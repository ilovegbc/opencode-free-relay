<?php
$max = 1;
foreach ($series as $row) { $max = max($max, (int) $row['requests']); }
?>
<div class="section-title">
    <div>
        <span class="eyebrow"><span class="dot"></span>Operations</span>
        <h1 style="margin-top:.7rem">总览</h1>
        <p class="muted" style="margin:0">用户、额度、IPv6 池与模型同步的核心指标。</p>
    </div>
    <div class="button-row">
        <a class="button ghost small" href="<?= e(url('/admin/diagnostics')) ?>">运行诊断</a>
        <a class="button ghost small" href="<?= e(url('/admin/models')) ?>">模型目录</a>
    </div>
</div>

<div class="grid grid-4">
    <div class="kpi"><div class="label">Users</div><div class="value"><?= e(format_number((int) ($userStats['users'] ?? 0))) ?></div><div class="hint"><?= e(format_number((int) ($userStats['active_users'] ?? 0))) ?> active · +<?= e(format_number((int) ($userStats['new_users_24h'] ?? 0))) ?> 24h</div></div>
    <div class="kpi"><div class="label">Requests today</div><div class="value"><?= e(format_number((int) ($requestStats['today_requests'] ?? 0))) ?></div><div class="hint"><?= e(format_number((int) ($requestStats['today_tokens'] ?? 0))) ?> tokens</div></div>
    <div class="kpi"><div class="label">Free models</div><div class="value"><?= e((string) $freeModels) ?></div><div class="hint">last sync <?= e(!empty($lastSync['finished_at']) ? time_ago((string) $lastSync['finished_at']) : 'never') ?></div></div>
    <div class="kpi"><div class="label">IPv6 pools</div><div class="value"><?= e((string) ($poolStats['enabled_pools'] ?? 0)) ?></div><div class="hint"><?= e((string) ($poolStats['healthy_pools'] ?? 0)) ?> healthy · <?= e(format_number((int) ($poolStats['active_leases'] ?? 0))) ?> leases</div></div>
</div>

<div class="split" style="margin-top:1rem">
    <div>
        <div class="card">
            <div class="card-header"><h2>14 天请求趋势</h2><span class="badge blue">UTC</span></div>
            <?php if (empty($series)): ?>
                <p class="muted">还没有聚合数据。</p>
            <?php else: ?>
                <div class="bars">
                    <?php foreach ($series as $row): ?>
                        <div class="bar" style="height:<?= e((string) max(2, round((int) $row['requests'] / $max * 100))) ?>%" data-label="<?= e($row['day']) ?> · <?= e((string) $row['requests']) ?> req"></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <div class="stat-grid" style="margin-top:1rem">
                <div class="stat"><span>Success</span><b><?= e(format_number((int) ($requestStats['ok_requests'] ?? 0))) ?></b></div>
                <div class="stat"><span>Failed</span><b><?= e(format_number((int) ($requestStats['failed_requests'] ?? 0))) ?></b></div>
                <div class="stat"><span>Avg latency</span><b><?= e((string) (int) ($requestStats['avg_latency'] ?? 0)) ?>ms</b></div>
                <div class="stat"><span>Total requests</span><b><?= e(format_number((int) ($requestStats['total_requests'] ?? 0))) ?></b></div>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h2>最近模型同步</h2><a class="button ghost small" href="<?= e(url('/admin/models')) ?>">管理</a></div>
            <?php if (empty($syncRuns)): ?>
                <p class="muted">还没有同步记录。请运行 <code>php bin/cron.php models</code> 或点击后台“立即同步”。</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>开始</th><th>状态</th><th>模型</th><th>免费</th><th>变化</th><th>错误</th></tr></thead>
                        <tbody>
                        <?php foreach ($syncRuns as $run): ?>
                            <tr>
                                <td class="small"><?= e(format_dt($run['started_at'], 'm-d H:i:s')) ?></td>
                                <td><span class="badge <?= $run['status'] === 'success' ? 'green' : ($run['status'] === 'error' ? 'red' : 'warn') ?>"><?= e($run['status']) ?></span></td>
                                <td class="small"><?= e((string) $run['total_models']) ?></td>
                                <td class="small"><?= e((string) $run['free_models']) ?></td>
                                <td class="small"><?= e((string) $run['changed_models']) ?></td>
                                <td class="small muted"><?= e(mb_substr((string) ($run['error'] ?? ''), 0, 80)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <aside>
        <div class="card">
            <h3>快捷入口</h3>
            <div class="button-row" style="flex-direction:column;align-items:stretch">
                <a class="button ghost small" href="<?= e(url('/admin/users')) ?>">用户管理</a>
                <a class="button ghost small" href="<?= e(url('/admin/pools')) ?>">添加 IPv6 池</a>
                <a class="button ghost small" href="<?= e(url('/admin/settings')) ?>">系统设置</a>
                <a class="button ghost small" href="<?= e(url('/admin/logs')) ?>">请求日志</a>
            </div>
        </div>
        <div class="card">
            <h3>同步健康</h3>
            <p class="small muted">最近状态：<span class="badge <?= ($lastSync['status'] ?? '') === 'success' ? 'green' : 'warn' ?>"><?= e($lastSync['status'] ?? 'never') ?></span></p>
            <?php if (!empty($lastSync['error'])): ?><p class="small" style="color:var(--danger)"><?= e($lastSync['error']) ?></p><?php endif; ?>
            <p class="help">Cron 示例：<code>0 * * * * php /path/bin/cron.php models</code></p>
        </div>
        <div class="card">
            <h3>公告</h3>
            <?php if (empty($announcements)): ?>
                <p class="muted small">暂无公告。</p>
            <?php else: ?>
                <?php foreach (array_slice($announcements, 0, 3) as $announcement): ?>
                    <p class="small"><span class="badge <?= e($announcement['level']) ?>"><?= e($announcement['level']) ?></span> <?= e($announcement['title']) ?></p>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </aside>
</div>
