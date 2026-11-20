# OpenCode 免费 API 调研记录

来源：官方 `opencode` CLI 1.19.6（Linux x64 官方二进制）在本机的实测抓包，以及
2026-11-18 对本机出口的逐项对照实验。

> 本文是开发期的调研记录，保留原始结论以便复现。仓库附带的
> `resources/opencode/` 提示词与工具 schema 来自官方 1.19.6 构建，许可见
> `resources/opencode/NOTICE.md`。

## 端点

| 用途 | URL |
|---|---|
| 模型目录 | `https://opencode.ai/inference/v1/models` |
| OpenAI Chat Completions | `https://opencode.ai/inference/openai/v1/chat/completions` |
| OpenAI Responses | `https://opencode.ai/inference/openai/v1/responses` |
| Anthropic Messages | `https://opencode.ai/inference/anthropic/v1/messages` |

## 鉴权

免费模型不需要真实 Key，官方客户端注入字面量 `public`：
`Authorization: Bearer public`。真正的 `oc_sk_...` 只与付费 Console / OpenCode Go 有关。

## 官方 1.19.6 实际发送的请求头

只有这 5 个 `x-opencode-*` 加 UA（没有 `x-session-affinity`、`X-Session-Id`）：

```http
authorization: Bearer public
user-agent: opencode/1.19.6 ai-sdk/provider-utils/4.0.23 runtime/bun/1.4.4
x-opencode-client: cli
x-opencode-project: global
x-opencode-request: msg_...
x-opencode-session: ses_e10f9c71fffe00000000000000
x-opencode-session-id: <与 x-opencode-session 同值>
```

## session id 必须是 canonical 布局

`ses_` + 12 位小写 hex + 14 位 base62，共 30 字符；12 位 hex 是
`~(unix_ms << 12 | counter)` 的低 48 位，因此恒以 `ffe` 结尾，解码时间需在 ±30 天内。
不符合该布局（例如老的 `ses_` + 64 hex、UUID、sha256）会被拒绝。

## 免费通道准入（2026-11-18 实测，与 2026-10 的文档不同）

在同一出口、同一时刻逐项对照后确认，免费通道现在**不是**按请求头判定的，而是：

1. `system` 消息必须以官方 agent 提示词**开头**（精确前缀；改一个字即拒绝，追加不受限）；
2. `tools` 必须包含官方工具（至少 `read`，官方 schema）——完全不带 `tools` 会被拒绝；
3. `stream` 必须为 `true`；
4. 满足上述形状后才按出口额度计量。

任一条件不满足都会返回 `429 FreeUsageLimitError`（**不是** 403 `FreeTierError`），
所以“从未使用也 429”通常是形状不匹配，而不是额度耗尽。

## 传输层准入

同一份合法请求体：

| 客户端 | 结果 |
|---|---|
| 官方 CLI（Bun 1.4.4，HTTP/2 + BoringSSL） | 200 |
| curl 7.29（NSS）/ 站点 libcurl | 429 |
| curl 7.70（OpenSSL 1.0.2u） | 429 |
| python httpx / curl_cffi（Chrome 指纹 + h2） | 429 |

说明上游对客户端传输栈也有准入。站点 PHP 无法直连上游，必须由
`bin/opencode-bridge.ts`（本机 Bun 服务，systemd `opencode-relay-bridge`）代发。

## 本项目如何适配

- `PromptShaper`：把调用方的 system 指令追加到官方 agent 提示词之后
  （`resources/opencode/build-system.txt`），并确保 `tools` 里存在官方 `read`
  （`resources/opencode/tools.json`）；调用方工具原样保留，但 `function.parameters`
  会被规范化——不少客户端发 `parameters: []` 或缺省，beam/flint/iceman 这类模型
  会直接 `400 Tool 0 function has invalid 'parameters' schema`。
  这两个资源文件随仓库提供（官方 1.19.6 构建，MIT）；升级客户端后需要替换。
- 官方前缀逐字保留（改一个字就会被拒），但它自带 “你是 opencode”、工作目录、
  工具清单等身份信息，所以前缀之后必须紧跟一段显式作废声明，再把调用方 system
  作为唯一有效指令；否则模型会自认 opencode（甚至复述抓包时的 `/opt/...` 目录）。
  调用方没有 system 时同样会补一段中性的 “helpful assistant”。
- 已下架模型（上游 `410`，如 `glm-5.3-flash-free`、`minimax-m3.1-flash-free`）
  返回 `404 model_deprecated`，并自动把该模型设为不可用，从 `/v1/models` 摘掉。
- `OpenCodeClient`：chat 一律 `stream: true`；非流式调用由 `SseAggregator`
  把 SSE 重组为 `chat.completion`；配置 `upstream.bridge_url` 后经 Bun 桥接，
  留空则走直连 IPv6 模式。
- `IdentityResolver`：生成/规范化 30 字符 canonical session；project 默认 `global`。
- IPv6 轮换仍然保留（直连模式和 `upstream_attempts` 记录用），但它已不是 429 的成因。

## 实测额度行为

真正耗尽额度的表现仍是 `429 FreeUsageLimitError`，`Retry-After` 指向 00:00 UTC。
现在开启请求塑形与桥接后，正常调用返回 200 并带 `usage`（`cost: 0`）。

## 版本刷新

`php bin/sync-client-version.php` 可刷新 `upstream.client_version`；
UA 固定走 `upstream.user_agent`，升级官方客户端后需要同步更新该值。

## 部署注意

- 站点 vhost 里的 `error_page 404 /404.html;` 必须注释掉：它会接手应用返回的 404，
  把 JSON 错误变成 HTML（客户端报 `Unexpected JSON token ... <!DOCTYPE html>`）。
  面板改 PHP 版本或伪静态时可能重写 vhost，改完要复查这一行。
- `opencode-relay-bridge`（Bun，127.0.0.1:18130）在桥接模式下必须常开；它负责
  传输层准入。桥接只监听回环，务必设置 `BRIDGE_TOKEN`。
- 桥接以服务用户运行即可（示例为 `www-data`）；把 Bun 复制到 `/usr/local/bin/bun`，
  避免 `ProtectHome=true` 阻止读取 `/root/.bun/bin/bun`。

## 工具注入的边界（2026-11 修订）

网关准入要求 tools 里必须有名为 `read` 的函数，但客户端未必有可执行的文件读取工具，
所以不能无条件把官方 `read` 塞给模型：

- 客户端没有 `read`：注入官方 `read` 仅用于通过准入。system 里明确它是
  “协议占位、禁止调用”，并声明模型无法访问文件系统/命令/主机信息。
  若客户端一个工具都没带，同时把 `tool_choice` 置为 `none`，模型不会产出
  tool_calls（实测上游接受该形状，仍返回 200）。
- 客户端自带 `read`：一律保留客户端定义，不注入、不改名、不覆盖；同名但语义
  不是读文件的工具（如 read_email）会额外提示“以客户端 schema 为准，不要当路径用”。
- 通用规则：只允许使用客户端真实提供且结果可观察的工具；工具调用失败（如
  `Tool read not found`）必须如实告知用户，禁止编造结果或声称具备环境能力。
- 该策略不能替客户端执行工具；客户端声明了工具却无法执行，属于客户端侧问题，
  中转只能避免诱导模型做无依据的能力承诺。

## 思考等级（2026-11-19 实测）

上游 `/inference/v1/models` 的每个模型都带 `capabilities.reasoning`：
`supported`、`toggle`、`effort` 档位数组、可选的 `default` 与 `budget`。
免费模型全部走 `chat-completions`，该端点只识别顶层 `reasoning_effort`：

| 请求体写法 | 结果 |
|---|---|
| `reasoning_effort: "high"` | 思考，流里出现 `reasoning_content` / `reasoning_tokens` |
| `reasoning_effort: "none"` | 不思考（模型带 `toggle: true` 时有效） |
| `reasoning: {effort}`、`thinking: {...}` | 被忽略，不会思考 |
| 不在 `effort` 列表里的值（如 deepseek 发 `medium`） | 返回 200 但静默不思考 |

因此中转在转发前把各种客户端写法统一成 `reasoning_effort`，并把未声明档位收敛到
阶梯上最近的合法档位（`none < minimal < low < medium < high < xhigh < max`，等距取更高档）。
同步时把归一化结果写进 `model_catalog.thinking`，并通过 `/v1/models` 的 `thinking` 暴露；
也可以直接把档位写进模型 ID（`id:high` / `id#high`）。
默认策略（2026-11-19 追加）：客户端完全没有传档位时，中转按模型 `efforts` 的最高档
（阶梯最高者，不会把 `none` 当最高）写入 `reasoning_effort`；显式 `auto` / `default`
会被从请求体里摘掉，按上游默认处理。没有 `effort` 列表的模型（如 big-pickle）不注入档位。
