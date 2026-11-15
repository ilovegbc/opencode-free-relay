<?php $planCodes = array_map(static fn (array $p): array => ['id' => (int) $p['id'], 'code' => $p['code'], 'name' => $p['name']], $plans ?? []); ?>
<div class="section-title">
    <div>
        <span class="eyebrow"><span class="dot"></span>Users</span>
        <h1 style="margin-top:.7rem">用户</h1>
        <p class="muted" style="margin:0">搜索、创建、调整套餐和状态。</p>
    </div>
    <a class="button ghost small" href="<?= e(url('/admin')) ?>">← 返回总览</a>
</div>

<div class="toolbar">
    <form method="get" action="<?= e(url('/admin/users')) ?>">
        <input type="search" name="q" value="<?= e($search) ?>" placeholder="用户名或邮箱">
        <button class="button ghost small" type="submit">搜索</button>
        <?php if ($search !== ''): ?><a class="link-button small" href="<?= e(url('/admin/users')) ?>">清除</a><?php endif; ?>
    </form>
    <details>
        <summary>创建用户</summary>
        <form method="post" action="<?= e(url('/admin/users')) ?>" style="margin-top:.8rem;min-width:320px">
            <?= csrf_field() ?>
            <div class="form-grid">
                <div class="form-row"><label>用户名</label><input name="username" type="text" required></div>
                <div class="form-row"><label>邮箱（可选）</label><input name="email" type="email"></div>
                <div class="form-row"><label>密码</label><input name="password" type="password" minlength="8" required></div>
                <div class="form-row"><label>初始余额</label><input name="balance" type="number" min="0" step="0.0001" value="0"></div>
                <div class="form-row"><label>套餐</label><select name="plan_id"><?php foreach ($planCodes as $plan): ?><option value="<?= e((string) $plan['id']) ?>"><?= e($plan['name']) ?> (<?= e($plan['code']) ?>)</option><?php endforeach; ?></select></div>
                <div class="form-row"><label>角色</label><select name="role"><option value="user">user</option><option value="admin">admin</option></select></div>
                <div class="form-row"><label>状态</label><select name="status"><option value="active">active</option><option value="pending">pending</option><option value="suspended">suspended</option></select></div>
            </div>
            <button class="button small" type="submit">创建</button>
        </form>
    </details>
</div>

<div class="card">
    <div class="table-wrap">
        <table>
            <thead><tr><th>ID</th><th>用户</th><th>套餐</th><th>余额</th><th>状态</th><th>角色</th><th>注册</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($users as $account): ?>
                <tr>
                    <td class="small mono"><?= e((string) $account['id']) ?></td>
                    <td><a href="<?= e(url('/admin/users/' . $account['id'])) ?>"><?= e($account['username']) ?></a><div class="tiny muted"><?= e($account['email'] ?: '—') ?></div></td>
                    <td class="small"><?= e($account['plan_name'] ?? '—') ?></td>
                    <td class="small"><?= e(format_credits($account['balance'])) ?></td>
                    <td><span class="badge <?= $account['status'] === 'active' ? 'green' : ($account['status'] === 'pending' ? 'warn' : 'red') ?>"><?= e($account['status']) ?></span></td>
                    <td class="small"><?= e($account['role']) ?></td>
                    <td class="small"><?= e(format_dt($account['created_at'], 'Y-m-d')) ?></td>
                    <td><a class="button ghost small" href="<?= e(url('/admin/users/' . $account['id'])) ?>">管理</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
    $base = url('/admin/users');
    $query = $search !== '' ? ['q' => $search] : [];
    require BASE_PATH . '/app/Views/partials/pagination.php';
    ?>
</div>
