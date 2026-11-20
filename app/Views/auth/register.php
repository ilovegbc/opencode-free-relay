<h1>创建账号</h1>
<p class="muted"><?= $mode === 'invite' ? '当前为邀请制注册，请输入邀请码。' : '当前开放注册。' ?></p>
<form method="post" action="<?= e(url('/register')) ?>" autocomplete="on">
    <?= csrf_field() ?>
    <div class="form-row">
        <label for="invite">邀请码<?= $mode === 'open' ? '（可选）' : '' ?></label>
        <input id="invite" name="invite" type="text" value="<?= e(old('invite', $invite)) ?>" placeholder="inv_..." <?= $mode === 'invite' ? 'required' : '' ?>>
        <div class="help"><?= $mode === 'invite' ? '邀请制注册，必须填写有效邀请码。' : '填写管理员邀请码可使用指定套餐；不填则使用默认套餐。' ?></div>
    </div>
    <div class="form-row">
        <label for="username">用户名</label>
        <input id="username" name="username" type="text" value="<?= e(old('username')) ?>" pattern="[A-Za-z0-9_.-]{3,32}" autocomplete="username" required>
        <div class="help">3–32 位字母、数字、点、下划线或连字符。</div>
    </div>
    <div class="form-row">
        <label for="email">邮箱</label>
        <input id="email" name="email" type="email" value="<?= e(old('email')) ?>" autocomplete="email" required>
    </div>
    <div class="form-row">
        <label for="password">密码</label>
        <input id="password" name="password" type="password" minlength="8" autocomplete="new-password" required>
    </div>
    <div class="form-row">
        <label for="password_confirmation">确认密码</label>
        <input id="password_confirmation" name="password_confirmation" type="password" minlength="8" autocomplete="new-password" required>
    </div>
    <button class="button" type="submit" style="width:100%">注册</button>
</form>
<p class="auth-links">已有账号？<a href="<?= e(url('/login')) ?>">登录</a></p>
