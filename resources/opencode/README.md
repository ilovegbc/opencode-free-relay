# 上游准入资源

这个目录存放免费通道请求塑形所需的两个文件：

| 文件 | 用途 |
|---|---|
| `build-system.txt` | 官方 agent 的 system 提示词前缀（用于通过免费通道的形状准入） |
| `tools.json` | 官方工具 schema 数组（至少包含名为 `read` 的函数） |

## 来源

两个文件从官方 **OpenCode 1.19.6** 构建（Linux x64）中导出，导出日期 2026-11-18。
OpenCode 以 MIT 许可开源；原始版权与许可归 OpenCode 项目所有，详见 [`NOTICE.md`](NOTICE.md)。
仓库重新分发这两个文件不代表上游对本项目的认可。

## 覆盖与更新

默认读取本目录；也可以放到 Web 根目录之外：

```dotenv
OPENCODE_SHAPE_DIR=/var/lib/opencode-relay/shape
```

升级官方客户端后，前缀可能变化。上游按“逐字前缀”校验，旧前缀会导致 `429 FreeUsageLimitError`。更新步骤：

1. 从新版本客户端导出对应的 `build-system.txt` 与 `tools.json`；
2. 替换本目录或 `OPENCODE_SHAPE_DIR` 中的文件；
3. 清空 PHP OPcache 或重启 PHP-FPM（`PromptShaper` 有进程内缓存）；
4. 用一次真实请求验证。

## 关闭塑形

如果不需要请求塑形（例如你使用其他上游或已获准的通道），把 `OPENCODE_SHAPE_REQUESTS` 设为 `false`。此时请求按调用方原样转发，但仍会对 chat 强制流式。

## 文件缺失时

`PromptShaper` 会静默跳过对应步骤（前缀为空、不注入 `read`），请求仍会转发，但上游多半会以 `429 FreeUsageLimitError` 拒绝。这不是额度问题，而是形状不匹配。
