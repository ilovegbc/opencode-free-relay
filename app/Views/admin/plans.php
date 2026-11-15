<div class="section-title">
    <div>
        <span class="eyebrow"><span class="dot"></span>Plans</span>
        <h1 style="margin-top:.7rem">套餐</h1>
        <p class="muted" style="margin:0">套餐控制每日请求/Token、RPM、并发、可用模型与积分折扣。</p>
    </div>
    <a class="button ghost small" href="<?= e(url('/admin')) ?>">← 总览</a>
</div>

<div class="card">
    <h2>创建套餐</h2>
    <form method="post" action="<?= e(url('/admin/plans')) ?>">
        <?= csrf_field() ?>
        <div class="form-grid">
            <div class="form-row"><label>Code</label><input name="code" type="text" placeholder="free-plus" required></div>
            <div class="form-row"><label>名称</label><input name="name" type="text" placeholder="Free Plus" required></div>
            <div class="form-row full"><label>描述</label><input name="description" type="text"></div>
            <div class="form-row"><label>价格（积分）</label><input name="price_credits" type="number" min="0" step="0.0001" value="0"></div>
            <div class="form-row"><label>每日请求（-1 不限）</label><input name="daily_requests" type="number" value="200"></div>
            <div class="form-row"><label>每日 Tokens（-1 不限）</label><input name="daily_tokens" type="number" value="5000000"></div>
            <div class="form-row"><label>RPM</label><input name="rpm" type="number" min="0" value="30"></div>
            <div class="form-row"><label>并发</label><input name="concurrency" type="number" min="1" value="3"></div>
            <div class="form-row"><label>折扣倍率</label><input name="discount_multiplier" type="number" min="0.0001" step="0.0001" value="1"></div>
            <div class="form-row"><label>允许模型（逗号分隔，空=全部）</label><input name="allowed_models" type="text"></div>
            <div class="form-row"><label>排序</label><input name="sort_order" type="number" value="0"></div>
        </div>
        <div class="checkbox form-row"><input id="plan_default" type="checkbox" name="is_default" value="1"><label for="plan_default">设为默认套餐</label></div>
        <div class="checkbox form-row"><input id="plan_active" type="checkbox" name="active" value="1" checked><label for="plan_active">启用</label></div>
        <button class="button" type="submit">创建套餐</button>
    </form>
</div>

<div class="card" style="margin-top:1rem">
    <h2>现有套餐</h2>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Code</th><th>名称</th><th>每日</th><th>RPM / 并发</th><th>折扣</th><th>用户</th><th>状态</th><th>操作</th></tr></thead>
            <tbody>
            <?php foreach ($plans as $plan): ?>
                <tr>
                    <td class="mono small"><?= e($plan['code']) ?></td>
                    <td><strong><?= e($plan['name']) ?></strong><div class="tiny muted"><?= e($plan['description']) ?></div></td>
                    <td class="small"><?= $plan['daily_requests'] < 0 ? '∞' : e(format_number((int) $plan['daily_requests'])) ?> req<br><?= $plan['daily_tokens'] < 0 ? '∞' : e(format_number((int) $plan['daily_tokens'])) ?> tok</td>
                    <td class="small"><?= e((string) $plan['rpm']) ?> / <?= e((string) $plan['concurrency']) ?></td>
                    <td class="small">×<?= e($plan['discount_multiplier']) ?></td>
                    <td class="small"><?= e((string) ($plan['user_count'] ?? 0)) ?></td>
                    <td>
                        <?php if ($plan['is_default']): ?><span class="badge green">default</span><?php endif; ?>
                        <span class="badge <?= $plan['active'] ? 'green' : 'red' ?>"><?= $plan['active'] ? 'active' : 'off' ?></span>
                    </td>
                    <td>
                        <details>
                            <summary class="small">编辑</summary>
                            <form method="post" action="<?= e(url('/admin/plans/' . $plan['id'])) ?>" style="margin-top:.6rem;min-width:300px">
                                <?= csrf_field() ?>
                                <div class="form-row"><label>Code</label><input name="code" type="text" value="<?= e($plan['code']) ?>" required></div>
                                <div class="form-row"><label>名称</label><input name="name" type="text" value="<?= e($plan['name']) ?>" required></div>
                                <div class="form-row"><label>描述</label><input name="description" type="text" value="<?= e($plan['description']) ?>"></div>
                                <div class="form-grid">
                                    <div class="form-row"><label>价格</label><input name="price_credits" type="number" min="0" step="0.0001" value="<?= e($plan['price_credits']) ?>"></div>
                                    <div class="form-row"><label>折扣</label><input name="discount_multiplier" type="number" min="0.0001" step="0.0001" value="<?= e($plan['discount_multiplier']) ?>"></div>
                                    <div class="form-row"><label>每日请求</label><input name="daily_requests" type="number" value="<?= e((string) $plan['daily_requests']) ?>"></div>
                                    <div class="form-row"><label>每日 Tokens</label><input name="daily_tokens" type="number" value="<?= e((string) $plan['daily_tokens']) ?>"></div>
                                    <div class="form-row"><label>RPM</label><input name="rpm" type="number" min="0" value="<?= e((string) $plan['rpm']) ?>"></div>
                                    <div class="form-row"><label>并发</label><input name="concurrency" type="number" min="1" value="<?= e((string) $plan['concurrency']) ?>"></div>
                                    <div class="form-row"><label>排序</label><input name="sort_order" type="number" value="<?= e((string) $plan['sort_order']) ?>"></div>
                                    <div class="form-row"><label>允许模型</label><input name="allowed_models" type="text" value="<?= e(implode(',', pg_text_array($plan['allowed_models'] ?? null) ?? [])) ?>"></div>
                                </div>
                                <div class="checkbox form-row"><input id="default_<?= e((string) $plan['id']) ?>" type="checkbox" name="is_default" value="1" <?= $plan['is_default'] ? 'checked' : '' ?>><label for="default_<?= e((string) $plan['id']) ?>">默认</label></div>
                                <div class="checkbox form-row"><input id="active_<?= e((string) $plan['id']) ?>" type="checkbox" name="active" value="1" <?= $plan['active'] ? 'checked' : '' ?>><label for="active_<?= e((string) $plan['id']) ?>">启用</label></div>
                                <button class="button small" type="submit">保存</button>
                            </form>
                        </details>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
