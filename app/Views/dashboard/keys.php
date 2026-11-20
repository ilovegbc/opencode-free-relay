<?php $newKey = $newKey ?? null; ?>
<div class="section-title">
    <div>
        <span class="eyebrow"><span class="dot"></span>API Keys</span>
        <h1 style="margin-top:.7rem">密钥</h1>
        <p class="muted" style="margin:0">完整密钥只在创建时显示一次；服务端仅保存摘要。</p>
    </div>
    <a class="button ghost small" href="<?= e(url('/dashboard')) ?>">← 返回控制台</a>
</div>

<?php if (!empty($newKey)): ?>
    <div class="secret">
        <div class="small muted" style="margin-bottom:.4rem">新 Key（请立即复制）</div>
        <div class="code-block">
            <button type="button" class="copy-button" data-copy="#new-key">复制</button>
            <code id="new-key"><?= e($newKey) ?></code>
        </div>
    </div>
<?php endif; ?>

<div class="split">
    <div class="card">
        <div class="card-header"><h2>我的 Keys</h2><span class="badge"><?= e((string) count($keys)) ?></span></div>
        <?php if (empty($keys)): ?>
            <p class="muted">还没有 Key。用右侧表单创建一个。</p>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>名称</th><th>前缀</th><th>状态</th><th>最近使用</th><th>请求数</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($keys as $key): ?>
                        <tr>
                            <td><?= e($key['name']) ?></td>
                            <td class="mono small"><?= e($key['prefix']) ?>…</td>
                            <td>
                                <?php if ($key['status'] === 'active' && (empty($key['expires_at']) || strtotime((string) $key['expires_at']) > time())): ?>
                                    <span class="badge green">active</span>
                                <?php else: ?>
                                    <span class="badge red">已过期</span>
                                <?php endif; ?>
                            </td>
                            <td class="small"><?= e(time_ago($key['last_used_at'])) ?></td>
                            <td class="small"><?= e(format_number((int) $key['request_count'])) ?></td>
                            <td>
                                <form method="post" action="<?= e(url('/dashboard/keys/' . $key['id'] . '/delete')) ?>" data-confirm="删除后该 Key 立即失效且不可恢复，确定删除？" class="inline-form">
                                    <?= csrf_field() ?>
                                    <button class="button danger small" type="submit">删除</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <aside class="card">
        <h3>创建新 Key</h3>
        <form method="post" action="<?= e(url('/dashboard/keys')) ?>">
            <?= csrf_field() ?>
            <div class="form-row">
                <label for="name">名称</label>
                <input id="name" name="name" type="text" maxlength="64" placeholder="例如 laptop / cherry-studio" required>
            </div>
            <div class="form-row">
                <label for="expires_days">有效期（天，0 = 不过期）</label>
                <input id="expires_days" name="expires_days" type="number" min="0" max="3650" value="0">
            </div>
            <button class="button" type="submit" style="width:100%">生成 Key</button>
        </form>
        <p class="help" style="margin-top:1rem">建议为每个客户端单独创建 Key；不再使用或疑似泄露时直接删除，删除后立即失效。</p>
    </aside>
</div>
