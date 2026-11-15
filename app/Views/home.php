<?php
$modelCount = count($models ?? []);
$poolCount = (int) ($pool['enabled_pools'] ?? 0);
$leaseCount = (int) ($pool['active_leases'] ?? 0);
$syncAt = $lastSync['finished_at'] ?? null;
$check = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 12.5 9.5 18 20 6.5"/></svg>';
?>
<section class="hero">
    <div class="hero-grid">
        <div>
            <span class="eyebrow"><span class="dot" aria-hidden="true"></span>OpenCode Free · IPv6 rotation relay</span>
            <h1>OpenCode 免费模型<span class="grad">每个请求，新的 IPv6</span></h1>
            <p class="lead">兼容 OpenAI Chat Completions 的自托管中转。免费模型无需真实 API Key，默认按官方客户端发送 <code>Authorization: Bearer public</code> 与完整会话/缓存请求头；每次上游请求从自定义 IPv6 段中分配全新地址，不回退 IPv4。</p>
            <div class="cta">
                <?php if (!empty($user)): ?>
                    <a class="button" href="<?= e(url('/dashboard')) ?>">进入控制台
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                    </a>
                <?php else: ?>
                    <a class="button" href="<?= e(url($registrationMode === 'closed' ? '/login' : '/register')) ?>">获取 API Key
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                    </a>
                <?php endif; ?>
                <a class="button ghost" href="<?= e(url('/docs')) ?>">查看 API 文档</a>
            </div>
        </div>
        <div class="stat-card">
            <div class="big"><?= e(format_number($modelCount)) ?> <span class="muted" style="font-size:.9rem;font-weight:500">free models</span></div>
            <p class="desc" style="margin:.3rem 0 .9rem">目录每小时从 OpenCode 刷新一次，只暴露免费模型。</p>
            <div class="stat-grid">
                <div class="stat"><span>IPv6 pools</span><b><?= e((string) $poolCount) ?></b></div>
                <div class="stat"><span>Active leases</span><b><?= e(format_number($leaseCount)) ?></b></div>
                <div class="stat"><span>IPv4 fallback</span><b>off</b></div>
                <div class="stat"><span>Last sync</span><b class="small"><?= e($syncAt ? time_ago((string) $syncAt) : 'not yet') ?></b></div>
            </div>
            <div class="notice" style="margin-bottom:0">所有请求都强制 <code>CURL_IPRESOLVE_V6</code>。IPv6 不可用时直接返回 503，不会静默降级。</div>
        </div>
    </div>
</section>

<section class="section" id="why">
    <div class="sec-h">
        <div>
            <h2>为什么这样设计</h2>
            <p>不是简单的反向代理，而是把“免费模型 + 可审计转发 + 可运营中转站”做在一起。</p>
        </div>
    </div>
    <div class="grid grid-3">
        <article class="card">
            <span class="role"><i class="dot" aria-hidden="true"></i>IPv6 only</span>
            <h3>每次请求独立出口</h3>
            <p class="desc">地址池支持任意 IPv6 CIDR（/32、/48、/56、/64、/96……前缀长度从数据库读取），为每次上游尝试生成从未使用过的地址并记录租约。</p>
            <ul class="points">
                <li><?= $check ?>不硬编码任何前缀或运营商网段</li>
                <li><?= $check ?>绑定失败立即报错，不回落 IPv4</li>
                <li><?= $check ?>429/5xx 自动换一个新地址重试</li>
            </ul>
        </article>
        <article class="card">
            <span class="role"><i class="dot" aria-hidden="true"></i>Client parity</span>
            <h3>与官方客户端一致</h3>
            <p class="desc">复刻 OpenCode 2.3.0 的 <code>User-Agent</code>、<code>x-opencode-*</code>、<code>x-session-affinity</code> 与稳定 <code>prompt_cache_key</code>，不修改系统提示词和消息。</p>
            <ul class="points">
                <li><?= $check ?>会话亲和度稳定，缓存命中更自然</li>
                <li><?= $check ?>原样透传 messages / tools / system</li>
                <li><?= $check ?>默认发送官方占位 Key：public</li>
            </ul>
        </article>
        <article class="card">
            <span class="role"><i class="dot" aria-hidden="true"></i>Open source</span>
            <h3>完整中转站制度</h3>
            <p class="desc">用户、API Key、套餐、每日额度、RPM、并发、积分、邀请码、兑换码、请求日志、审计日志、公告、模型管理一应俱全。</p>
            <ul class="points">
                <li><?= $check ?>默认开放注册，管理员可切换邀请/关闭</li>
                <li><?= $check ?>MIT 许可，无 Composer 依赖</li>
                <li><?= $check ?>Hook 扩展点便于二次开发</li>
            </ul>
        </article>
    </div>
</section>

<?php if ($models !== []): ?>
<section class="section" id="models">
    <div class="sec-h">
        <div>
            <h2>当前免费模型</h2>
            <p>目录来自 OpenCode 的公开模型端点，按 cost=0 与名称中的 Free 标记自动筛选。</p>
        </div>
        <a class="button ghost small" href="<?= e(url('/docs#models')) ?>">接口调用示例</a>
    </div>
    <div class="grid grid-3">
        <?php foreach (array_slice($models, 0, 9) as $model): ?>
            <article class="model-card">
                <div class="card-header" style="margin-bottom:.35rem">
                    <h3><?= e($model['name']) ?></h3>
                    <span class="badge green">free</span>
                </div>
                <div class="meta mono"><?= e($model['id']) ?></div>
                <p class="desc"><?= e(mb_substr((string) ($model['description'] ?: 'OpenCode free model.'), 0, 130)) ?></p>
                <div class="tag-list">
                    <?php if (!empty($model['context_limit'])): ?><span class="badge">ctx <?= e(format_number((int) $model['context_limit'])) ?></span><?php endif; ?>
                    <?php if (!empty($model['output_limit'])): ?><span class="badge">out <?= e(format_number((int) $model['output_limit'])) ?></span><?php endif; ?>
                    <span class="badge blue"><?= e($model['upstream_kind']) ?></span>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
    <?php if ($modelCount > 9): ?>
        <p class="muted center" style="margin-top:1rem">还有 <?= e((string) ($modelCount - 9)) ?> 个模型，登录后从 <code>/v1/models</code> 获取完整列表。</p>
    <?php endif; ?>
</section>
<?php endif; ?>

<section class="section" id="how">
    <div class="sec-h">
        <div>
            <h2>三步开始</h2>
            <p>注册、拿 Key、把任何 OpenAI 兼容客户端指向这里。</p>
        </div>
    </div>
    <ol class="steps">
        <li class="step">
            <span class="step-n" aria-hidden="true">1</span>
            <div class="step-b">
                <h3>创建账号与 API Key</h3>
                <p>默认注册模式为 <b>open</b>；管理员可在后台切换为邀请制或关闭注册。创建 Key 后只显示一次完整值。</p>
                <p class="tip">Key 形如 <code>sk-oc-...</code>，服务端只保存 HMAC-SHA256 摘要。</p>
            </div>
        </li>
        <li class="step">
            <span class="step-n" aria-hidden="true">2</span>
            <div class="step-b">
                <h3>把客户端 Base URL 指过来</h3>
                <p class="path"><?= e(rtrim((string) cfg('app.url', 'http://localhost:8080'), '/')) ?>/v1</p>
                <p>OpenAI SDK / Cherry Studio / OpenCode / Cline 等只需改 Base URL 和 API Key。模型名使用下方列表中的 ID。</p>
            </div>
        </li>
        <li class="step">
            <span class="step-n" aria-hidden="true">3</span>
            <div class="step-b">
                <h3>请求自动分配新的 IPv6</h3>
                <p>中转站从后台配置的 IPv6 段中分配地址、绑定出口、记录租约与上游尝试。失败时换新地址重试，直到达到配置上限。</p>
                <p class="tip">部署前请运行 <code>php bin/doctor.php</code>，确认 <code>net.ipv6.ip_nonlocal_bind=1</code>、NDP 代理和网段路由。</p>
            </div>
        </li>
    </ol>
</section>

<section class="section" id="faq">
    <div class="sec-h">
        <div><h2>常见问题</h2></div>
    </div>
    <div class="faq">
        <div class="it open">
            <button type="button" aria-expanded="true"><span>免费模型真的不需要 API Key 吗？</span></button>
            <div class="bd"><div><p>对 OpenCode 上游而言不需要真实 Key。官方客户端在没有 Console Key 时仍会发送字面量 <code>Authorization: Bearer public</code>，中转默认照做以保持一致；你也可以在设置里清空该值，让请求完全不带 Authorization。</p></div></div>
        </div>
        <div class="it">
            <button type="button" aria-expanded="false"><span>为什么必须 IPv6？没有 IPv4 兜底？</span></button>
            <div class="bd"><div><p>这是项目目标：用大段 IPv6 为每个请求提供独立出口。代码强制 <code>CURL_IPRESOLVE_V6</code>，并在绑定/连接失败时返回明确错误，避免“看起来能用、实际走了 IPv4”的隐式降级。</p></div></div>
        </div>
        <div class="it">
            <button type="button" aria-expanded="false"><span>和官方客户端相比，额度消耗会更慢吗？</span></button>
            <div class="bd"><div><p>中转不修改系统提示词、不裁剪消息、不改变 tools 结构；它复刻官方请求头并稳定 <code>prompt_cache_key</code>/会话亲和度，因此缓存行为与官方客户端一致。额度仍由 OpenCode 上游决定，本项目不承诺绕过任何限制。</p></div></div>
        </div>
        <div class="it">
            <button type="button" aria-expanded="false"><span>可以商用或二次开发吗？</span></button>
            <div class="bd"><div><p>代码使用 MIT 许可，可自由修改和自部署。请自行遵守 OpenCode 的服务条款以及当地法律；建议在站点上保留使用说明与免责声明。</p></div></div>
        </div>
    </div>
</section>
