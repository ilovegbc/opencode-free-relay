<?php
$ok = $dbOk && count($models) > 0 && (int) ($pool['enabled_pools'] ?? 0) > 0;
?>
<div class="section-title">
    <div>
        <span class="eyebrow"><span class="dot"></span>Status</span>
        <h1 style="margin-top:.7rem">运行状态</h1>
        <p class="muted" style="margin:0">不含密钥、IP 地址或用户数据。</p>
    </div>
    <span class="badge <?= $ok ? 'green' : 'red' ?>"><span class="status-dot <?= $ok ? 'ok' : 'bad' ?>"></span><?= $ok ? 'Operational' : 'Degraded' ?></span>
</div>

<div class="grid grid-4">
    <div class="kpi"><div class="label">数据库</div><div class="value"><?= $dbOk ? 'OK' : 'DOWN' ?></div><div class="hint">PostgreSQL</div></div>
    <div class="kpi"><div class="label">免费模型</div><div class="value"><?= e((string) count($models)) ?></div><div class="hint">enabled + available</div></div>
    <div class="kpi"><div class="label">IPv6 池</div><div class="value"><?= e((string) ($pool['enabled_pools'] ?? 0)) ?></div><div class="hint"><?= e((string) ($pool['healthy_pools'] ?? 0)) ?> healthy</div></div>
    <div class="kpi"><div class="label">今日请求</div><div class="value"><?= e(format_number((int) ($stats['today_requests'] ?? 0))) ?></div><div class="hint"><?= e(format_number((int) ($stats['today_tokens'] ?? 0))) ?> tokens</div></div>
</div>

<div class="grid grid-2" style="margin-top:1rem">
    <div class="card">
        <div class="card-header"><h2>模型目录</h2><span class="badge blue">hourly</span></div>
        <p class="small muted">最近同步：<?= e(!empty($lastSync['finished_at']) ? format_dt($lastSync['finished_at']) : '尚未同步') ?></p>
        <p class="small muted">同步状态：<span class="badge <?= ($lastSync['status'] ?? '') === 'success' ? 'green' : 'warn' ?>"><?= e($lastSync['status'] ?? 'never') ?></span></p>
        <?php if (!empty($lastSync['error'])): ?><p class="small" style="color:var(--danger)"><?= e($lastSync['error']) ?></p><?php endif; ?>
        <?php if (!empty($models)): ?>
            <div class="tag-list">
                <?php foreach ($models as $model): ?><span class="badge"><?= e($model['id']) ?></span><?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <div class="card">
        <div class="card-header"><h2>请求质量</h2></div>
        <div class="stat-grid">
            <div class="stat"><span>Success</span><b><?= e(format_number((int) ($stats['ok_requests'] ?? 0))) ?></b></div>
            <div class="stat"><span>Failed</span><b><?= e(format_number((int) ($stats['failed_requests'] ?? 0))) ?></b></div>
            <div class="stat"><span>Avg latency</span><b><?= e((string) (int) ($stats['avg_latency'] ?? 0)) ?>ms</b></div>
            <div class="stat"><span>Active leases</span><b><?= e(format_number((int) ($pool['active_leases'] ?? 0))) ?></b></div>
        </div>
    </div>
</div>

<div class="card" style="margin-top:1rem">
    <h2>部署检查</h2>
    <p class="muted small">本页只显示汇总。完整检查请在服务器执行 <code>php bin/doctor.php</code>，或管理员访问 <a href="<?= e(url('/admin/diagnostics')) ?>">诊断页</a>。</p>
</div>
