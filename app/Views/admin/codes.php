<?php $newCode = $newCode ?? null; ?>
<div class="section-title">
    <div>
        <span class="eyebrow"><span class="dot"></span>Growth</span>
        <h1 style="margin-top:.7rem">邀请与兑换</h1>
        <p class="muted" style="margin:0">邀请码控制注册入口；兑换码给用户增加积分。</p>
    </div>
    <a class="button ghost small" href="<?= e(url('/admin')) ?>">← 总览</a>
</div>

<?php if (!empty($newCode)): ?>
    <div class="secret">
        <div class="small muted" style="margin-bottom:.4rem">新代码（仅显示一次）</div>
        <div class="code-block">
            <button type="button" class="copy-button" data-copy="#new-code">复制</button>
            <code id="new-code"><?= e($newCode) ?></code>
        </div>
    </div>
<?php endif; ?>

<div class="grid grid-2">
    <div class="card">
        <h2>生成邀请码</h2>
        <form method="post" action="<?= e(url('/admin/codes/invites')) ?>">
            <?= csrf_field() ?>
            <div class="form-grid">
                <div class="form-row"><label>标签</label><input name="label" type="text" placeholder="friends / launch"></div>
                <div class="form-row"><label>可用次数</label><input name="max_uses" type="number" min="1" value="1"></div>
                <div class="form-row"><label>指定套餐</label><select name="plan_id"><option value="0">默认</option><?php foreach ($plans as $plan): ?><option value="<?= e((string) $plan['id']) ?>"><?= e($plan['name']) ?></option><?php endforeach; ?></select></div>
                <div class="form-row"><label>初始积分</label><input name="initial_credits" type="number" min="0" step="0.0001" value="0"></div>
                <div class="form-row"><label>过期时间</label><input name="expires_at" type="datetime-local"></div>
            </div>
            <button class="button" type="submit">生成邀请码</button>
        </form>
    </div>

    <div class="card">
        <h2>生成兑换码</h2>
        <form method="post" action="<?= e(url('/admin/codes/redemptions')) ?>">
            <?= csrf_field() ?>
            <div class="form-grid">
                <div class="form-row"><label>积分数</label><input name="credits" type="number" min="0.0001" step="0.0001" required></div>
                <div class="form-row"><label>可用次数</label><input name="max_uses" type="number" min="1" value="1"></div>
                <div class="form-row"><label>过期时间</label><input name="expires_at" type="datetime-local"></div>
                <div class="form-row"><label>备注</label><input name="note" type="text"></div>
            </div>
            <button class="button" type="submit">生成兑换码</button>
        </form>
    </div>
</div>

<div class="grid grid-2" style="margin-top:1rem">
    <div class="card">
        <h2>邀请码</h2>
        <div class="table-wrap">
            <table>
                <thead><tr><th>标签</th><th>套餐</th><th>使用</th><th>过期</th><th>状态</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($invites as $invite): ?>
                    <tr>
                        <td class="small"><?= e($invite['label'] ?: '—') ?><div class="tiny muted"><?= e($invite['creator'] ?? '') ?></div></td>
                        <td class="small"><?= e($invite['plan_name'] ?? '默认') ?></td>
                        <td class="small"><?= e((string) $invite['used_count']) ?> / <?= e((string) $invite['max_uses']) ?></td>
                        <td class="small"><?= e($invite['expires_at'] ? format_dt($invite['expires_at'], 'Y-m-d') : '—') ?></td>
                        <td><span class="badge <?= $invite['active'] ? 'green' : 'red' ?>"><?= $invite['active'] ? 'active' : 'off' ?></span></td>
                        <td>
                            <?php if ($invite['active']): ?>
                                <form method="post" action="<?= e(url('/admin/codes/invites/' . $invite['id'] . '/disable')) ?>" class="inline-form">
                                    <?= csrf_field() ?><button class="button danger small" type="submit">停用</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($invites)): ?><tr><td colspan="6" class="muted small">暂无邀请码</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h2>兑换码</h2>
        <div class="table-wrap">
            <table>
                <thead><tr><th>积分</th><th>使用</th><th>备注</th><th>过期</th><th>状态</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($redemptions as $redemption): ?>
                    <tr>
                        <td class="small"><?= e(format_credits($redemption['credits'])) ?></td>
                        <td class="small"><?= e((string) $redemption['used_count']) ?> / <?= e((string) $redemption['max_uses']) ?></td>
                        <td class="small"><?= e($redemption['note']) ?></td>
                        <td class="small"><?= e($redemption['expires_at'] ? format_dt($redemption['expires_at'], 'Y-m-d') : '—') ?></td>
                        <td><span class="badge <?= $redemption['active'] ? 'green' : 'red' ?>"><?= $redemption['active'] ? 'active' : 'off' ?></span></td>
                        <td>
                            <?php if ($redemption['active']): ?>
                                <form method="post" action="<?= e(url('/admin/codes/redemptions/' . $redemption['id'] . '/disable')) ?>" class="inline-form">
                                    <?= csrf_field() ?><button class="button danger small" type="submit">停用</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($redemptions)): ?><tr><td colspan="6" class="muted small">暂无兑换码</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
