# OpenCode Free Relay

纯 PHP 8.1+ / PostgreSQL 自托管 **OpenCode 免费模型** 中转站。

- 兼容 OpenAI Chat Completions / Anthropic Messages / OpenAI Responses
- 用户、API Key、套餐、每日额度、RPM、并发、邀请码、邮箱验证、请求日志、审计日志、公告、后台管理
- 自定义 IPv6 CIDR 池；直连模式下每次上游尝试分配全新地址，绝不回退 IPv4
- 可选的 Bun 桥接：由 Bun 的 TLS/HTTP2 栈代发上游请求，通过传输层准入
- 免费通道请求塑形：官方提示词前缀 + `read` 工具 + 强制流式
- 思考档位归一化（`reasoning_effort`），并在 `/v1/models` 暴露
- 每小时自动刷新免费模型目录
- 无 Composer 依赖、无框架、无前端构建步骤
- MIT 开源，前后端均可自由修改

> ⚠️ 本项目用于自托管与技术研究。请自行遵守 OpenCode 服务条款、上游提供商政策与当地法律。项目不承诺绕过任何额度限制，也不代表上游官方或获得其认可。

---

## 1. 上游现状（2026-11）

### 1.1 免费模型不需要真实 Key

官方客户端在没有配置 Console Key 时会把 provider 的 apiKey 注入为字面量：

```http
Authorization: Bearer public
```

`public` 不是密钥。真正的 `oc_sk_...` 只与付费 Console / OpenCode Go 有关。本项目默认发送 `public` 以对齐官方客户端；清空 `upstream.key` 可完全不发送 Authorization。

### 1.2 免费通道有请求形状准入

仅靠请求头已经不够。2026-11-18 实测确认，免费通道还会检查：

| 条件 | 说明 |
|---|---|
| `system` 前缀 | 必须以官方 agent 提示词**逐字开头**，改一个字即拒绝；追加内容不受限 |
| `tools` | 必须包含官方 `read` 工具（官方 schema）；完全不带工具会被拒绝 |
| `stream` | 必须为 `true` |
| 传输栈 | 站点 PHP/libcurl 常被拒绝，需要 Bun + BoringSSL + HTTP/2 |

任一形状条件不满足都会返回 `429 FreeUsageLimitError`，**不是** 403，所以“从未使用也 429”通常不是额度耗尽。

### 1.3 本项目如何适配

- `PromptShaper` 把调用方的 system 指令追加到官方前缀之后，并注入官方 `read` 与 `tool_choice`。调用方工具原样保留，`function.parameters` 会被规范化为合法 object schema（很多客户端发 `parameters: []`，会让部分模型直接 400）。
- 官方前缀自带 “你是 opencode”、工作目录、工具清单等身份信息，所以前缀之后会紧跟一段显式作废声明，再把调用方 system 作为唯一有效指令。
- 客户端没有工具时，注入的 `read` 仅用于通过准入：system 明确禁止调用它，`tool_choice` 置为 `none`。
- `IdentityResolver` 生成/规范化 30 字符 canonical session id（`ses_` + 12 hex + 14 base62），project 默认 `global`。
- chat 请求一律 `stream: true`；调用方要求非流式时，`SseAggregator` 把 SSE 重组成标准 `chat.completion`。
- 配置 `upstream.bridge_url` 后，PHP 通过本机 `bin/opencode-bridge.ts` 代发；未配置时走直连 IPv6 模式。

> **重要：** 请求塑形会修改 `system`、`tools` 与 `tool_choice`，并强制 `stream`。这与 v1.0 的“原样透传”承诺不同。如果你不需要塑形（例如使用其他上游），设置 `OPENCODE_SHAPE_REQUESTS=false`。

### 1.4 两种出口模式

| 模式 | 上游出口 | 适用场景 |
|---|---|---|
| 直连（默认） | 每个请求从 IPv6 池绑定一个租约地址 | 上游接受站点 libcurl；需要完整 IPv6 轮换 |
| 桥接（推荐） | 桥接主机自己的默认出口 | 站点 libcurl 过不了传输层准入 |

桥接模式下，PHP 仍会从池中分配租约用于记录与健康检查，但**不会**把该地址交给 Bun；上游看到的出口由桥接主机决定。需要多出口时，可在不同 IPv6 出口的机器上各跑一个桥接，再按需切换 `upstream.bridge_url`。

### 1.5 随仓库提供的上游准入资源

`resources/opencode/` 内含从官方 OpenCode 1.19.6 构建导出的 `build-system.txt` 与 `tools.json`（MIT 许可，来源与版权见 [`resources/opencode/NOTICE.md`](resources/opencode/NOTICE.md)）。开箱即可启用塑形。

升级官方客户端后前缀可能变化，需要替换这两个文件，或用 `OPENCODE_SHAPE_DIR` 指向 Web 根目录之外的新版本文件。缺少文件时塑形会静默跳过，请求多半仍会被上游以 429 拒绝。详见 [`resources/opencode/README.md`](resources/opencode/README.md)。

---

## 2. 功能

### API

- `POST /v1/chat/completions`：OpenAI 兼容，支持流式/非流式
- `POST /v1/messages`：Anthropic Messages 透传（模型需声明 messages）
- `POST /v1/responses`：OpenAI Responses 透传（模型需声明 responses）
- `GET /v1/models`、`GET /v1/models/{id}`、`GET /v1/me`、`GET /health`
- SSE 即时转发、用量解析、客户端断开处理
- OpenAI 兼容错误结构
- 可配置 CORS，转发常见 OpenAI/Anthropic SDK 头

### IPv6 轮换（直连模式）

- 任意 IPv6 CIDR 长度：`/1`–`/128`，多地址池，不硬编码 `/48`、`/56`、`/64`
- 加权选池、每池租约 TTL、唯一地址租约表
- 每次上游尝试分配新地址；429/5xx/绑定失败自动换新地址重试
- 强制 `CURL_IPRESOLVE_V6`，禁用重定向、禁用环境代理
- `CURLOPT_INTERFACE`（或可选的 opensocket）绑定源地址
- 显式返回 `503 ipv6_unavailable` / `503 ipv6_pool_exhausted`，绝不静默走 IPv4
- 源地址绑定探针：`bin/doctor.php --probe` 与后台诊断页

### 账户与中转站制度

- 用户、角色、状态（active/pending/suspended）
- API Key：HMAC-SHA256 存储，完整 Key 仅展示一次
- 套餐：每日请求、每日 Token、RPM、并发、允许模型
- 用户可创建邀请码并查看使用情况；管理员也可创建邀请码
- 邮箱验证：配置 SMTP 后，新注册账号需点击邮件链接激活（`Mailer`，支持 465/587/25）
- 每日用量聚合、请求日志、每次上游尝试的 IPv6 日志、审计日志
- 公告、API 文档、后台设置、用户管理、模型管理、地址池诊断
- 注册模式：开放 / 邀请 / 关闭，可配置管理员审核
- 默认免费套餐可用；运营者可自行收紧，不强制付费墙

> 数据库仍保留 `credit_*` / `redemption_*` 表与套餐折扣字段，用于兼容旧数据；v1.1 已移除积分、兑换码与状态页的前端与路由。

### 思考档位

- 模型同步把上游 `capabilities.reasoning` 归一化为 `model_catalog.thinking`
- `/v1/models` 暴露 `supported` / `toggle` / `efforts` / `levels` / `default` / `budget`
- 接受 `reasoning_effort`、`reasoning.effort`、`thinking.effort`、`thinking.budget_tokens` 与模型 ID 后缀 `id:level` / `id#level`
- 未声明的档位按阶梯收敛到最近的合法档位，避免上游静默不思考
- 客户端完全不指定档位时，默认按该模型的最高档发送（`auto` / `default` 保持上游默认）
- 详见 [`docs/API.md`](docs/API.md)

### 每小时刷新免费模型

- `bin/cron.php models` 由 cron / systemd timer 每小时执行
- PostgreSQL advisory lock 防止并发同步
- 上游返回 0 个可用免费模型时保留旧目录，避免误清空
- 新免费模型默认自动启用；管理员手动禁用状态不会被覆盖
- 上游下架（410）的模型自动标记不可用并从列表摘除

---

## 3. 环境要求

- Linux 服务器，有路由到本机的 IPv6 段（或隧道 / NDP）
- PHP 8.1+，扩展：`pdo_pgsql`、`curl`、`openssl`、`mbstring`、`json`
  - `sockets`、`bcmath` 可选
- PostgreSQL 14+
- Nginx + PHP-FPM（推荐），或 PHP 内置服务器用于开发
- 桥接模式需要 [Bun](https://bun.sh/) 1.1+
- 请求塑形所需文件已随仓库提供（MIT，见 `resources/opencode/NOTICE.md`）

服务器必须能绑定自定义 CIDR 内的任意地址：

```bash
sysctl -w net.ipv6.ip_nonlocal_bind=1
ip -6 route add local YOUR_PREFIX::/64 dev lo   # 示例，请换成运营商路由给你的段
```

运营商路由 `/64` 通常还需要 NDP 代理（`ndppd`）。隧道方案见 [`docs/DEPLOY.md`](docs/DEPLOY.md)。代码中**没有任何 IP 地址或前缀假设**。

---

## 4. 安装

### 4.1 站点

```bash
git clone <your-fork> opencode-relay
cd opencode-relay
cp .env.example .env
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"   # 填入 APP_KEY
```

编辑 `.env`：

```dotenv
APP_URL=https://relay.example.com
APP_KEY=<64 位随机字符串>
DB_HOST=127.0.0.1
DB_NAME=opencode_relay
DB_USER=relay
DB_PASSWORD=...
CRON_SECRET=<随机字符串>
OPENCODE_UPSTREAM_KEY=public
OPENCODE_USER_AGENT="opencode/1.19.6 ai-sdk/provider-utils/4.0.23 runtime/bun/1.4.4"
```

建库、迁移、创建管理员：

```bash
createdb opencode_relay
php bin/migrate.php
php bin/create-admin.php admin admin@example.com 'change-me-please'
chown -R www-data:www-data storage
```

### 4.2 上游准入资源

仓库已附带 `resources/opencode/build-system.txt` 与 `tools.json`，默认配置即可使用。若你更新了官方客户端，或想放在 Web 根目录之外：

```bash
sudo install -d -o www-data -g www-data /var/lib/opencode-relay/shape
# 复制两个文件到该目录
```

```dotenv
OPENCODE_SHAPE_REQUESTS=true
OPENCODE_SHAPE_DIR=/var/lib/opencode-relay/shape
```

### 4.3 Bun 桥接（推荐）

```bash
sudo apt install -y unzip
curl -fsSL https://bun.sh/install | bash
sudo install -m 0755 /root/.bun/bin/bun /usr/local/bin/bun
sudo install -d -o www-data -g www-data /opt/opencode-relay-bridge
sudo install -m 0644 bin/opencode-bridge.ts /opt/opencode-relay-bridge/
```

`/etc/systemd/system/opencode-relay-bridge.service`（也可直接用 [`deploy/opencode-relay-bridge.service`](deploy/opencode-relay-bridge.service)）：

```ini
[Unit]
Description=OpenCode Relay upstream bridge
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/opt/opencode-relay-bridge
Environment=HOME=/var/www
Environment=BRIDGE_HOST=127.0.0.1
Environment=BRIDGE_PORT=18130
Environment=BRIDGE_TOKEN=replace-with-a-random-token
Environment=BRIDGE_ALLOWED_HOSTS=opencode.ai
ExecStart=/usr/local/bin/bun /opt/opencode-relay-bridge/opencode-bridge.ts
Restart=always
RestartSec=3
NoNewPrivileges=true
ProtectSystem=strict
ProtectHome=true
PrivateTmp=true

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now opencode-relay-bridge
curl -s http://127.0.0.1:18130/health
```

在 `.env` 中启用：

```dotenv
OPENCODE_BRIDGE_URL=http://127.0.0.1:18130
OPENCODE_BRIDGE_TOKEN=replace-with-a-random-token
```

`BRIDGE_TOKEN` 与 `OPENCODE_BRIDGE_TOKEN` 必须一致；`BRIDGE_ALLOWED_HOSTS` 是桥接侧的第二道 SSRF 白名单。

### 4.4 邮箱验证（可选）

后台 → **系统设置 → 邮件**：

- 开启“启用邮件”，填写 SMTP 主机、端口、加密方式（`ssl` 465 / `tls` 587 / `none` 25）
- 用户名、密码、发件地址、发件人名称
- 验证链接有效期（分钟）

配置完成后，新注册账号会进入 `pending`，点击邮件链接后自动激活。SMTP 发送失败会回滚注册并提示错误。

### 4.5 IPv6 池、模型与首次调用

```bash
php bin/doctor.php
php bin/doctor.php --probe
```

添加地址池（三种方式任选）：

- 后台 → **IPv6 池** → 添加 CIDR / 名称 / 权重 / TTL
- CLI 文件：`php bin/import-pools.php pools.txt`
- 单行管道：`echo '2001:db8:1234::/48,my-pool,100,604800,eth0' | php bin/import-pools.php`

同步模型：

```bash
php bin/cron.php models
```

打开站点注册（或使用管理员账号），创建 API Key，然后：

```bash
curl https://relay.example.com/v1/chat/completions \
  -H "Authorization: Bearer sk-oc-..." \
  -H "Content-Type: application/json" \
  -d '{"model":"big-pickle","messages":[{"role":"user","content":"hi"}],"stream":true}'
```

### 4.6 Web 服务器

Nginx 示例：[`deploy/nginx.conf.example`](deploy/nginx.conf.example)。站点根目录必须是 `public/`。不要暴露 `.env`、`storage/`、`database/`、`resources/`。

> vhost 中的 `error_page 404 /404.html;` 必须注释掉：它会接手应用返回的 404，把 JSON 错误变成 HTML（客户端报 `Unexpected JSON token ... <!DOCTYPE html>`）。面板改 PHP 版本或伪静态时可能重写 vhost，改完要复查。

本地开发：

```bash
php -S 127.0.0.1:8080 -t public public/router.php
```

---

## 5. 配置

环境变量见 [`.env.example`](.env.example)。运行时可修改的值保存在 `settings` 表，由后台 → 设置管理。优先级：**数据库设置 > 环境变量默认值 > 内置默认值**。

| 设置 | 默认值 | 说明 |
|---|---:|---|
| `upstream.base_url` | `https://opencode.ai/inference` | OpenCode 推理根地址 |
| `upstream.models_url` | `https://opencode.ai/inference/v1/models` | 每小时模型目录来源 |
| `upstream.key` | `public` | 官方占位值；留空 = 不发送 Authorization |
| `upstream.client_version` | `1.19.6` | 模型同步等处的版本标记 |
| `upstream.user_agent` | 官方 1.19.6 UA | 实际发送的 UA；建议固定 |
| `upstream.max_ip_retries` | `2` | 额外换 IP 重试次数（直连模式） |
| `upstream.shape_requests` | `true` | 免费通道请求塑形开关 |
| `upstream.shape_resources_dir` | 空 | 塑形资源目录；空 = `resources/opencode` |
| `upstream.bridge_url` | 空 | Bun 桥接地址；空 = 直连 |
| `upstream.bridge_token` | 空 | 桥接鉴权 token |
| `ipv6.default_lease_ttl` | `604800` | 地址不复用窗口（秒） |
| `ipv6.bind_method` | `interface` | `interface` 或 `opensocket` |
| `site.registration_mode` | `open` | `open` / `invite` / `closed` |
| `site.default_plan` | `free` | 注册默认套餐 |
| `models.sync_interval` | `3600` | 每小时刷新 |
| `mail.*` | 关闭 | 仅后台可配；SMTP 与验证有效期 |

`APP_KEY` 必须是 64 位随机字符串。它用于 API Key HMAC 与不透明代码哈希；更换后旧 Key、邀请码会失效。

---

## 6. 一次请求的完整流程

1. 校验 `sk-oc-...` Key（HMAC 比较），加载用户与套餐。
2. 校验模型是免费、已启用、可用，且套餐允许。
3. 在 `usage_daily` 中原子预占额度；检查每日请求/Token、RPM、并发。
4. 解析会话身份：优先客户端传入的 `x-opencode-session*` / `x-session-affinity` / `prompt_cache_key`，否则由 API Key + 首条用户消息 + 模型派生 canonical session。
5. 从 IPv6 池分配租约（桥接模式也保留，用于记录与健康检查）。
6. 塑形请求体：官方前缀 + 作废声明 + 调用方 system；补齐 `read`；规范化工具 schema；强制 `stream: true`；归一化思考档位。
7. 发送上游：
   - 直连：强制 IPv6、绑定租约地址、禁止重定向与代理、校验 TLS
   - 桥接：POST 到 `bridge_url/forward`，由 Bun 代发
8. 429/5xx/绑定失败时重试（直连模式会换新地址）。
9. 流式请求即时转发 SSE；非流式 chat 由 `SseAggregator` 重组后返回 JSON。
10. 提交额度、写请求日志与每次尝试的 IPv6 日志。
11. IPv6 不可用则明确报错。**直连模式永远不走 IPv4。**

---

## 7. 目录结构

```text
app/
  Auth/               会话登录 + API Key 鉴权
  Controllers/        API / Web / Admin 控制器
  Http/               Request/Response/Router/异常
  Repositories/       PostgreSQL 数据访问
  Services/           IPv6 分配器、OpenCode 客户端、额度、
                      模型同步、身份解析、请求塑形、SSE 聚合、
                      SMTP 邮件、中继编排
  Support/            Env/Config/DB/Settings/Session/Crypto/IPv6 工具
  Views/              PHP 模板（明暗主题设计）
bin/                  migrate、create-admin、cron、doctor、
                      import-pools、sync-client-version、
                      opencode-bridge.ts（Bun 上游桥接）
config/config.php     环境变量默认值
database/migrations/  表结构与安全种子数据
deploy/               nginx/systemd/cron/sysctl 示例
public/               入口、静态资源、router、cron 端点
resources/opencode/   塑形资源（官方前缀与工具 schema，MIT）
tests/                零依赖单元测试
docs/                 调研、部署、API、扩展文档
```

---

## 8. 运维与升级

Cron：

```cron
0 * * * * php /var/www/opencode-relay/bin/cron.php models >> /var/log/opencode-relay-cron.log 2>&1
30 3 * * * php /var/www/opencode-relay/bin/cron.php cleanup >> /var/log/opencode-relay-cron.log 2>&1
```

或使用虚拟主机定时服务：

```text
https://relay.example.com/cron.php?token=YOUR_CRON_SECRET&task=models
```

升级：

```bash
cd /var/www/opencode-relay
git pull
php bin/migrate.php
php bin/cron.php models
sudo systemctl restart opencode-relay-bridge   # 如果使用桥接
```

迁移是幂等的；升级前先备份数据库与 `.env`。

---

## 9. 安全说明

- API Key 仅保存 HMAC-SHA256；前缀用于展示，完整 Key 只显示一次。
- 密码优先 Argon2id，不可用时回退 bcrypt。
- 所有 Web POST 都有 CSRF；Cookie 为 SameSite=Lax + HttpOnly。
- `TRUST_PROXY` 只应在可信反向代理后开启。
- 上游主机白名单防 SSRF；桥接侧还有独立的 `BRIDGE_ALLOWED_HOSTS`。
- 提示词/内容日志默认关闭。请求日志只记录元数据、Token 数和出口 IPv6。
- 限流与额度在 PostgreSQL 中实现，多 PHP-FPM worker 共享。
- 错误响应不暴露 Key、数据库 DSN 或上游密钥。
- 桥接默认只监听 `127.0.0.1`，务必设置 `BRIDGE_TOKEN`。
- 本项目面向自托管。运营者需自行遵守 OpenCode 条款、上游 AUP 与当地法律。

---

## 10. 测试

```bash
php tests/run.php
```

覆盖 IPv6 CIDR 运算（含 `/32`、`/48`、`/56`、`/64`、`/96`、`/128`）、免费模型筛选、canonical session/缓存键派生、SSE usage 解析、思考档位归一化、路由编译。当前为 490 个断言。

不依赖数据库的上游集成测试：

```bash
# 终端 1：启动只监听 IPv6 回环的 mock 上游
php -S [::1]:18080 tests/mock_upstream.php
# 终端 2：验证源地址绑定、官方请求头、流式转发
php tests/upstream_integration.php
```

桥接与真实上游的准入只能在目标服务器验证；数据库集成测试不随包提供，请运行 `php bin/doctor.php`。

---

## 11. 许可证

MIT，见 [`LICENSE`](LICENSE)。欢迎 Fork、商业部署和二次开发，只需保留版权与许可声明。

## 12. 致谢

- OpenCode / OpenCode Zen：上游免费模型目录与客户端协议
- OpenCode 官方提示词前缀与工具 schema（MIT）：见 [`resources/opencode/NOTICE.md`](resources/opencode/NOTICE.md)
- [phanes-io/nyxproxy-oss](https://github.com/phanes-io/nyxproxy-oss)、[uk0/go-proxy-ipv6-pool](https://github.com/uk0/go-proxy-ipv6-pool)：IPv6 轮换与地址池思路
