<div class="section-title">
    <div>
        <span class="eyebrow"><span class="dot"></span>Account</span>
        <h1 style="margin-top:.7rem">账户设置</h1>
        <p class="muted" style="margin:0">修改密码后其他会话不会自动退出，但新密码立即生效。</p>
    </div>
    <a class="button ghost small" href="<?= e(url('/dashboard')) ?>">← 返回控制台</a>
</div>
<div class="split">
    <div class="card">
        <h2>修改密码</h2>
        <form method="post" action="<?= e(url('/dashboard/settings/password')) ?>">
            <?= csrf_field() ?>
            <div class="form-row">
                <label for="current_password">当前密码</label>
                <input id="current_password" name="current_password" type="password" autocomplete="current-password" required>
            </div>
            <div class="form-row">
                <label for="password">新密码</label>
                <input id="password" name="password" type="password" minlength="8" autocomplete="new-password" required>
            </div>
            <div class="form-row">
                <label for="password_confirmation">确认新密码</label>
                <input id="password_confirmation" name="password_confirmation" type="password" minlength="8" autocomplete="new-password" required>
            </div>
            <button class="button" type="submit">更新密码</button>
        </form>
    </div>
    <aside class="card">
        <h3>账户</h3>
        <p class="small muted">用户名</p>
        <p class="mono"><?= e($user['username']) ?></p>
        <p class="small muted">邮箱</p>
        <p><?= e($user['email'] ?: '未设置') ?></p>
        <p class="small muted">注册时间</p>
        <p><?= e(format_dt($user['created_at'])) ?></p>
        <p class="help">如需修改用户名、邮箱或套餐，请联系管理员。</p>
    </aside>
</div>
