<div class="section-title">
    <div>
        <span class="eyebrow"><span class="dot"></span>Observability</span>
        <h1 style="margin-top:.7rem">请求日志</h1>
        <p class="muted" style="margin:0">包含每次上游尝试的出口 IPv6、状态、Token 与延迟。</p>
    </div>
    <a class="button ghost small" href="<?= e(url('/admin/audit')) ?>">审计日志 →</a>
</div>

<div class="card">
    <form method="get" action="<?= e(url('/admin/logs')) ?>" class="toolbar" style="margin-bottom:1rem">
        <div class="button-row">
            <input type="text" name="model" value="<?= e($filters['model'] ?? '') ?>" placeholder="model id">
            <input type="text" name="user_id" value="<?= e($filters['user_id'] ?? '') ?>" placeholder="user id">
            <input type="text" name="ip" value="<?= e($filters['ip'] ?? '') ?>" placeholder="exit IPv6">
            <select name="status">
                <option value="">any status</option>
                <?php foreach ([200, 400, 401, 403, 429, 500, 502, 503] as $status): ?>
                    <option value="<?= e((string) $status) ?>" <?= (string) ($filters['status'] ?? '') === (string) $status ? 'selected' : '' ?>><?= e((string) $status) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="button-row">
            <button class="button small" type="submit">筛选</button>
            <a class="button ghost small" href="<?= e(url('/admin/logs')) ?>">重置</a>
        </div>
    </form>

    <div class="table-wrap">
        <table>
            <thead><tr><th>时间</th><th>用户</th><th>模型</th><th>状态</th><th>上游</th><th>出口 IPv6</th><th>尝试</th><th>Tokens</th><th>积分</th><th>延迟</th><th>错误</th></tr></thead>
            <tbody>
            <?php foreach ($logs as $log): ?>
                <tr>
                    <td class="small nowrap"><?= e(format_dt($log['created_at'], 'm-d H:i:s')) ?></td>
                    <td class="small"><?= e($log['username'] ?? ('#' . ($log['user_id'] ?? '—'))) ?></td>
                    <td class="small mono"><?= e($log['model_id'] ?? '—') ?></td>
                    <td><span class="badge <?= (int) $log['status_code'] >= 200 && (int) $log['status_code'] < 300 ? 'green' : 'red' ?>"><?= e((string) $log['status_code']) ?></span></td>
                    <td class="small"><?= e((string) $log['upstream_status']) ?></td>
                    <td class="small mono"><?= e($log['ip_address'] ?? '—') ?></td>
                    <td class="small"><?= e((string) $log['attempt_count']) ?></td>
                    <td class="small"><?= e(format_number((int) $log['total_tokens'])) ?></td>
                    <td class="small"><?= e(format_credits($log['credits'])) ?></td>
                    <td class="small"><?= e((string) $log['latency_ms']) ?>ms</td>
                    <td class="small muted"><?= e(mb_substr((string) ($log['error_code'] ?? ''), 0, 40)) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($logs)): ?><tr><td colspan="11" class="muted">没有匹配的记录。</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
    $base = url('/admin/logs');
    $query = $filters;
    require BASE_PATH . '/app/Views/partials/pagination.php';
    ?>
</div>
