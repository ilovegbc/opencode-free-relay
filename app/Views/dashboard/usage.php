<?php
$max = 1;
foreach ($series as $row) { $max = max($max, (int) $row['requests']); }
?>
<div class="section-title">
    <div>
        <span class="eyebrow"><span class="dot"></span>Usage</span>
        <h1 style="margin-top:.7rem">用量明细</h1>
        <p class="muted" style="margin:0">请求、Token、出口 IPv6 与延迟均可在日志中审计。</p>
    </div>
    <a class="button ghost small" href="<?= e(url('/dashboard')) ?>">← 返回控制台</a>
</div>

<div class="card">
    <div class="card-header"><h2>最近 14 天请求</h2><span class="badge blue">UTC</span></div>
    <?php if (empty($series)): ?>
        <p class="muted">还没有聚合数据。</p>
    <?php else: ?>
        <div class="bars">
            <?php foreach ($series as $row): ?>
                <div class="bar" style="height:<?= e((string) max(2, round((int) $row['requests'] / $max * 100))) ?>%" data-label="<?= e($row['day']) ?> · <?= e((string) $row['requests']) ?> req"></div>
            <?php endforeach; ?>
        </div>
        <div class="tiny muted" style="display:flex;justify-content:space-between;margin-top:.4rem"><span><?= e($series[0]['day']) ?></span><span><?= e($series[count($series) - 1]['day']) ?></span></div>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-header"><h2>请求日志</h2><span class="badge"><?= e((string) count($logs)) ?> rows</span></div>
    <?php if (empty($logs)): ?>
        <p class="muted">暂无记录。</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th>时间</th><th>模型</th><th>端点</th><th>状态</th><th>出口 IPv6</th><th>Tokens</th><th>积分</th><th>延迟</th></tr></thead>
                <tbody>
                <?php foreach ($logs as $log): ?>
                    <tr>
                        <td class="small nowrap"><?= e(format_dt($log['created_at'], 'm-d H:i:s')) ?></td>
                        <td class="small mono"><?= e($log['model_id'] ?? '—') ?></td>
                        <td class="small"><?= e($log['endpoint']) ?></td>
                        <td><span class="badge <?= (int) $log['status_code'] >= 200 && (int) $log['status_code'] < 300 ? 'green' : 'red' ?>"><?= e((string) $log['status_code']) ?></span></td>
                        <td class="small mono"><?= e($log['ip_address'] ?? '—') ?></td>
                        <td class="small"><?= e(format_number((int) $log['total_tokens'])) ?></td>
                        <td class="small"><?= e(format_credits($log['credits'])) ?></td>
                        <td class="small"><?= e((string) $log['latency_ms']) ?>ms</td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
        $base = url('/dashboard/usage');
        $query = [];
        require BASE_PATH . '/app/Views/partials/pagination.php';
        ?>
    <?php endif; ?>
</div>
