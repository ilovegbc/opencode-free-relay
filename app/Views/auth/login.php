<h1>登录</h1>
<p class="muted">使用用户名或邮箱登录控制台。</p>
<form method="post" action="<?= e(url('/login')) ?>" autocomplete="on">
    <?= csrf_field() ?>
    <div class="form-row">
        <label for="login">用户名 / 邮箱</label>
        <input id="login" name="login" type="text" value="<?= e(old('login')) ?>" autocomplete="username" required autofocus>
    </div>
    <div class="form-row">
        <label for="password">密码</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required>
    </div>
    <button class="button" type="submit" style="width:100%">登录</button>
</form>
<p class="auth-links">
    <?php if ((string) setting('site.registration_mode', 'open') !== 'closed'): ?>
        还没有账号？<a href="<?= e(url('/register')) ?>">注册</a>
    <?php else: ?>
        注册已关闭，请联系管理员。
    <?php endif; ?>
</p>
