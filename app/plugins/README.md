# Plugins

这个目录下的 `*.php` 会在 `bootstrap.php` 末尾自动加载。适合放不准备合入主干的私有扩展。

示例（保存为 `my-plugin.php`）：

```php
<?php
use Relay\Support\Hook;

Hook::on('relay.upstream.headers', function (array $headers): array {
    $headers['X-Relay-Operator'] = 'my-instance';
    return $headers;
});
```

可用事件：

- `app.booted`
- `relay.upstream.request`：`url`, `headers`, `body`, `source_ip`, `stream`
- `relay.upstream.headers`
- `relay.upstream.body`

注意：不要修改 `system` / `messages` / `tools`，否则会破坏项目“不修改提示词、与官方客户端一致”的承诺。插件拥有进程内完整权限，只安装你信任的插件。
