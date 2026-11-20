<div class="section-title">
    <div>
        <span class="eyebrow"><span class="dot"></span>Plans</span>
        <h1 style="margin-top:.7rem">套餐</h1>
        <p class="muted" style="margin:0">套餐控制每日请求数、RPM、并发与可用模型。</p>
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
            <div class="form-row"><label>每日请求（-1 不限）</label><input name="daily_requests" type="number" value="200"></div>
            <div class="form-row"><label>每日 Tokens（-1 不限）</label><input name="daily_tokens" type="number" value="5000000"></div>
            <div class="form-row"><label>RPM</label><input name="rpm" type="number" min="0" value="30"></div>
            <div class="form-row"><label>并发</label><input name="concurrency" type="number" min="1" value="3"></div>
            <div class="form-row"><label>允许模型（逗号分隔，空=全部）</label><input name="allowed_models" type="text"></div>
            <div class="form-row"><label>排序</label><input name="sort_order" type="number" value="0"></div>
        </div>
        <div class="checkbox form-row"><input id="plan_default" type="checkbox" name="is_default" value="1"><label for="plan_default">设为默认套餐</label></div>
        <div class="checkbox form-row"><input id="plan_active" type="checkbox" name="active" value="1" checked><label for="plan_active">启用</label></div>
        <button class="button" type="submit">创建套餐</button>
    </form>
</div>

<div class="card" style="margin-top:1rem">
    <div class="card-header">
        <h2>现有套餐</h2>
        <span class="badge blue"><?= e((string) count($plans)) ?></span>
    </div>
    <div class="plan-list">
        <?php foreach ($plans as $plan): ?>
            <?php
            $busy = (int) ($plan['user_count'] ?? 0) > 0;
            $isDefault = !empty($plan['is_default']);
            $locked = $busy || $isDefault;
            ?>
            <div class="plan">
                <div class="plan-head">
                    <div class="plan-title">
                        <div class="tiny mono muted"><?= e($plan['code']) ?></div>
                        <h3><?= e($plan['name']) ?></h3>
                        <?php if (trim((string) ($plan['description'] ?? '')) !== ''): ?>
                            <p class="small muted" style="margin:0"><?= e($plan['description']) ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="plan-badges">
                        <?php if ($isDefault): ?><span class="badge green">default</span><?php endif; ?>
                        <span class="badge <?= $plan['active'] ? 'green' : 'red' ?>"><?= $plan['active'] ? 'active' : 'off' ?></span>
                    </div>
                </div>

                <dl class="plan-meta">
                    <div>
                        <dt>每日请求</dt>
                        <dd><?= (int) $plan['daily_requests'] < 0 ? '∞' : e(format_number((int) $plan['daily_requests'])) ?></dd>
                    </div>
                    <div>
                        <dt>每日 Tokens</dt>
                        <dd><?= (int) $plan['daily_tokens'] < 0 ? '∞' : e(format_number((int) $plan['daily_tokens'])) ?></dd>
                    </div>
                    <div>
                        <dt>RPM / 并发</dt>
                        <dd><?= e((string) $plan['rpm']) ?> / <?= e((string) $plan['concurrency']) ?></dd>
                    </div>
                    <div>
                        <dt>用户</dt>
                        <dd><?= e((string) ($plan['user_count'] ?? 0)) ?></dd>
                    </div>
                </dl>

                <div class="plan-actions">
                    <details class="plan-edit">
                        <summary class="button ghost small">编辑</summary>
                        <form method="post" action="<?= e(url('/admin/plans/' . $plan['id'])) ?>">
                            <?= csrf_field() ?>
                            <div class="form-grid">
                                <div class="form-row"><label>Code</label><input name="code" type="text" value="<?= e($plan['code']) ?>" required></div>
                                <div class="form-row"><label>名称</label><input name="name" type="text" value="<?= e($plan['name']) ?>" required></div>
                                <div class="form-row full"><label>描述</label><input name="description" type="text" value="<?= e($plan['description']) ?>"></div>
                                <div class="form-row"><label>每日请求</label><input name="daily_requests" type="number" value="<?= e((string) $plan['daily_requests']) ?>"></div>
                                <div class="form-row"><label>每日 Tokens</label><input name="daily_tokens" type="number" value="<?= e((string) $plan['daily_tokens']) ?>"></div>
                                <div class="form-row"><label>RPM</label><input name="rpm" type="number" min="0" value="<?= e((string) $plan['rpm']) ?>"></div>
                                <div class="form-row"><label>并发</label><input name="concurrency" type="number" min="1" value="<?= e((string) $plan['concurrency']) ?>"></div>
                                <div class="form-row"><label>排序</label><input name="sort_order" type="number" value="<?= e((string) $plan['sort_order']) ?>"></div>
                                <div class="form-row full"><label>允许模型（逗号分隔，空=全部）</label><input name="allowed_models" type="text" value="<?= e(implode(',', pg_text_array($plan['allowed_models'] ?? null) ?? [])) ?>"></div>
                            </div>
                            <div class="checkbox form-row"><input id="default_<?= e((string) $plan['id']) ?>" type="checkbox" name="is_default" value="1" <?= $plan['is_default'] ? 'checked' : '' ?>><label for="default_<?= e((string) $plan['id']) ?>">默认</label></div>
                            <div class="checkbox form-row"><input id="active_<?= e((string) $plan['id']) ?>" type="checkbox" name="active" value="1" <?= $plan['active'] ? 'checked' : '' ?>><label for="active_<?= e((string) $plan['id']) ?>">启用</label></div>
                            <button class="button small" type="submit">保存</button>
                        </form>
                    </details>

                    <form method="post" action="<?= e(url('/admin/plans/' . $plan['id'] . '/delete')) ?>" class="inline-form" onsubmit="return confirm('确定删除该套餐？此操作不可恢复。')">
                        <?= csrf_field() ?>
                        <button class="button ghost small danger" type="submit" <?= $locked ? 'disabled' : '' ?> title="<?= $isDefault ? '默认套餐不能删除' : ($busy ? '仍有用户使用该套餐' : '删除套餐') ?>">删除</button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
