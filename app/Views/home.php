<?php
$modelCount = count($models ?? []);
?>
<section class="hero">
    <div class="hero-grid">
        <div>
            <span class="eyebrow"><span class="dot" aria-hidden="true"></span>OpenCode Free</span>
            <h1>OpenCode 免费模型<span class="grad">注册即用</span></h1>
            <p class="lead">兼容 OpenAI Chat Completions 接口的免费模型服务。注册账号并创建 API Key 后，把任意 OpenAI 兼容客户端的 Base URL 指向本站即可开始调用。</p>
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
            <p class="desc" style="margin:.3rem 0 .9rem">当前可用的免费模型，登录后可查看完整列表。</p>
            <div class="stat-grid">
                <div class="stat"><span>接口格式</span><b>OpenAI</b></div>
                <div class="stat"><span>Base URL</span><b class="small">/v1</b></div>
                <div class="stat"><span>API Key</span><b class="small">自助创建</b></div>
                <div class="stat"><span>额度</span><b class="small">每日更新</b></div>
            </div>
        </div>
    </div>
</section>

<?php if ($models !== []): ?>
<section class="section" id="models">
    <div class="sec-h">
        <div>
            <h2>免费模型</h2>
            <p>登录后可从 <code>/v1/models</code> 获取完整列表。</p>
        </div>
        <a class="button ghost small" href="<?= e(url('/docs#models')) ?>">调用示例</a>
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
            <p>注册、创建 Key、把客户端指向这里。</p>
        </div>
    </div>
    <ol class="steps">
        <li class="step">
            <span class="step-n" aria-hidden="true">1</span>
            <div class="step-b">
                <h3>注册并创建 API Key</h3>
                <p>进入控制台创建 Key，完整值只显示一次，请及时保存。</p>
            </div>
        </li>
        <li class="step">
            <span class="step-n" aria-hidden="true">2</span>
            <div class="step-b">
                <h3>把客户端 Base URL 指过来</h3>
                <p class="path"><?= e(rtrim((string) cfg('app.url', 'http://localhost:8080'), '/')) ?>/v1</p>
                <p>OpenAI SDK / Cherry Studio / OpenCode / Cline 等只需改 Base URL 和 API Key，模型名使用上方列表中的 ID。</p>
            </div>
        </li>
        <li class="step">
            <span class="step-n" aria-hidden="true">3</span>
            <div class="step-b">
                <h3>开始调用</h3>
                <p>用任意兼容 OpenAI 的客户端发起请求即可，用量和记录可在控制台查看。</p>
            </div>
        </li>
    </ol>
</section>
