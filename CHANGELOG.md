# Changelog

## 1.1.0 — 2026-11-19

免费通道准入适配、请求塑形与账户体系增强。

- 免费通道请求塑形：官方 system 前缀（逐字保留）+ 显式作废声明 + 调用方 system；自动补齐官方 `read` 工具；客户端无工具时 `tool_choice=none`
- 工具 schema 规范化：`parameters: []` / 缺失 / 非 object 自动修正，避免部分模型 400
- 上游桥接 `bin/opencode-bridge.ts`（Bun + HTTP/2），通过传输层准入；直连 IPv6 模式保留
- chat 请求强制 `stream: true`；新增 `SseAggregator`，把 SSE 重组为标准非流式 `chat.completion`
- canonical 30 字符 session id（`ses_` + 12 hex + 14 base62）；`x-opencode-project` 默认 `global`
- 请求头对齐官方 1.19.6 CLI：只发送实际使用的 `x-opencode-*` 与 UA
- 模型同步把上游 `capabilities.reasoning` 归一化为 `model_catalog.thinking`（每次同步覆盖）
- `/v1/models` 暴露 `thinking`：`supported` / `toggle` / `efforts` / `levels` / `default` / `budget`
- 中继接受 `reasoning_effort`、`reasoning.effort`、`thinking.effort`、`thinking.budget_tokens` 与模型 ID 后缀 `id:level` / `id#level`
- 未声明的档位按阶梯收敛到最近的合法档位；客户端完全不指定时默认最高档
- 邮箱验证：新增 `email_verifications` 表、无依赖 SMTP `Mailer`、注册验证链接与后台邮件设置
- 用户邀请页：普通用户可创建/停用邀请码并查看使用次数
- 上游 410（模型下架）映射为 `404 model_deprecated` 并自动停用模型
- 移除积分、兑换码与状态页的前端与路由；数据库旧表保留用于兼容
- 文档更新：准入条件、桥接部署、随仓库提供的塑形资源、IPv6 两种出口模式

## 1.0.0 — 2026-11-15

首个开源版本。

- OpenCode 免费模型目录同步（每小时，PostgreSQL advisory lock）
- OpenAI Chat Completions / Anthropic Messages / OpenAI Responses 中继
- 官方客户端请求头与 `prompt_cache_key` 对齐
- 自定义 IPv6 CIDR 池（任意前缀长度），每次上游尝试分配新地址
- 强制 IPv6、源地址绑定、429/5xx 自动换 IP 重试、无 IPv4 回退
- 用户 / API Key / 套餐 / 每日额度 / RPM / 并发 / 积分 / 邀请码 / 兑换码
- 请求日志、每次上游尝试 IPv6 日志、审计日志、公告、诊断页
- 明暗主题前端（响应式、玻璃卡片、动态背景）
- MIT 许可，零 Composer 依赖，Hook 扩展点
