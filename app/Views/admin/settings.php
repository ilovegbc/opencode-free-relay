<?php
$get = static fn (string $key, mixed $default = null): mixed => $settings[$key] ?? $default;
$checked = static fn (string $key): string => !empty($settings[$key]) ? 'checked' : '';
$val = static fn (string $key, mixed $default = ''): string => (string) ($settings[$key] ?? $default);
$planCodes = array_map(static fn (array $p): string => (string) $p['code'], $plans ?? []);
?>
<div class="section-title">
    <div>
        <span class="eyebrow"><span class="dot"></span>Configuration</span>
        <h1 style="margin-top:.7rem">系统设置</h1>
        <p class="muted" style="margin:0">这些值保存在 PostgreSQL <code>settings</code> 表，修改后立即生效。</p>
    </div>
    <a class="button ghost small" href="<?= e(url('/admin')) ?>">← 返回总览</a>
</div>

<form method="post" action="<?= e(url('/admin/settings')) ?>">
    <?= csrf_field() ?>

    <div class="grid grid-2">
        <div class="card">
            <h2>站点</h2>
            <div class="form-row"><label>站点名称</label><input name="site.name" type="text" value="<?= e($val('site.name')) ?>" required></div>
            <div class="form-row"><label>标语</label><input name="site.tagline" type="text" value="<?= e($val('site.tagline')) ?>"></div>
            <div class="form-row"><label>描述</label><textarea name="site.description"><?= e($val('site.description')) ?></textarea></div>
            <div class="form-grid">
                <div class="form-row">
                    <label>注册模式</label>
                    <select name="site.registration_mode">
                        <?php foreach (['open' => '开放注册', 'invite' => '邀请制', 'closed' => '关闭注册'] as $key => $label): ?>
                            <option value="<?= e($key) ?>" <?= $val('site.registration_mode') === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row">
                    <label>默认套餐</label>
                    <select name="site.default_plan">
                        <?php foreach ($planCodes as $code): ?>
                            <option value="<?= e($code) ?>" <?= $val('site.default_plan') === $code ? 'selected' : '' ?>><?= e($code) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="checkbox form-row"><input id="require_approval" type="checkbox" name="site.require_approval" value="1" <?= $checked('site.require_approval') ?>><label for="require_approval">注册后需管理员审核</label></div>
            <div class="checkbox form-row"><input id="public_models" type="checkbox" name="site.public_model_list" value="1" <?= $checked('site.public_model_list') ?>><label for="public_models">允许未登录读取 /v1/models</label></div>
        </div>

        <div class="card">
            <h2>OpenCode 上游</h2>
            <div class="form-row"><label>Base URL</label><input name="upstream.base_url" type="url" value="<?= e($val('upstream.base_url')) ?>" required></div>
            <div class="form-row"><label>模型列表 URL</label><input name="upstream.models_url" type="url" value="<?= e($val('upstream.models_url')) ?>" required></div>
            <div class="form-row"><label>上游 Key</label><input name="upstream.key" type="text" value="<?= e($val('upstream.key')) ?>"><div class="help">免费模型无需真实 Key；官方客户端使用占位值 <code>public</code>。留空则完全不发送 Authorization。</div></div>
            <div class="form-grid">
                <div class="form-row"><label>客户端名</label><input name="upstream.client_name" type="text" value="<?= e($val('upstream.client_name')) ?>"></div>
                <div class="form-row"><label>通道</label><input name="upstream.client_channel" type="text" value="<?= e($val('upstream.client_channel')) ?>"></div>
                <div class="form-row"><label>版本</label><input name="upstream.client_version" type="text" value="<?= e($val('upstream.client_version')) ?>"></div>
                <div class="form-row"><label>UA 覆盖（留空自动）</label><input name="upstream.user_agent" type="text" value="<?= e($val('upstream.user_agent')) ?>"></div>
            </div>
            <div class="form-grid">
                <div class="form-row"><label>最大换 IP 重试次数</label><input name="upstream.max_ip_retries" type="number" min="0" max="20" value="<?= e($val('upstream.max_ip_retries', 2)) ?>"></div>
                <div class="form-row"><label>连接超时（秒）</label><input name="upstream.connect_timeout" type="number" min="1" max="120" value="<?= e($val('upstream.connect_timeout', 15)) ?>"></div>
                <div class="form-row"><label>总超时（秒）</label><input name="upstream.timeout" type="number" min="30" max="3600" value="<?= e($val('upstream.timeout', 900)) ?>"></div>
                <div class="form-row"><label>允许的上游主机</label><input name="upstream.allowed_hosts" type="text" value="<?= e($val('upstream.allowed_hosts')) ?>"><div class="help">逗号分隔，支持子域。</div></div>
            </div>
            <div class="checkbox form-row"><input id="stream_usage" type="checkbox" name="upstream.stream_include_usage" value="1" <?= $checked('upstream.stream_include_usage') ?>><label for="stream_usage">流式请求补充 stream_options.include_usage</label></div>
            <div class="checkbox form-row"><input id="cache_key" type="checkbox" name="upstream.prompt_cache_key" value="1" <?= $checked('upstream.prompt_cache_key') ?>><label for="cache_key">自动补充 prompt_cache_key</label></div>
            <div class="checkbox form-row"><input id="mirror_headers" type="checkbox" name="upstream.mirror_client_headers" value="1" <?= $checked('upstream.mirror_client_headers') ?>><label for="mirror_headers">发送完整 OpenCode 身份请求头</label></div>
            <div class="checkbox form-row"><input id="verify_tls" type="checkbox" name="upstream.verify_tls" value="1" <?= $checked('upstream.verify_tls') ?>><label for="verify_tls">校验上游 TLS 证书</label></div>
            <div class="checkbox form-row"><input id="allow_http" type="checkbox" name="upstream.allow_http" value="1" <?= $checked('upstream.allow_http') ?>><label for="allow_http">允许 HTTP 上游（仅本地测试）</label></div>
        </div>
    </div>

    <div class="grid grid-2" style="margin-top:1rem">
        <div class="card">
            <h2>IPv6</h2>
            <p class="help" style="margin-top:0">本项目强制 IPv6：即使配置错误也不会回退 IPv4，而是直接返回 503。</p>
            <div class="checkbox form-row"><input id="allow_loopback" type="checkbox" name="ipv6.allow_loopback" value="1" <?= $checked('ipv6.allow_loopback') ?>><label for="allow_loopback">允许 ::1/128 池（仅开发）</label></div>
            <div class="form-grid">
                <div class="form-row"><label>默认租约 TTL（秒）</label><input name="ipv6.default_lease_ttl" type="number" min="0" value="<?= e($val('ipv6.default_lease_ttl', 604800)) ?>"><div class="help">0 表示约 100 年不回收。</div></div>
                <div class="form-row">
                    <label>绑定方式</label>
                    <select name="ipv6.bind_method">
                        <option value="interface" <?= $val('ipv6.bind_method') === 'interface' ? 'selected' : '' ?>>CURLOPT_INTERFACE</option>
                        <option value="opensocket" <?= $val('ipv6.bind_method') === 'opensocket' ? 'selected' : '' ?>>opensocket + socket_bind</option>
                    </select>
                </div>
            </div>
            <div class="form-row"><label>探针 URL</label><input name="ipv6.probe_url" type="url" value="<?= e($val('ipv6.probe_url')) ?>"></div>
            <p class="help">部署要求：<code>net.ipv6.ip_nonlocal_bind=1</code>、网段路由到本机、/64 场景配置 NDP 代理。详见 README。</p>
        </div>

        <div class="card">
            <h2>模型 / 安全 / 计费</h2>
            <div class="checkbox form-row"><input id="auto_free" type="checkbox" name="models.auto_enable_new_free" value="1" <?= $checked('models.auto_enable_new_free') ?>><label for="auto_free">自动启用新发现的免费模型</label></div>
            <div class="form-row"><label>模型同步间隔（秒）</label><input name="models.sync_interval" type="number" min="60" value="<?= e($val('models.sync_interval', 3600)) ?>"></div>
            <div class="form-grid">
                <div class="form-row"><label>登录失败上限</label><input name="security.login_max_attempts" type="number" min="1" value="<?= e($val('security.login_max_attempts', 10)) ?>"></div>
                <div class="form-row"><label>登录窗口（分钟）</label><input name="security.login_window_minutes" type="number" min="1" value="<?= e($val('security.login_window_minutes', 15)) ?>"></div>
                <div class="form-row"><label>单用户最大活跃 Key</label><input name="security.max_keys_per_user" type="number" min="1" value="<?= e($val('security.max_keys_per_user', 10)) ?>"></div>
                <div class="form-row"><label>CORS Origin</label><input name="security.cors_origin" type="text" value="<?= e($val('security.cors_origin', '*')) ?>"><div class="help">* 或逗号分隔的 Origin。</div></div>
            </div>
            <div class="checkbox form-row"><input id="log_prompts" type="checkbox" name="logging.log_prompts" value="1" <?= $checked('logging.log_prompts') ?>><label for="log_prompts">记录提示词内容（默认关闭，隐私优先）</label></div>
            <div class="form-grid">
                <div class="form-row"><label>日志保留（天）</label><input name="logging.log_retention_days" type="number" min="1" value="<?= e($val('logging.log_retention_days', 30)) ?>"></div>
                <div class="form-row"><label>租约保留（天）</label><input name="logging.lease_retention_days" type="number" min="0" value="<?= e($val('logging.lease_retention_days', 7)) ?>"></div>
            </div>
            <div class="form-row"><label>页脚文案</label><input name="branding.footer" type="text" value="<?= e($val('branding.footer')) ?>"></div>
        </div>

        <div class="card">
            <h2>邮件（SMTP）</h2>
            <p class="muted small">启用后，注册必须填写真实邮箱并点击验证链接才能激活账号；
                邮箱验证通过即自动激活，不再走管理员审批。未启用时不发邮件、注册直接成功。</p>
            <div class="checkbox form-row">
                <input id="mail_enabled" type="checkbox" name="mail.enabled" value="1" <?= $checked('mail.enabled') ?>>
                <label for="mail_enabled">启用 SMTP 邮箱验证</label>
            </div>
            <div class="form-grid">
                <div class="form-row"><label>SMTP 主机</label><input name="mail.host" type="text" value="<?= e($val('mail.host')) ?>" placeholder="smtp.example.com"></div>
                <div class="form-row"><label>端口</label><input name="mail.port" type="number" min="1" max="65535" value="<?= e($val('mail.port', 587)) ?>"></div>
                <div class="form-row"><label>加密</label>
                    <select name="mail.encryption">
                        <?php foreach (['tls' => 'STARTTLS (587)', 'ssl' => 'SSL (465)', 'none' => '无加密 (25)'] as $k => $label): ?>
                            <option value="<?= e($k) ?>" <?= (string) $val('mail.encryption', 'tls') === $k ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row"><label>用户名（可选）</label><input name="mail.username" type="text" value="<?= e($val('mail.username')) ?>" autocomplete="off"></div>
                <div class="form-row"><label>密码（留空不变）</label><input name="mail.password" type="password" value="" autocomplete="new-password"></div>
                <div class="form-row"><label>发件地址</label><input name="mail.from_address" type="email" value="<?= e($val('mail.from_address')) ?>" placeholder="no-reply@example.com"></div>
                <div class="form-row"><label>发件人名称</label><input name="mail.from_name" type="text" value="<?= e($val('mail.from_name')) ?>"></div>
                <div class="form-row"><label>验证有效期（分钟）</label><input name="mail.verify_ttl_minutes" type="number" min="5" value="<?= e($val('mail.verify_ttl_minutes', 60)) ?>"></div>
            </div>
            <p class="help">当前状态：<strong><?= $checked('mail.enabled') && trim((string) $val('mail.host')) !== '' && trim((string) $val('mail.from_address')) !== '' ? '已配置，注册需要邮箱验证' : '未配置，注册不验证邮箱' ?></strong></p>
        </div>
    </div>

    <div class="button-row" style="margin-top:1rem">
        <button class="button" type="submit">保存设置</button>
        <span class="muted small">保存后立即生效；进行中的请求使用旧配置。</span>
    </div>
</form>
