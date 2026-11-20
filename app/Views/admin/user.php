<?php $newKey = $newKey ?? null; ?>
<div class="section-title">
    <div>
        <span class="eyebrow"><span class="dot"></span>User #<?= e((string) $account['id']) ?></span>
        <h1 style="margin-top:.7rem"><?= e($account['username']) ?></h1>
        <p class="muted" style="margin:0"><?= e($account['email'] ?: '未设置邮箱') ?> · 注册于 <?= e(format_dt($account['created_at'])) ?></p>
    </div>
    <a class="button ghost small" href="<?= e(url('/admin/users')) ?>">← 用户列表</a>
</div>

<?php if (!empty($newKey)): ?>
    <div class="secret">
        <div class="small muted" style="margin-bottom:.4rem">新 Key（仅显示一次）</div>
        <div class="code-block">
            <button type="button" class="copy-button" data-copy="#admin-new-key">复制</button>
            <code id="admin-new-key"><?= e($newKey) ?></code>
        </div>
    </div>
<?php endif; ?>

<div>
    <div class="card">
        <h2>账户设置</h2>
        <form method="post" action="<?= e(url('/admin/users/' . $account['id'])) ?>">
            <?= csrf_field() ?>
            <div class="form-grid">
                <div class="form-row"><label>邮箱</label><input name="email" type="email" value="<?= e($account['email'] ?? '') ?>"></div>
                <div class="form-row"><label>重置密码（留空不改）</label><input name="password" type="password" minlength="8" autocomplete="new-password"></div>
                <div class="form-row">
                    <label>套餐</label>
                    <select name="plan_id">
                        <?php foreach ($plans as $plan): ?>
                            <option value="<?= e((string) $plan['id']) ?>" <?= (int) $account['plan_id'] === (int) $plan['id'] ? 'selected' : '' ?>><?= e($plan['name']) ?> (<?= e($plan['code']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row">
                    <label>角色</label>
                    <select name="role"><option value="user" <?= $account['role'] === 'user' ? 'selected' : '' ?>>user</option><option value="admin" <?= $account['role'] === 'admin' ? 'selected' : '' ?>>admin</option></select>
                </div>
                <div class="form-row">
                    <label>状态</label>
                    <select name="status">
                        <?php foreach (['active', 'pending', 'suspended'] as $status): ?>
                            <option value="<?= e($status) ?>" <?= $account['status'] === $status ? 'selected' : '' ?>><?= e($status) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <button class="button" type="submit">保存账户</button>
        </form>
    </div>

    <div class="card" style="margin-top:1rem">
        <h2>额度与套餐</h2>
        <p class="muted">当前套餐：<strong><?= e($account['plan_name'] ?? '—') ?></strong> ·
            每日请求 <?= (int) ($account['daily_requests'] ?? -1) < 0 ? '不限' : e(format_number((int) $account['daily_requests'])) ?> ·
            <?= e((string) ($account['rpm'] ?? 0)) ?> RPM ·
            <?= e((string) ($account['concurrency'] ?? 0)) ?> 并发</p>
    </div>
</div>

<div class="card" style="margin-top:1rem">
    <div class="card-header">
        <h2>API Keys</h2>
        <form method="post" action="<?= e(url('/admin/users/' . $account['id'] . '/keys')) ?>" class="inline-form">
            <?= csrf_field() ?>
            <button class="button small" type="submit">为 TA 创建 Key</button>
        </form>
    </div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>名称</th><th>前缀</th><th>状态</th><th>最近使用</th><th>请求数</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($keys as $key): ?>
                <tr>
                    <td><?= e($key['name']) ?></td>
                    <td class="mono small"><?= e($key['prefix']) ?>…</td>
                    <td><span class="badge green">active</span></td>
                    <td class="small"><?= e(time_ago($key['last_used_at'])) ?></td>
                    <td class="small"><?= e(format_number((int) $key['request_count'])) ?></td>
                    <td>
                        <form method="post" action="<?= e(url('/admin/users/' . $account['id'] . '/keys/' . $key['id'] . '/delete')) ?>" data-confirm="删除后该 Key 立即失效，确定删除？" class="inline-form">
                            <?= csrf_field() ?>
                            <button class="button danger small" type="submit">删除</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($keys)): ?><tr><td colspan="6" class="muted small">暂无 Key</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card" style="margin-top:1rem">
    <h2>最近请求</h2>
    <div class="table-wrap">
        <table>
            <thead><tr><th>时间</th><th>模型</th><th>状态</th><th>出口 IPv6</th><th>Tokens</th><th>延迟</th></tr></thead>
            <tbody>
            <?php foreach ($logs as $log): ?>
                <tr>
                    <td class="small"><?= e(format_dt($log['created_at'], 'm-d H:i:s')) ?></td>
                    <td class="small mono"><?= e($log['model_id'] ?? '—') ?></td>
                    <td><span class="badge <?= (int) $log['status_code'] >= 200 && (int) $log['status_code'] < 300 ? 'green' : 'red' ?>"><?= e((string) $log['status_code']) ?></span></td>
                    <td class="small mono"><?= e($log['ip_address'] ?? '—') ?></td>
                    <td class="small"><?= e(format_number((int) $log['total_tokens'])) ?></td>
                    <td class="small"><?= e((string) $log['latency_ms']) ?>ms</td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($logs)): ?><tr><td colspan="6" class="muted small">暂无请求</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card" style="margin-top:1rem;border-color:rgba(231,76,60,.45)">
    <h2>危险操作</h2>
    <p class="muted small">删除后该用户的 API Key 与请求日志会一并清除，且不可恢复。</p>
    <form method="post" action="<?= e(url('/admin/users/' . $account['id'] . '/delete')) ?>" data-confirm="确定永久删除用户 <?= e($account['username']) ?>？此操作不可恢复。" class="inline-form">
        <?= csrf_field() ?>
        <button class="button danger small" type="submit">删除用户</button>
    </form>
</div>
