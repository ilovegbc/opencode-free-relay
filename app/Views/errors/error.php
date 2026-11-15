<div class="card" style="max-width:720px;margin:6vh auto">
    <span class="badge red">HTTP <?= e((string) ($status ?? 500)) ?></span>
    <h1 style="font-size:clamp(1.6rem,3vw,2.2rem)"><?= e($title ?? 'Request failed') ?></h1>
    <p class="lead" style="margin-bottom:1rem"><?= e($message ?? 'Something went wrong.') ?></p>
    <?php if (!empty($details)): ?>
        <pre><code><?= e($details) ?></code></pre>
    <?php endif; ?>
    <div class="button-row">
        <a class="button ghost" href="<?= e(url('/')) ?>">返回首页</a>
        <a class="button" href="<?= e(url('/docs')) ?>">API 文档</a>
    </div>
</div>
