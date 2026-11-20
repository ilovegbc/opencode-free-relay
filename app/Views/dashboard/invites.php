<?php $newCode = $newCode ?? null; ?>
<div class="section-title">
    <div>
        <span class="eyebrow"><span class="dot"></span>Invites</span>
        <h1 style="margin-top:.7rem">邀请码</h1>
        <p class="muted" style="margin:0">每个邀请码只能使用一次，新用户按默认套餐注册。可创建任意数量。</p>
    </div>
    <a class="button ghost small" href="<?= e(url('/dashboard')) ?>">← 返回控制台</a>
</div>

<?php if (!empty($newCode)): ?>
    <div class="secret">
        <div class="small muted" style="margin-bottom:.4rem">新邀请码（仅显示一次）</div>
        <div class="code-block">
            <button type="button" class="copy-button" data-copy="#new-invite">复制</button>
            <code id="new-invite"><?= e($newCode) ?></code>
        </div>
    </div>
<?php endif; ?>

<div class="split">
    <div class="card">
        <div class="card-header"><h2>我的邀请码</h2><span class="badge"><?= e((string) count($invites)) ?></span></div>
        <?php if (empty($invites)): ?>
            <p class="muted">还没有邀请码。用右侧表单生成一个。</p>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>标签</th><th>套餐</th><th>状态</th><th>创建时间</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($invites as $invite): ?>
                        <?php $used = (int) $invite['used_count'] >= (int) $invite['max_uses']; ?>
                        <tr>
                            <td><?= e($invite['label'] ?: '—') ?></td>
                            <td class="small"><?= e($invite['plan_name'] ?? '默认套餐') ?></td>
                            <td>
                                <?php if (!$invite['active']): ?>
                                    <span class="badge red">已停用</span>
                                <?php elseif ($used): ?>
                                    <span class="badge">已使用</span>
                                <?php else: ?>
                                    <span class="badge green">可用</span>
                                <?php endif; ?>
                            </td>
                            <td class="small"><?= e(format_dt($invite['created_at'], 'Y-m-d H:i')) ?></td>
                            <td>
                                <?php if ($invite['active'] && !$used): ?>
                                    <form method="post" action="<?= e(url('/dashboard/invites/' . $invite['id'] . '/disable')) ?>" data-confirm="停用这个邀请码？" class="inline-form">
                                        <?= csrf_field() ?>
                                        <button class="button danger small" type="submit">停用</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <aside class="card">
        <h3>生成邀请码</h3>
        <form method="post" action="<?= e(url('/dashboard/invites')) ?>">
            <?= csrf_field() ?>
            <div class="form-row">
                <label for="label">标签（可选）</label>
                <input id="label" name="label" type="text" maxlength="64" placeholder="friends">
            </div>
            <button class="button" type="submit">生成邀请码</button>
        </form>
        <p class="help">邀请码只能使用一次，使用后自动失效。仅管理员可指定更高等级套餐。</p>
    </aside>
</div>
