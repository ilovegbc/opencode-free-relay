<?php
use Relay\Support\Ipv6;
?>
<div class="section-title">
    <div>
        <span class="eyebrow"><span class="dot"></span>IPv6</span>
        <h1 style="margin-top:.7rem">IPv6 地址池</h1>
        <p class="muted" style="margin:0">支持任意前缀长度的自定义 CIDR。代码不会假设 /48、/56 或 /64。</p>
    </div>
    <a class="button ghost small" href="<?= e(url('/admin/diagnostics')) ?>">诊断 / 探针</a>
</div>

<div class="grid grid-2">
    <div class="card">
        <h2>添加地址池</h2>
        <form method="post" action="<?= e(url('/admin/pools')) ?>">
            <?= csrf_field() ?>
            <div class="form-row"><label>CIDR</label><input name="cidr" type="text" placeholder="例如 2001:db8:1234::/48" required><div class="help">仅 IPv6；接受 /1–/128 的任意前缀。不要填写保留/组播地址。</div></div>
            <div class="form-grid">
                <div class="form-row"><label>名称</label><input name="name" type="text" placeholder="custom range"></div>
                <div class="form-row"><label>权重</label><input name="weight" type="number" min="1" value="100"></div>
                <div class="form-row"><label>租约 TTL（秒）</label><input name="lease_ttl_seconds" type="number" min="0" value="604800"><div class="help">0 = 约 100 年不回收。</div></div>
                <div class="form-row"><label>网卡（可选）</label><input name="interface_name" type="text" placeholder="eth0 / ens3"></div>
            </div>
            <div class="form-row"><label>备注</label><textarea name="notes" placeholder="上游提供商、NDP 配置、路由说明……"></textarea></div>
            <div class="checkbox form-row"><input id="pool_enabled" type="checkbox" name="enabled" value="1" checked><label for="pool_enabled">启用</label></div>
            <button class="button" type="submit">创建地址池</button>
        </form>
    </div>
    <div class="card">
        <h2>部署检查</h2>
        <p class="desc">每个请求会从池中生成新地址并绑定为源地址。主机需要满足：</p>
        <ul class="points">
            <li>Provider 将整个 IPv6 段路由到本机（或 NDP 代理可用）。</li>
            <li><code>sysctl -w net.ipv6.ip_nonlocal_bind=1</code></li>
            <li>/64 场景通常需要 <code>ndppd</code> 或等价 NDP 代理。</li>
            <li>防火墙允许该段的出站连接。</li>
        </ul>
        <p class="help">探针 URL：<code><?= e($probeUrl) ?></code>。探针只做 TCP 连接，不消耗模型额度。</p>
    </div>
</div>

<div class="card" style="margin-top:1rem">
    <div class="card-header"><h2>已配置地址池</h2><span class="badge"><?= e((string) count($pools)) ?></span></div>
    <?php if (empty($pools)): ?>
        <p class="muted">还没有地址池。添加后 API 才会开始工作。</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th>名称</th><th>CIDR</th><th>主机位</th><th>状态</th><th>租约</th><th>权重</th><th>最近检查</th><th>操作</th></tr></thead>
                <tbody>
                <?php foreach ($pools as $pool):
                    $prefix = 128;
                    try { $prefix = Ipv6::parseCidr((string) $pool['cidr'])['prefix']; } catch (Throwable) {}
                    ?>
                    <tr>
                        <td>
                            <strong><?= e($pool['name']) ?></strong>
                            <?php if (!empty($pool['notes'])): ?><div class="tiny muted"><?= e(mb_substr((string) $pool['notes'], 0, 60)) ?></div><?php endif; ?>
                        </td>
                        <td class="mono small"><?= e($pool['cidr']) ?></td>
                        <td class="small">/<?= e((string) $prefix) ?> · <?= e((string) Ipv6::hostBits($prefix)) ?> bits</td>
                        <td>
                            <span class="badge <?= $pool['status'] === 'healthy' ? 'green' : ($pool['status'] === 'degraded' ? 'warn' : '') ?>"><?= e($pool['status']) ?></span>
                            <?php if (!$pool['enabled']): ?><span class="badge red">disabled</span><?php endif; ?>
                            <?php if (!empty($pool['last_error'])): ?><div class="tiny" style="color:var(--danger)"><?= e(mb_substr((string) $pool['last_error'], 0, 80)) ?></div><?php endif; ?>
                        </td>
                        <td class="small"><?= e(format_number((int) ($pool['active_leases'] ?? 0))) ?></td>
                        <td class="small"><?= e((string) $pool['weight']) ?></td>
                        <td class="small"><?= e(time_ago($pool['last_checked_at'])) ?></td>
                        <td>
                            <div class="button-row">
                                <form method="post" action="<?= e(url('/admin/pools/' . $pool['id'] . '/test')) ?>" class="inline-form">
                                    <?= csrf_field() ?><button class="button ghost small" type="submit">探针</button>
                                </form>
                                <form method="post" action="<?= e(url('/admin/pools/' . $pool['id'] . '/delete')) ?>" data-confirm="删除该池及其租约？" class="inline-form">
                                    <?= csrf_field() ?><button class="button danger small" type="submit">删除</button>
                                </form>
                            </div>
                            <details style="margin-top:.5rem">
                                <summary class="small">编辑</summary>
                                <form method="post" action="<?= e(url('/admin/pools/' . $pool['id'])) ?>" style="margin-top:.6rem">
                                    <?= csrf_field() ?>
                                    <div class="form-row"><label>CIDR</label><input name="cidr" type="text" value="<?= e($pool['cidr']) ?>" required></div>
                                    <div class="form-row"><label>名称</label><input name="name" type="text" value="<?= e($pool['name']) ?>"></div>
                                    <div class="form-grid">
                                        <div class="form-row"><label>权重</label><input name="weight" type="number" min="1" value="<?= e((string) $pool['weight']) ?>"></div>
                                        <div class="form-row"><label>TTL 秒</label><input name="lease_ttl_seconds" type="number" min="0" value="<?= e((string) $pool['lease_ttl_seconds']) ?>"></div>
                                    </div>
                                    <div class="form-row"><label>网卡</label><input name="interface_name" type="text" value="<?= e($pool['interface_name'] ?? '') ?>"></div>
                                    <div class="form-row"><label>备注</label><textarea name="notes"><?= e($pool['notes'] ?? '') ?></textarea></div>
                                    <div class="checkbox form-row"><input id="enabled_<?= e((string) $pool['id']) ?>" type="checkbox" name="enabled" value="1" <?= $pool['enabled'] ? 'checked' : '' ?>><label for="enabled_<?= e((string) $pool['id']) ?>">启用</label></div>
                                    <button class="button small" type="submit">保存</button>
                                </form>
                            </details>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
