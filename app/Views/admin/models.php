<?php $serving = count(array_filter($models, static fn (array $m): bool => (bool) $m['enabled'] && (bool) $m['available'])); ?>
<div class="section-title">
    <div>
        <span class="eyebrow"><span class="dot"></span>Catalog</span>
        <h1 style="margin-top:.7rem">免费模型</h1>
        <p class="muted" style="margin:0">每小时从 OpenCode 模型端点刷新，只保留零价或名称带 Free 的模型。</p>
    </div>
    <div class="button-row">
        <form method="post" action="<?= e(url('/admin/models/sync')) ?>" class="inline-form">
            <?= csrf_field() ?>
            <button class="button small" type="submit">立即同步</button>
        </form>
        <a class="button ghost small" href="<?= e(url('/admin')) ?>">← 总览</a>
    </div>
</div>

<div class="grid grid-3">
    <div class="kpi"><div class="label">免费模型</div><div class="value"><?= e((string) count($models)) ?></div><div class="hint">is_free</div></div>
    <div class="kpi"><div class="label">Serving</div><div class="value"><?= e((string) $serving) ?></div><div class="hint">enabled + available</div></div>
    <div class="kpi"><div class="label">Last sync</div><div class="value" style="font-size:1rem"><?= e(!empty($lastSync['finished_at']) ? time_ago((string) $lastSync['finished_at']) : 'never') ?></div><div class="hint"><?= e($lastSync['status'] ?? 'never') ?></div></div>
</div>

<div class="card" style="margin-top:1rem">
    <div class="card-header"><h2>模型目录</h2><span class="badge blue">free only</span></div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>模型</th><th>类型</th><th>状态</th><th>上下文</th><th>接入</th><th>操作</th></tr></thead>
            <tbody>
            <?php foreach ($models as $model): ?>
                <?php $thinking = \Relay\Services\Thinking::forModel($model); ?>
                <tr>
                    <td>
                        <strong><?= e($model['name']) ?></strong>
                        <div class="tiny mono"><?= e($model['id']) ?></div>
                        <div class="tiny muted">思考：<?= e($thinking['levels'] !== [] ? implode(' / ', $thinking['levels']) : '—') ?></div>
                    </td>
                    <td class="small"><?= e($model['type']) ?></td>
                    <td>
                        <span class="badge <?= $model['enabled'] ? 'green' : 'red' ?>"><?= $model['enabled'] ? 'enabled' : 'disabled' ?></span>
                        <span class="badge <?= $model['available'] ? 'blue' : 'warn' ?>"><?= $model['available'] ? 'available' : 'stale' ?></span>
                    </td>
                    <td class="small"><?= e($model['context_limit'] ? format_number((int) $model['context_limit']) : '—') ?><br><span class="muted">out <?= e($model['output_limit'] ? format_number((int) $model['output_limit']) : '—') ?></span></td>
                    <td class="small"><span class="badge blue"><?= e($model['upstream_kind']) ?></span><div class="tiny muted mono" style="max-width:260px;word-break:break-all"><?= e($model['upstream_base_url']) ?>/…</div></td>
                    <td>
                        <details>
                            <summary class="small">编辑</summary>
                            <form method="post" action="<?= e(url('/admin/models/' . rawurlencode((string) $model['id']))) ?>" style="margin-top:.6rem;width:100%;max-width:320px">
                                <?= csrf_field() ?>
                                <div class="checkbox form-row"><input id="m_enabled_<?= e(md5((string) $model['id'])) ?>" type="checkbox" name="enabled" value="1" <?= $model['enabled'] ? 'checked' : '' ?>><label for="m_enabled_<?= e(md5((string) $model['id'])) ?>">启用</label></div>
                                <div class="form-grid">
                                    <div class="form-row"><label>排序</label><input name="sort_order" type="number" value="<?= e((string) $model['sort_order']) ?>"></div>
                                </div>
                                <button class="button small" type="submit">保存</button>
                            </form>
                        </details>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($models)): ?><tr><td colspan="6" class="muted">还没有模型。点击“立即同步”。</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="grid grid-2" style="margin-top:1rem">
    <div class="card">
        <h2>模型别名</h2>
        <form method="post" action="<?= e(url('/admin/models/aliases')) ?>" class="form-grid">
            <?= csrf_field() ?>
            <div class="form-row"><label>别名</label><input name="alias" type="text" placeholder="opencode/big-pickle" required></div>
            <div class="form-row"><label>目标模型 ID</label><input name="model_id" type="text" placeholder="big-pickle" required></div>
            <div class="form-row full"><button class="button small" type="submit">添加 / 更新</button></div>
        </form>
        <div class="table-wrap" style="margin-top:1rem">
            <table>
                <thead><tr><th>别名</th><th>目标</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($aliases as $alias): ?>
                    <tr>
                        <td class="mono small"><?= e($alias['alias']) ?></td>
                        <td class="small"><?= e($alias['model_name']) ?> <span class="muted mono"><?= e($alias['model_id']) ?></span></td>
                        <td>
                            <form method="post" action="<?= e(url('/admin/models/aliases/' . rawurlencode((string) $alias['alias']) . '/delete')) ?>" data-confirm="删除别名？" class="inline-form">
                                <?= csrf_field() ?><button class="button danger small" type="submit">删除</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($aliases)): ?><tr><td colspan="3" class="muted small">暂无别名</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h2>同步历史</h2>
        <div class="table-wrap">
            <table>
                <thead><tr><th>开始</th><th>状态</th><th>模型</th><th>免费</th><th>变化</th></tr></thead>
                <tbody>
                <?php foreach ($syncRuns as $run): ?>
                    <tr><td class="small"><?= e(format_dt($run['started_at'], 'm-d H:i')) ?></td><td><span class="badge <?= $run['status'] === 'success' ? 'green' : ($run['status'] === 'error' ? 'red' : 'warn') ?>"><?= e($run['status']) ?></span></td><td class="small"><?= e((string) $run['total_models']) ?></td><td class="small"><?= e((string) $run['free_models']) ?></td><td class="small"><?= e((string) $run['changed_models']) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="help" style="margin-top:.8rem">Cron：<code>0 * * * * php /path/bin/cron.php models</code>。也可以将 <code>public/cron.php?token=...</code> 配给外部定时服务。</p>
    </div>
</div>
