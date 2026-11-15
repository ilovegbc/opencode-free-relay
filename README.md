# OpenCode Free Relay

纯 PHP 8.1+ / PostgreSQL 自托管 **OpenCode 免费模型** 中转站。

- 兼容 OpenAI Chat Completions / Anthropic Messages / OpenAI Responses
- 完整中转站制度：用户、API Key、套餐、每日额度、RPM、并发、积分、邀请码、兑换码、请求日志、审计日志、公告、后台管理
- **仅 IPv6 出口**：每次请求从自定义 CIDR 池分配全新地址，不回退 IPv4
- 每小时自动刷新 OpenCode 免费模型目录
- 无 Composer 依赖、无框架、无前端构建步骤
- MIT 开源，前后端均可自由修改

> ⚠️ 本项目用于自托管与技术研究。请自行遵守 OpenCode 服务条款、上游提供商政策与当地法律。项目不承诺绕过任何额度限制。

---

## 1. 从官方客户端挖到的协议

以下内容来自官方 OpenCode 2.3.0 客户端（桌面端 `opencode-cli.exe` / `app.asar` 内的 `SessionModelRequest.prepare`）：

```js
headers: {
  "x-opencode-session-id": session.id,
  ...(session.parentID ? {"x-opencode-parent-session-id": session.parentID} : {}),
  "x-session-affinity": affinity,
  "X-Session-Id": affinity,
  ...(session.parentID ? {"x-parent-session-id": session.parentID} : {}),
  "User-Agent": `opencode/${client.channel}/${client.version}/${client.name}`,
  "x-opencode-project": session.projectID,
  "x-opencode-session": affinity,
  "x-opencode-client": client.name,
}
```

其中：

```js
const affinity = session.parentID ?? session.fork?.sessionID ?? session.id;
const promptCacheKey = /^ses_[0-9a-f]{64}$/.test(affinity) ? affinity.slice(4) : affinity;
```

当前 CLI 构建的默认值：

```text
client.channel = latest
client.version = 2.3.0
client.name    = cli
User-Agent     = opencode/latest/2.3.0/cli
```

### 端点

| 用途 | URL |
|---|---|
| 模型目录 | `https://opencode.ai/inference/v1/models` |
| OpenAI Chat Completions | `https://opencode.ai/inference/openai/v1/chat/completions` |
| OpenAI Responses | `https://opencode.ai/inference/openai/v1/responses` |
| Anthropic Messages | `https://opencode.ai/inference/anthropic/v1/messages` |
| System One（本项目中继不透传） | `https://opencode.ai/inference/systemone/v1/systemone` |

### 免费模型需要 API Key 吗？

**不需要真实 Key。** 官方客户端在没有配置 Console Key 时，会把 provider 的 apiKey 注入为字面量：

```http
Authorization: Bearer public
```

`public` 不是密钥。实测不带任何 Authorization 也能到达网关（返回的是额度错误而不是 401）。本项目默认发送 `public` 以完全对齐官方客户端；后台也可以清空该值，让请求完全不发送 Authorization。

真正的 `oc_sk_...` Key 只与付费 Console 模型 / OpenCode Go 有关。

### 额外请求头是必须的吗？

不是“鉴权必须”，但本项目仍然完整发送，因为：

1. 官方客户端就是这么发的，保持一致可以避免不必要的行为差异；
2. `x-session-affinity` / `prompt_cache_key` 用于保持提示词缓存亲和度；
3. 网关可能用这些头做会话、项目与缓存路由。

中转**不会修改 system prompt、messages、tools、tool_choice**。

### 免费模型如何识别？

每小时读取 `/inference/v1/models`，满足以下条件即视为免费：

- `type == "chat"`；
- `cost.text.input.price == 0` 且 `cost.text.output.price == 0`，**或** 名称包含 `Free`。

因此像 `big-pickle` 这种零成本隐身模型也会被识别。只有带可用 `chat-completions` / `messages` / `responses` 端点的模型会被暴露。

### 实测额度行为

免费模型可能返回：

```json
{
  "type": "error",
  "error": {
    "type": "FreeUsageLimitError",
    "message": "Free usage limit reached. Free models work in OpenCode (https://opencode.ai); subscribe to OpenCode Go to keep going: https://opencode.ai/go"
  }
}
```

HTTP 状态为 `429`。带不带官方身份头都可能出现，说明这些头不是“额度绕过器”。额度由上游根据 IP、账号、模型和时间窗口决定。

本项目的 IPv6 轮换是需求本身：让每次请求走新的网络出口。它不保证额度，也不伪造账号身份。

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

### IPv6 轮换

- 任意 IPv6 CIDR 长度：`/1`–`/128`，多地址池，不硬编码 `/48`、`/56`、`/64`
- 加权选池、每池租约 TTL、唯一地址租约表
- 每次上游尝试分配新地址；429/5xx/绑定失败自动换新地址重试
- 强制 `CURL_IPRESOLVE_V6`，禁用重定向、禁用环境代理
- `CURLOPT_INTERFACE`（或可选的 opensocket）绑定源地址
- 显式返回 `503 ipv6_unavailable` / `503 ipv6_pool_exhausted`，绝不静默走 IPv4
- 源地址绑定探针：`bin/doctor.php --probe` 与后台诊断页

### 中转站制度

- 用户、角色、状态（active/pending/suspended）
- API Key：HMAC-SHA256 存储，完整 Key 仅展示一次
- 套餐：每日请求、每日 Token、RPM、并发、允许模型、积分折扣
- 积分、积分流水、兑换码、邀请码
- 每日用量聚合、请求日志、每次上游尝试的 IPv6 日志、审计日志
- 公告、公开状态页、API 文档
- 注册模式：开放 / 邀请 / 关闭，可配置管理员审核
- 默认免费套餐可用；运营者可自行收紧，不强制付费墙

### 与官方客户端一致

- 完整复刻官方 User-Agent 与 `x-opencode-*` 请求头
- 稳定会话亲和度：优先使用客户端传入值，否则由 API Key + 首条用户消息 + system + 模型派生
- 稳定 64 位 hex `prompt_cache_key`
- 不改提示词、不改消息、不改 tools；仅在配置允许时补 `stream_options.include_usage` 与 `prompt_cache_key`
- `bin/sync-client-version.php` 可从 npm/GitHub 刷新客户端版本

### 每小时刷新免费模型

- `bin/cron.php models` 由 cron / systemd timer 每小时执行
- PostgreSQL advisory lock 防止并发同步
- 上游返回 0 个可用免费模型时保留旧目录，避免误清空
- 新免费模型默认自动启用；管理员手动禁用状态不会被覆盖

---

## 3. 环境要求

- Linux 服务器，有路由到本机的 IPv6 段（或隧道 / NDP）
- PHP 8.1+，扩展：`pdo_pgsql`、`curl`、`openssl`、`mbstring`、`json`
  - `sockets`、`bcmath` 可选
- PostgreSQL 14+
- Nginx + PHP-FPM（推荐），或 PHP 内置服务器用于开发

服务器必须能绑定自定义 CIDR 内的任意地址：

```bash
sysctl -w net.ipv6.ip_nonlocal_bind=1
ip -6 route add local YOUR_PREFIX::/64 dev lo   # 示例，请换成运营商路由给你的段
```

运营商路由 `/64` 通常还需要 NDP 代理（`ndppd`）。隧道方案见 [`docs/DEPLOY.md`](docs/DEPLOY.md)。代码中**没有任何 IP 地址或前缀假设**。

---

## 4. 安装

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
```

建库、迁移、创建管理员：

```bash
createdb opencode_relay
php bin/migrate.php
php bin/create-admin.php admin admin@example.com 'change-me-please'
```

权限：

```bash
chown -R www-data:www-data storage
```

运行诊断：

```bash
php bin/doctor.php
php bin/doctor.php --probe
```

添加 IPv6 地址池（三种方式任选）：

- 后台 → **IPv6 池** → 添加 CIDR / 名称 / 权重 / TTL
- CLI 文件：`php bin/import-pools.php pools.txt`
- 单行管道：`echo '2001:db8:1234::/48,my-pool,100,604800,eth0' | php bin/import-pools.php`

同步模型：

```bash
php bin/cron.php models
```

打开 `https://relay.example.com`，注册（或使用管理员账号），创建 API Key，然后调用：

```bash
curl https://relay.example.com/v1/chat/completions \
  -H "Authorization: Bearer sk-oc-..." \
  -H "Content-Type: application/json" \
  -d '{"model":"big-pickle","messages":[{"role":"user","content":"hi"}],"stream":true}'
```

### Web 服务器

Nginx 示例：[`deploy/nginx.conf.example`](deploy/nginx.conf.example)。站点根目录必须是 `public/`。不要暴露 `.env`、`storage/`、`database/`。

本地开发：

```bash
php -S 127.0.0.1:8080 -t public public/router.php
```

---

## 5. 每小时刷新免费模型

Cron：

```cron
0 * * * * php /var/www/opencode-relay/bin/cron.php models >> /var/log/opencode-relay-cron.log 2>&1
30 3 * * * php /var/www/opencode-relay/bin/cron.php cleanup >> /var/log/opencode-relay-cron.log 2>&1
```

虚拟主机 / 外部定时服务：

```text
https://relay.example.com/cron.php?token=YOUR_CRON_SECRET&task=models
```

`models` 任务会读取 `models.sync_interval`（默认 3600 秒），并使用 PostgreSQL advisory lock 防止并发。后台“免费模型 → 立即同步”可以强制刷新。

---

## 6. 一次请求的完整流程

1. 校验 `sk-oc-...` Key（HMAC 比较），加载用户与套餐。
2. 校验模型是免费、已启用、可用，且套餐允许。
3. 在 `usage_daily` 中原子预占额度；检查每日请求/Token、RPM、并发、余额。
4. 解析会话身份：
   - 优先 `x-session-affinity` / `X-Session-Id` / `x-opencode-session` / body `prompt_cache_key`；
   - 否则由 API Key + 首条用户消息 + system + 模型派生。
5. 从 IPv6 池分配全新地址，写入唯一租约。
6. 调用 OpenCode：
   - 强制 `CURLOPT_IPRESOLVE = CURL_IPRESOLVE_V6`
   - `CURLOPT_INTERFACE` 或 opensocket 绑定源地址
   - 禁止重定向、禁止代理，默认校验 TLS
   - 复刻官方请求头；缺失时补 `prompt_cache_key`
7. 429/5xx/绑定失败时，换新地址重试（次数可配）。
8. 流式请求即时转发 SSE；同时解析 usage 事件用于计费。
9. 提交额度、写请求日志与每次尝试的 IPv6 日志。
10. IPv6 不可用则明确报错。**永远不走 IPv4。**

### 为什么强调“官方一致性”

OpenCode 官方客户端会对支持 `streamUsage` 的 chat 模型自动发送 `stream_options.include_usage`，并用稳定的会话亲和度生成 `prompt_cache_key`。本项目默认做同样的事，完整保留 `system` / `messages` / `tools` 请求体，并使用相同的 User-Agent 与会话头。它不修改提示词，也不声称能绕过 OpenCode 的额度；额度仍由上游决定。

---

## 7. 目录结构

```text
app/
  Auth/               会话登录 + API Key 鉴权
  Controllers/        API / Web / Admin 控制器
  Http/               Request/Response/Router/异常
  Repositories/       PostgreSQL 数据访问
  Services/           IPv6 分配器、OpenCode 客户端、额度、计费、
                      模型同步、身份解析、中继编排
  Support/            Env/Config/DB/Settings/Session/Crypto/IPv6 工具
  Views/              PHP 模板（明暗主题设计）
bin/                  migrate、create-admin、cron、doctor、
                      import-pools、sync-client-version
config/config.php     环境变量默认值
database/migrations/  表结构与安全种子数据
deploy/               nginx/systemd/cron/sysctl 示例
public/               入口、静态资源、router、cron 端点
tests/                零依赖单元测试
docs/                 调研、部署、API、扩展文档
```

---

## 8. 配置

环境变量见 [`.env.example`](.env.example)。运行时可修改的值保存在 `settings` 表，由后台 → 设置管理。优先级：**数据库设置 > 环境变量默认值 > 内置默认值**。

| 设置 | 默认值 | 说明 |
|---|---:|---|
| `upstream.base_url` | `https://opencode.ai/inference` | OpenCode 推理根地址 |
| `upstream.models_url` | `https://opencode.ai/inference/v1/models` | 每小时模型目录来源 |
| `upstream.key` | `public` | 官方占位值；留空 = 不发送 Authorization |
| `upstream.client_version` | `2.3.0` | User-Agent 中的版本 |
| `upstream.max_ip_retries` | `2` | 额外换 IP 重试次数 |
| `ipv6.default_lease_ttl` | `604800` | 地址不复用窗口（秒） |
| `ipv6.bind_method` | `interface` | `interface` 或 `opensocket` |
| `site.registration_mode` | `open` | `open` / `invite` / `closed` |
| `site.default_plan` | `free` | 注册默认套餐 |
| `models.sync_interval` | `3600` | 每小时刷新 |
| `billing.*` | `0` | 默认全免费；可选用积分计费 |

`APP_KEY` 必须是 64 位随机字符串。它用于 API Key HMAC 与不透明代码哈希；更换后旧 Key/兑换码会失效。

---

## 9. 安全说明

- API Key 仅保存 HMAC-SHA256；前缀用于展示，完整 Key 只显示一次。
- 密码优先 Argon2id，不可用时回退 bcrypt。
- 所有 Web POST 都有 CSRF；Cookie 为 SameSite=Lax + HttpOnly。
- `TRUST_PROXY` 只应在可信反向代理后开启。
- 上游主机白名单防 SSRF；URL 来自模型目录与配置。
- 提示词/内容日志默认关闭。请求日志只记录元数据、Token 数和出口 IPv6。
- 限流与额度在 PostgreSQL 中实现，多 PHP-FPM worker 共享。
- 错误响应不暴露 Key、数据库 DSN 或上游密钥。
- 本项目面向自托管。运营者需自行遵守 OpenCode 条款、上游 AUP 与当地法律。

---

## 10. 测试

```bash
php tests/run.php
```

覆盖 IPv6 CIDR 运算（含 `/32`、`/48`、`/56`、`/64`、`/96`、`/128`）、免费模型筛选、身份/缓存键派生、SSE usage 解析、路由编译。

本地还可以跑上游集成测试（不依赖数据库）：

```bash
# 终端 1：启动只监听 IPv6 回环的 mock 上游
php -S [::1]:18080 tests/mock_upstream.php
# 终端 2：验证源地址绑定、官方请求头、prompt_cache_key 与流式转发
php tests/upstream_integration.php
```

数据库集成测试不随包提供；请在真实 PostgreSQL 上运行 `php bin/doctor.php`。

---

## 11. 许可证

MIT，见 [`LICENSE`](LICENSE)。欢迎 Fork、商业部署和二次开发，只需保留版权与许可声明。

## 12. 致谢

- OpenCode / OpenCode Zen：上游免费模型目录与客户端协议
- [phanes-io/nyxproxy-oss](https://github.com/phanes-io/nyxproxy-oss)、[uk0/go-proxy-ipv6-pool](https://github.com/uk0/go-proxy-ipv6-pool)：IPv6 轮换与地址池思路
