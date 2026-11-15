<?php /** @var string $content */ ?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title><?= e($title ?? ('登录 · ' . ($siteName ?? 'OpenCode Free Relay'))) ?></title>
    <meta name="color-scheme" content="light dark">
    <script>(function(){try{var t=localStorage.getItem('ocrelay-theme');if(t==='light'||t==='dark'){document.documentElement.dataset.theme=t;}}catch(e){}})();</script>
    <link rel="stylesheet" href="<?= e(asset('app.css')) ?>">
    <link rel="icon" href="<?= e(url('/assets/logo.svg')) ?>" type="image/svg+xml">
</head>
<body class="auth-body">
<div class="bg" aria-hidden="true">
    <i class="aurora"></i><i class="orb o1"></i><i class="orb o2"></i><i class="orb o3"></i><i class="orb o4"></i>
    <i class="grid"></i><i class="dots"></i><i class="spot"></i><i class="grain"></i>
</div>
<div class="auth-wrap">
    <div class="auth-brand-row">
        <a class="brand auth-brand" href="<?= e(url('/')) ?>">
            <img src="<?= e(url('/assets/logo.svg')) ?>" alt="" width="34" height="34">
            <span><?= e($siteName ?? 'OpenCode Free Relay') ?></span>
        </a>
        <label class="dn" title="切换深色模式">
            <input type="checkbox" id="themeSwitch" role="switch" aria-label="深色模式">
            <b aria-hidden="true"></b>
        </label>
    </div>
    <?php require BASE_PATH . '/app/Views/partials/flash.php'; ?>
    <div class="card auth-card">
        <?= $content ?>
    </div>
    <p class="muted center"><a href="<?= e(url('/')) ?>">← 返回首页</a></p>
</div>
<div class="toasts" id="toasts" aria-live="polite" aria-atomic="false"></div>
<script src="<?= e(asset('app.js')) ?>" defer></script>
</body>
</html>
