# Changelog

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
