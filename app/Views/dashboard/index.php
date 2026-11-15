<?php
$dailyReq = isset($plan['daily_requests']) ? (int) $plan['daily_requests'] : -1;
$dailyTok = isset($plan['daily_tokens']) ? (int) $plan['daily_tokens'] : -1;
$usedReq = (int) ($usage['requests'] ?? 0);
$usedTok = (int) ($usage['input_tokens'] ?? 0) + (int) ($usage['output_tokens'] ?? 0);
$keyCount = count($keys ?? []);
$base = rtrim((string) cfg('app.url', ''), '/') . '/v1';
$exampleModel = (string) ($models[0]['id'] ?? 'big-pickle');
$curl = "curl {$base}/chat/completions \\\n  -H \"Authorization: Bearer sk-oc-...\" \\\n  -H \"Content-Type: application/json\" \\\n  -d '{\"model\":\"{$exampleModel}\",\"messages\":[{\"role\":\"user\",\"content\":\"hi\"}],\"stream\":false}'";
?>
<div class="section-title">
    <div>
        <span class="eyebrow"><span class="dot"></span>Console</span>
        <h1 style="margin-top:.7rem">你好，<?= e($user['username']) ?></h1>
        <p class="muted" style="margin:0">管理 Key、查看用量，或直接开始调用免费模型。</p>
    </div>
    <div class="button-row">
        <a class="button ghost small" href="<?= e(url('/dashboard/usage')) ?>">用量明细</a>
        <a class="button small" href="<?= e(url('/dashboard/keys')) ?>">创建 API Key</a>
    </div>
</div>

<?php if (!empty($announcements)): ?>
    <?php foreach ($announcements as $announcement): ?>
        <div class="alert <?= e($announcement['level'] ?? 'info') ?>">
            <strong><?= e($announcement['title']) ?></strong>
            <div class="small"><?= nl2br(e($announcement['body'])) ?></div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<div class="grid grid-4" style="margin-bottom:1rem">
    <div class="kpi">
        <div class="label">今日请求</div>
        <div class="value"><?= e(format_number($usedReq)) ?><?= $dailyReq >= 0 ? ' / ' . e(format_number($dailyReq)) : '' ?></div>
        <div class="hint"><?= $dailyReq >= 0 ? e((string) round(percent($usedReq, $dailyReq))) . '% of daily quota' : 'unlimited requests' ?></div>
        <div class="progress" style="margin-top:.55rem"><span style="width:<?= $dailyReq > 0 ? e((string) percent($usedReq, $dailyReq)) : '0' ?>%"></span></div>
    </div>
    <div class="kpi">
        <div class="label">今日 Tokens</div>
        <div class="value"><?= e(format_number($usedTok)) ?><?= $dailyTok >= 0 ? ' / ' . e(format_number($dailyTok)) : '' ?></div>
        <div class="hint"><?= $dailyTok >= 0 ? e((string) round(percent($usedTok, $dailyTok))) . '% of daily tokens' : 'unlimited tokens' ?></div>
        <div class="progress" style="margin-top:.55rem"><span style="width:<?= $dailyTok > 0 ? e((string) percent($usedTok, $dailyTok)) : '0' ?>%"></span></div>
    </div>
    <div class="kpi">
        <div class="label">积分余额</div>
        <div class="value"><?= e(format_credits($user['balance'] ?? 0)) ?></div>
        <div class="hint">套餐：<?= e($plan['name'] ?? '未分配') ?> · <?= e((string) ($plan['rpm'] ?? 0)) ?> RPM · <?= e((string) ($plan['concurrency'] ?? 0)) ?> 并发</div>
    </div>
    <div class="kpi">
        <div class="label">API Keys</div>
        <div class="value"><?= e((string) $keyCount) ?></div>
        <div class="hint">活跃密钥数量</div>
        <div class="button-row" style="margin-top:.55rem">
            <a class="button ghost small" href="<?= e(url('/dashboard/keys')) ?>">管理</a>
        </div>
    </div>
</div>

<div class="split">
    <div>
        <div class="card">
            <div class="card-header">
                <div>
                    <h2>快速开始</h2>
                    <p class="muted small" style="margin:0">Base URL：<code><?= e($base) ?></code></p>
                </div>
                <a class="button ghost small" href="<?= e(url('/docs')) ?>">完整文档</a>
            </div>
            <div class="code-block">
                <button type="button" class="copy-button" data-copy="#curl-example">复制</button>
                <pre id="curl-example"><code><?= e($curl) ?></code></pre>
            </div>
            <p class="muted small" style="margin:0">把 <code>sk-oc-...</code> 换成控制台创建的 Key。模型名可从 <a href="<?= e(url('/v1/models')) ?>" target="_blank" rel="noopener">/v1/models</a> 获取。</p>
        </div>

        <div class="card">
            <div class="card-header">
                <h2>最近请求</h2>
                <a class="button ghost small" href="<?= e(url('/dashboard/usage')) ?>">全部</a>
            </div>
            <?php if (empty($logs)): ?>
                <p class="muted">还没有请求记录。</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>时间</th><th>模型</th><th>状态</th><th>IP</th><th>Tokens</th><th>耗时</th></tr></thead>
                        <tbody>
                        <?php foreach ($logs as $log): ?>
                            <tr>
                                <td class="small nowrap"><?= e(format_dt($log['created_at'], 'm-d H:i:s')) ?></td>
                                <td class="small mono"><?= e($log['model_id'] ?? '—') ?></td>
                                <td><span class="badge <?= (int) $log['status_code'] >= 200 && (int) $log['status_code'] < 300 ? 'green' : 'red' ?>"><?= e((string) $log['status_code']) ?></span></td>
                                <td class="small mono"><?= e($log['ip_address'] ?? '—') ?></td>
                                <td class="small"><?= e(format_number((int) $log['total_tokens'])) ?></td>
                                <td class="small"><?= e((string) $log['latency_ms']) ?>ms</td>
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
            <div class="card-header"><h3>可用免费模型</h3><span class="badge green"><?= e((string) count($models)) ?></span></div>
            <?php if (empty($models)): ?>
                <p class="muted small">模型目录尚未同步。管理员可在后台点击“立即同步”。</p>
            <?php else: ?>
                <div class="tag-list">
                    <?php foreach (array_slice($models, 0, 12) as $model): ?>
                        <span class="badge blue" title="<?= e($model['name']) ?>"><?= e($model['id']) ?></span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <div class="card">
            <div class="card-header"><h3>快捷操作</h3></div>
            <div class="button-row" style="flex-direction:column;align-items:stretch">
                <a class="button ghost small" href="<?= e(url('/dashboard/keys')) ?>">创建 / 吊销 Key</a>
                <a class="button ghost small" href="<?= e(url('/dashboard/redeem')) ?>">兑换积分</a>
                <a class="button ghost small" href="<?= e(url('/dashboard/settings')) ?>">修改密码</a>
            </div>
        </div>
        <div class="card">
            <h3>当前套餐</h3>
            <p class="small muted" style="margin-bottom:.5rem"><?= e($plan['description'] ?? '由管理员分配') ?></p>
            <div class="tiny muted">
                每日请求：<?= $dailyReq < 0 ? '不限' : e(format_number($dailyReq)) ?><br>
                每日 Tokens：<?= $dailyTok < 0 ? '不限' : e(format_number($dailyTok)) ?><br>
                速率：<?= e((string) ($plan['rpm'] ?? 0)) ?> RPM<br>
                并发：<?= e((string) ($plan['concurrency'] ?? 0)) ?>
            </div>
        </div>
    </aside>
</div>
