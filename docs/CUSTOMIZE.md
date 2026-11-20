# 二次开发与扩展

项目刻意保持“纯 PHP + PostgreSQL”，没有 Composer 依赖。所有核心服务都可以替换或在 Hook 上扩展。

## 1. Hook 扩展点

在 `app/plugins/*.php` 放任意 PHP 文件，`bootstrap.php` 会按文件名自动加载。

### 请求头

```php
<?php
use Relay\Support\Hook;

Hook::on('relay.upstream.headers', function (array $headers, $identity): array {
    // 只增加自己的头，不要在这里改系统提示词。
    $headers['X-My-Relay'] = 'self-hosted';
    return $headers;
});
```

### 请求体

```php
Hook::on('relay.upstream.body', function (array $body, array $model): array {
    // 这个 Hook 在请求塑形之后执行：body 里已经是
    // “官方前缀 + 作废声明 + 调用方 system” 以及补齐后的 tools。
    // 如需在塑形前处理原始请求，请在控制器层扩展。
    return $body;
});
```

### 请求 URL

```php
Hook::on('relay.upstream.request', function (array $ctx): array {
    // $ctx: url, headers, body, source_ip, stream
    // 可以把请求路由到你自己的合规镜像；仍然会强制 IPv6。
    return $ctx;
});
```

### 启动事件

```php
Hook::on('app.booted', function (): void {
    // 注册自定义路由、加载配置等。
});
```

Hook 返回值是数组时替换 payload；返回 null 表示不修改。

## 2. 自定义 IP 分配算法

`Relay\Services\IpAllocator` 的职责：

1. 读取启用的地址池；
2. 按权重排序；
3. 根据 CIDR 前缀长度生成随机地址；
4. 写入 `ip_leases` 唯一租约；
5. 返回 `IpLease`。

如需接入外部地址管理 API，可继承/替换该类，并在 `ChatController` 构造 `RelayService` 时注入。关键约束：**必须返回 IPv6，不允许 IPv4 回退**。

## 3. 新增模型别名

后台 → 免费模型 → 模型别名，或 SQL：

```sql
INSERT INTO model_aliases (alias, model_id)
VALUES ('opencode/big-pickle', 'big-pickle')
ON CONFLICT (alias) DO UPDATE SET model_id = EXCLUDED.model_id;
```

客户端使用 `opencode/big-pickle` 时会自动解析到 `big-pickle`。

## 4. 自定义套餐/额度

后台 → 套餐。字段：

- `daily_requests` / `daily_tokens`：`-1` 表示不限
- `rpm`：每分钟请求数
- `concurrency`：并发请求数
- `allowed_models`：`NULL` 表示全部；否则是模型 ID 数组
- `discount_multiplier`：旧版积分折扣字段，v1.1 不再使用

默认套餐在 `plans.is_default = true`。注册时使用该套餐。

## 5. 请求塑形与思考档位

- `Relay\Services\PromptShaper` 负责免费通道的形状准入：官方前缀、`read` 工具、`tool_choice` 与工具 schema 规范化。资源文件不在仓库中，路径由 `upstream.shape_resources_dir` 指定。
- `Relay\Services\Thinking` 负责把各种客户端写法归一化为 `reasoning_effort`；档位来自模型同步写入的 `model_catalog.thinking`。
- 如需替换塑形逻辑，可实现自己的 shaper 并注入 `OpenCodeClient`；保留“前缀逐字不改、调用方指令在后”的顺序。
- `upstream.shape_requests=false` 会完全关闭塑形，仅保留 chat 强流式。

## 6. 替换上游客户端

`Relay\Services\OpenCodeClient` 是唯一直接发起上游 HTTP 的地方。替换时请保留：

- 直连模式：`CURLOPT_IPRESOLVE = CURL_IPRESOLVE_V6`、源地址绑定、禁用重定向与代理
- 桥接模式：`POST {bridge_url}/forward` 的信封格式与 `x-bridge-token`
- 官方身份头与 canonical session
- 流式写回调；chat 非流式由 `SseAggregator` 重组

`RelayService` 只依赖它的 `send()` 返回 `UpstreamResult`。

## 7. 数据库扩展

新增表请接着现有编号放到 `database/migrations/005_xxx.sql`，然后：

```bash
php bin/migrate.php
```

迁移按文件名排序、记录到 `schema_migrations`，不会重复执行。

## 8. 前端

- 模板：`app/Views/`
- 样式：`public/assets/app.css`（明暗主题变量）
- 脚本：`public/assets/app.js`（主题、Toast、复制、涟漪、倾斜、reveal、FAQ）
- 图标：`public/assets/logo.svg`

主题变量集中在 `:root` 与 `:root[data-theme="dark"]`，改色不需要动模板。

## 9. 新增 API 端点

1. 在 `public/index.php` 注册路由；
2. 在 `app/Controllers/Api/` 新建控制器；
3. 失败时抛 `HttpException` / `ValidationException` / `UpstreamException`，入口会统一转成 JSON；
4. 如需要 API Key 鉴权，注入 `ApiAuthenticator`。

## 10. 代码风格

- `declare(strict_types=1);`
- PSR-4 命名空间 `Relay\`
- 不引入全局状态；DB/Settings 通过 `App::db()` / `App::settings()` 获取
- 所有 SQL 使用预处理参数
- 面向用户的文案放在模板或设置中，不要硬编码在服务层
- 不提交 `.env`、密钥、日志

## 11. 开源注意事项

- 保留 MIT LICENSE
- 不要在主分支提交真实 IPv6 段、运营商标识、用户数据或 API Key
- 对上游条款、隐私政策与当地法律做自己的合规评估
- 若你部署公开实例，建议：
  - 开启邀请制或审核
  - 配置每日额度与 RPM
  - 关闭 `logging.log_prompts`
  - 设置 `security.cors_origin` 为明确的 Origin
  - 定期备份并轮换 `APP_KEY`（会失效旧 Key）
