<div class="section-title">
    <div>
        <span class="eyebrow"><span class="dot"></span>Credits</span>
        <h1 style="margin-top:.7rem">兑换积分</h1>
        <p class="muted" style="margin:0">输入管理员生成的兑换码，积分会立即计入余额。</p>
    </div>
    <a class="button ghost small" href="<?= e(url('/dashboard')) ?>">← 返回控制台</a>
</div>
<div class="split">
    <div class="card">
        <h2>兑换码</h2>
        <form method="post" action="<?= e(url('/dashboard/redeem')) ?>">
            <?= csrf_field() ?>
            <div class="form-row">
                <label for="code">兑换码</label>
                <input id="code" name="code" type="text" placeholder="rdm_..." required autofocus>
            </div>
            <button class="button" type="submit">兑换</button>
        </form>
    </div>
    <aside class="card">
        <h3>说明</h3>
        <p class="desc">每个兑换码可能有次数上限和过期时间；同一用户对同一码只能兑换一次。积分可用于付费模型（若管理员配置了价格），免费模型默认为 0 积分。</p>
    </aside>
</div>
