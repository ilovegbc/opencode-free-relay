<div class="section-title">
    <div>
        <span class="eyebrow"><span class="dot"></span>Audit</span>
        <h1 style="margin-top:.7rem">审计日志</h1>
        <p class="muted" style="margin:0">管理员操作与安全相关事件。</p>
    </div>
    <a class="button ghost small" href="<?= e(url('/admin/logs')) ?>">← 请求日志</a>
</div>
<div class="card">
    <div class="table-wrap">
        <table>
            <thead><tr><th>时间</th><th>操作者</th><th>动作</th><th>目标</th><th>详情</th><th>IP</th></tr></thead>
            <tbody>
            <?php foreach ($logs as $log): ?>
                <tr>
                    <td class="small nowrap"><?= e(format_dt($log['created_at'], 'm-d H:i:s')) ?></td>
                    <td class="small"><?= e($log['username'] ?? 'system') ?></td>
                    <td class="small mono"><?= e($log['action']) ?></td>
                    <td class="small"><?= e($log['target_type']) ?><?= $log['target_id'] !== '' ? ':' . e($log['target_id']) : '' ?></td>
                    <td class="small mono muted" style="max-width:420px;word-break:break-all"><?= e(mb_substr((string) $log['details'], 0, 220)) ?></td>
                    <td class="small mono"><?= e($log['ip'] ?? '—') ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($logs)): ?><tr><td colspan="6" class="muted">暂无审计记录。</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
    $base = url('/admin/audit');
    $query = [];
    require BASE_PATH . '/app/Views/partials/pagination.php';
    ?>
</div>
