<div class="section-title">
    <div>
        <span class="eyebrow"><span class="dot"></span>Diagnostics</span>
        <h1 style="margin-top:.7rem">诊断</h1>
        <p class="muted" style="margin:0">部署自检与 IPv6 源绑定探针。探针不发送模型请求。</p>
    </div>
    <a class="button ghost small" href="<?= e(url('/admin/pools')) ?>">管理地址池</a>
</div>

<div class="card">
    <h2>环境检查</h2>
    <div class="table-wrap">
        <table>
            <thead><tr><th>检查项</th><th>结果</th><th>详情</th></tr></thead>
            <tbody>
            <?php foreach ($checks as $check): ?>
                <tr>
                    <td><?= e($check['name']) ?></td>
                    <td><span class="badge <?= $check['ok'] ? 'green' : 'red' ?>"><?= $check['ok'] ? 'OK' : 'FAIL' ?></span></td>
                    <td class="small muted"><?= e((string) $check['detail']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <div class="card-header"><h2>IPv6 池</h2><span class="badge">probe URL: <?= e($probeUrl) ?></span></div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>名称</th><th>CIDR</th><th>主机位</th><th>容量</th><th>状态</th><th>租约</th><th>最近错误</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($pools as $pool): ?>
                <tr>
                    <td><?= e($pool['name']) ?></td>
                    <td class="mono small"><?= e($pool['cidr']) ?></td>
                    <td class="small"><?= e((string) ($pool['host_bits'] ?? '?')) ?></td>
                    <td class="small mono"><?= e(mb_strimwidth((string) ($pool['capacity'] ?? '?'), 0, 32, '…')) ?></td>
                    <td><span class="badge <?= $pool['status'] === 'healthy' ? 'green' : ($pool['status'] === 'degraded' ? 'warn' : '') ?>"><?= e($pool['status']) ?></span></td>
                    <td class="small"><?= e(format_number((int) ($pool['active_leases'] ?? 0))) ?></td>
                    <td class="small" style="color:var(--danger)"><?= e(mb_substr((string) ($pool['last_error'] ?? ''), 0, 80)) ?></td>
                    <td>
                        <form method="post" action="<?= e(url('/admin/diagnostics/pools/' . $pool['id'] . '/probe')) ?>" class="inline-form">
                            <?= csrf_field() ?><button class="button ghost small" type="submit">探针</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($pools)): ?><tr><td colspan="8" class="muted">没有地址池。先到 <a href="<?= e(url('/admin/pools')) ?>">IPv6 池</a> 添加。</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <h2>命令行</h2>
    <p class="muted small">生产服务器上建议执行：</p>
    <pre><code>php bin/doctor.php
php bin/doctor.php --probe
php bin/cron.php all

# 每小时刷新免费模型
0 * * * * php /path/to/relay/bin/cron.php models

# 每天清理日志/租约
30 3 * * * php /path/to/relay/bin/cron.php cleanup</code></pre>
</div>
