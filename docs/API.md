# API 文档

Base URL：`https://your-relay.example.com/v1`

鉴权：`Authorization: Bearer sk-oc-...`（或 `x-api-key`）。

本项目只暴露 OpenCode 免费模型。模型 ID 通过 `GET /v1/models` 获取。

## Chat Completions

```http
POST /v1/chat/completions
Content-Type: application/json
Authorization: Bearer sk-oc-...
```

```json
{
  "model": "big-pickle",
  "messages": [
    {"role": "system", "content": "You are a helpful assistant."},
    {"role": "user", "content": "写一个 PHP 函数。"}
  ],
  "tools": [],
  "stream": true
}
```

流式响应会原样转发 `text/event-stream`；非流式响应为原始 JSON。

中转完整保留 `system`、`messages`、`tools`、`tool_choice`、temperature 等字段。仅在缺失时补充 `prompt_cache_key`，并在开启时对 chat 流补充 `stream_options.include_usage`。

### 可选会话头

外部客户端可以显式传入以下任一头，让提示词缓存亲和度更稳定：

```http
X-Session-Id: ses_<64 hex>
x-session-affinity: ses_<64 hex>
x-opencode-session: ses_<64 hex>
x-opencode-project: prj_...
```

如果都没有，中转会根据 API Key + 首条用户消息 + system + 模型派生稳定的 `ses_<64 hex>`。多轮对话中首条用户消息不变，因此缓存键保持稳定。

## Anthropic Messages

```http
POST /v1/messages
```

只有上游元数据声明 `messages` 端点的模型才能使用。大多数免费模型只声明 `chat-completions`，除非 `/v1/models` 明确标注，否则请使用 `/v1/chat/completions`。

## Responses

```http
POST /v1/responses
```

同上：模型必须声明 `responses` 端点。

## 模型列表

```http
GET /v1/models
```

未登录是否可读由 `site.public_model_list` 控制。带 Key 时按套餐 `allowed_models` 过滤。

```json
{
  "object": "list",
  "data": [
    {
      "id": "big-pickle",
      "object": "model",
      "created": 1794744296,
      "owned_by": "opencode",
      "name": "Big Pickle",
      "context_length": 200000,
      "max_output_tokens": 32000,
      "pricing": {"prompt": "0", "completion": "0", "request": "0"}
    }
  ]
}
```

## 账户

```http
GET /v1/me
```

返回套餐、限额、Key 前缀与今日用量。

## 健康检查

```http
GET /health
```

数据库可连接、至少一个免费模型可用、至少一个 IPv6 池启用时返回 `200`，否则 `503`。不暴露密钥或 IP 地址。

## 错误格式

```json
{
  "error": {
    "message": "Daily request quota reached. It resets at 00:00 UTC.",
    "type": "invalid_request_error",
    "code": "daily_request_limit"
  }
}
```

| HTTP | code | 含义 |
|---:|---|---|
| 400 | `invalid_json`、`wrong_endpoint`、`validation_error` | 请求格式或端点不匹配 |
| 401 | `invalid_api_key` | Key 缺失、吊销或过期 |
| 403 | `model_forbidden`、`model_not_free`、`model_disabled` | 套餐/模型策略 |
| 404 | `model_not_found` | 未知模型 |
| 419 | `csrf_mismatch` | Web 表单会话过期 |
| 422 | `validation_error` | 字段非法 |
| 429 | `rate_limit`、`concurrency_limit`、`daily_request_limit`、`daily_token_limit`、`free_usage_limit` | 限流/额度/上游免费额度耗尽 |
| 502 | `upstream_error` | 所有 IPv6 重试后仍失败 |
| 503 | `ipv6_unavailable`、`ipv6_pool_exhausted` | 没有可用 IPv6 池/地址 |

限流/额度响应会在有意义时带 `Retry-After`。

## OpenAI SDK 示例

Python：

```python
from openai import OpenAI

client = OpenAI(base_url="https://relay.example.com/v1", api_key="sk-oc-...")
for chunk in client.chat.completions.create(
    model="big-pickle",
    messages=[{"role": "user", "content": "hi"}],
    stream=True,
):
    print(chunk.choices[0].delta.content or "", end="")
```

Node：

```js
import OpenAI from "openai";
const client = new OpenAI({ baseURL: "https://relay.example.com/v1", apiKey: "sk-oc-..." });
const stream = await client.chat.completions.create({
  model: "big-pickle",
  messages: [{ role: "user", content: "hi" }],
  stream: true,
});
for await (const chunk of stream) process.stdout.write(chunk.choices[0]?.delta?.content ?? "");
```

## CORS

`security.cors_origin` 可设为 `*` 或逗号分隔的 Origin 白名单。允许的请求头包含常见 OpenAI/Anthropic SDK 头与 OpenCode 会话头。
