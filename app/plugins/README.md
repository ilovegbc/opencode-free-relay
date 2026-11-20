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

注意：`relay.upstream.body` 在请求塑形之后执行，`body` 中已经是“官方前缀 + 作废声明 + 调用方 system”以及补齐后的 `tools`。修改它会直接改变发给上游的请求；如果你要替换塑形策略，请优先关闭 `upstream.shape_requests` 并自行实现。插件拥有进程内完整权限，只安装你信任的插件。
