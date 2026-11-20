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

流式请求会即时转发 `text/event-stream`；非流式 chat 请求在上游仍以流式发送，返回前由 `SseAggregator` 重组为标准 `chat.completion` JSON。

### 请求塑形

免费通道要求请求与官方客户端同形，因此 chat 请求会在转发前被改写：

- `system` 变为“官方 agent 前缀 + 显式作废声明 + 调用方 system”；调用方消息本身不改；
- `tools` 保留调用方定义，并确保存在官方 `read`（客户端没有时作为协议占位注入）；
- 客户端完全没有工具时 `tool_choice` 置为 `none`，避免模型调用无人执行的占位工具；
- `function.parameters` 会被规范化为合法 object schema；
- `stream` 强制为 `true`，并补充 `stream_options.include_usage`；
- 思考档位统一改写为顶层 `reasoning_effort`（见下节）。

`upstream.shape_requests=false` 可关闭塑形；此时请求按调用方原样转发（仍会强制 chat 流式）。仓库已附带 `resources/opencode/build-system.txt` 与 `tools.json`（MIT，见 `NOTICE.md`）。

### 思考等级（reasoning effort）

`GET /v1/models` 的每个模型都带 `thinking`，由上游 `capabilities.reasoning` 同步：

```json
"thinking": {
  "supported": true,
  "toggle": true,
  "efforts": ["low", "high", "max"],
  "levels": ["none", "low", "high", "max"],
  "default": null,
  "budget": null
}
```

- `efforts`：上游声明的档位（顺序保持上游原样）。
- `levels`：客户端可选档位，`toggle` 为 true 时追加 `none`（关闭思考）。
- `supported` 为 true 但没有 `levels` 的模型（如 `big-pickle`）不限制档位值，按客户端传来的值透传。

客户端可以选择任一种写法，中转统一转成上游 chat-completions 的 `reasoning_effort`：

```json
{
  "model": "deepseek-v4.1-flash-free",
  "messages": [{"role": "user", "content": "你好"}],
  "reasoning_effort": "high"
}
```

```json
{"model": "deepseek-v4.1-flash-free", "reasoning": {"effort": "high"}, "messages": []}
{"model": "deepseek-v4.1-flash-free", "thinking": {"type": "enabled", "effort": "high"}, "messages": []}
{"model": "deepseek-v4.1-flash-free", "thinking": {"type": "enabled", "budget_tokens": 8192}, "messages": []}
```

也可以把档位直接写进模型 ID：`deepseek-v4.1-flash-free:high`、`deepseek-v4.1-flash-free#max`。

收敛规则：值不在 `levels` 里时取档位阶梯（`none < minimal < low < medium < high < xhigh < max`）上最近的支持档位，
等距取更高档；`auto` / `default` 表示跟随上游默认。上游对未声明档位不报错而是静默不思考，所以这一步在中转侧完成。
**客户端完全没有指定档位时，中转按该模型的最高档发送**（模型未声明 `effort` 列表时不做处理，交给上游默认）。
Anthropic Messages 端点不改写 `thinking`，按客户端原样转发。


### 可选会话头

外部客户端可以显式传入以下任一头，让提示词缓存亲和度更稳定：

```http
X-Session-Id: ses_<30 字符 canonical id>
x-session-affinity: ses_<30 字符 canonical id>
x-opencode-session: ses_<30 字符 canonical id>
x-opencode-project: prj_...
```

如果都没有，中转会根据 API Key + 首条用户消息 + 模型派生稳定的 canonical id。任意 `ses_...`、UUID、64 位 hex 都会被规范化为官方 30 字符布局：`ses_` + 12 位小写 hex + 14 位 base62，编码时间落在上游 ±30 天窗口内。多轮对话中首条用户消息不变，因此缓存键保持稳定；`prompt_cache_key` 取 canonical id 去掉 `ses_` 后的 26 个字符。

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
      "pricing": {"prompt": "0", "completion": "0", "request": "0"},
      "capabilities": {"tools": {"supported": true}, "reasoning": {"supported": true}},
      "thinking": {
        "supported": true,
        "toggle": false,
        "efforts": [],
        "levels": [],
        "default": null,
        "budget": null
      }
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
| 404 | `model_not_found`、`model_deprecated` | 未知模型，或上游已下架 |
| 419 | `csrf_mismatch` | Web 表单会话过期 |
| 422 | `validation_error` | 字段非法 |
| 429 | `rate_limit`、`concurrency_limit`、`daily_request_limit`、`daily_token_limit`、`free_usage_limit` | 限流/额度/上游免费额度或形状拒绝 |
| 502 | `upstream_error`、`upstream_client_error` | 上游或桥接失败 |
| 503 | `ipv6_unavailable`、`ipv6_pool_exhausted`、`service_unavailable` | 没有可用 IPv6 池/地址，或服务暂不可用 |

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
