# OpenCode 免费 API 调研记录

本文记录本项目实现的协议细节，来源为官方 OpenCode 2.3.0 客户端包（桌面端 `opencode-cli.exe` / `app.asar`）以及公开推理端点。

## 端点

| 用途 | URL |
|---|---|
| 模型目录 | `https://opencode.ai/inference/v1/models` |
| OpenAI Chat Completions | `https://opencode.ai/inference/openai/v1/chat/completions` |
| OpenAI Responses | `https://opencode.ai/inference/openai/v1/responses` |
| Anthropic Messages | `https://opencode.ai/inference/anthropic/v1/messages` |
| System One（本项目不透传） | `https://opencode.ai/inference/systemone/v1/systemone` |

每个模型在 `/v1/models` 里都有 `api` 字段，标出原生 base URL 与 AI SDK 包。当前免费 chat 模型都声明 `chat-completions`。

## 鉴权

免费模型**不需要真实 API Key**。官方客户端在未配置 Console Key 时会注入字面量 `public`：

```js
// OpenCode 包内的 provider transform
if (!hasConsoleKey) {
  provider.settings.apiKey = "public";
}
```

实际请求会带上：

```http
Authorization: Bearer public
```

这个值不是密钥。即使完全不带 Authorization，也能到达网关（返回额度错误而不是 401）。本项目默认发送 `public` 以保持与官方客户端一致，也允许后台清空该值从而不发送 Authorization。

真正的 `oc_sk_...` Key 只与付费 Console 模型 / OpenCode Go 有关。

## 官方客户端发送的请求头

来自 `SessionModelRequest.prepare`：

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

观察到的 CLI 构建默认值：

- `client.channel = "latest"`
- `client.version = "2.3.0"`
- `client.name = "cli"`
- `User-Agent = "opencode/latest/2.3.0/cli"`

桌面端使用同一套代码，只是 client name / channel 不同。两者都可以在中转后台配置。

官方 AI SDK 还会对允许流式 usage 的 chat 模型发送 `stream_options: {include_usage: true}`。本项目默认做同样的事，以保持用量统计与缓存行为一致。

## 免费模型判定

满足以下条件即视为免费：

1. `type == "chat"`；
2. `cost.text.input.price == 0` 且 `cost.text.output.price == 0`，**或** 显示名称匹配 `/\bfree\b/i`。

成本字段是权威依据，因此 `big-pickle` 这类名称不含 Free 的零成本隐身模型也会被识别。名称匹配只是元数据变化时的兜底。

只有 `systemone` 端点的模型（例如 Jev）不是 chat-completions 模型，本项目不暴露。

## 实测额度行为

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

HTTP `429`。带不带官方身份头都观察到过，说明这些头不是额度绕过手段。额度由上游根据来源 IP、账号、模型和时间窗口决定。

本项目的 IPv6 轮换是需求本身：让每个请求走新的网络出口。它不保证额度，也不修改提示词或伪造账号。

## 为什么要复刻请求头

- **提示词缓存**：`prompt_cache_key` 与会话亲和度让上游复用缓存前缀。中转优先使用客户端传入值，否则由 API Key + 首条用户消息 + system + 模型稳定派生。
- **客户端一致性**：匹配 `User-Agent`、`x-opencode-*` 与 `stream_options.include_usage`，避免网关侧出现不必要的行为差异。
- **透明**：中转绝不修改 `system`、`messages`、`tools`、`tool_choice`。只管理 `model`、两个可选兼容字段和传输头。

## 版本刷新

OpenCode 版本更新频繁。`php bin/sync-client-version.php` 会通过 IPv6 从 npm（`@opencode-ai/cli`）或 GitHub Releases 解析当前版本，并更新 `upstream.client_version`。除非手动固定 `upstream.user_agent`，User-Agent 会自动派生。
