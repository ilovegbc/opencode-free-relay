<?php /** @var string $content */ ?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title><?= e($title ?? $siteName ?? 'OpenCode Free Relay') ?></title>
    <meta name="description" content="<?= e($siteDescription ?? 'OpenCode 免费模型，兼容 OpenAI 接口。') ?>">
    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" content="#fbf9f5" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#181817" media="(prefers-color-scheme: dark)">
    <script>(function(){try{var t=localStorage.getItem('ocrelay-theme');if(t==='light'||t==='dark'){document.documentElement.dataset.theme=t;}}catch(e){}})();</script>
    <link rel="stylesheet" href="<?= e(asset('app.css')) ?>">
    <link rel="icon" href="<?= e(url('/assets/logo.svg')) ?>" type="image/svg+xml">
</head>
<body>
<div class="bg" aria-hidden="true">
    <i class="aurora"></i><i class="orb o1"></i><i class="orb o2"></i><i class="orb o3"></i><i class="orb o4"></i>
    <i class="grid"></i><i class="dots"></i><i class="spot"></i><i class="grain"></i>
</div>
<a class="skip" href="#main">跳到主要内容</a>
<div class="app-shell">
    <header class="topbar">
        <div class="topbar-in">
            <a class="brand" href="<?= e(url('/')) ?>">
                <img src="<?= e(url('/assets/logo.svg')) ?>" alt="" width="28" height="28">
                <span><?= e($siteName ?? 'OpenCode Free Relay') ?></span>
            </a>
            <button class="nav-toggle" type="button" data-nav-toggle aria-label="Toggle navigation">☰</button>
            <div class="topbar-r">
                <nav class="nav" data-nav aria-label="页面导航">
                    <a class="<?= ($activeNav ?? '') === 'home' ? 'active' : '' ?>" href="<?= e(url('/')) ?>">首页</a>
                    <a class="<?= ($activeNav ?? '') === 'docs' ? 'active' : '' ?>" href="<?= e(url('/docs')) ?>">API 文档</a>
                    <?php if (!empty($user)): ?>
                        <a class="<?= str_starts_with((string) ($activeNav ?? ''), 'dashboard') ? 'active' : '' ?>" href="<?= e(url('/dashboard')) ?>">控制台</a>
                        <?php if (($user['role'] ?? '') === 'admin'): ?>
                            <a href="<?= e(url('/admin')) ?>">管理</a>
                        <?php endif; ?>
                        <form method="post" action="<?= e(url('/logout')) ?>" class="inline-form">
                            <?= csrf_field() ?>
                            <button class="link-button" type="submit">退出</button>
                        </form>
                    <?php else: ?>
                        <a href="<?= e(url('/login')) ?>">登录</a>
                        <a class="button small" href="<?= e(url('/register')) ?>">注册</a>
                    <?php endif; ?>
                </nav>
                <label class="dn" title="切换深色模式">
                    <input type="checkbox" id="themeSwitch" role="switch" aria-label="深色模式">
                    <b aria-hidden="true"></b>
                </label>
            </div>
        </div>
    </header>

    <main class="page" id="main">
        <?php require BASE_PATH . '/app/Views/partials/flash.php'; ?>
        <?= $content ?>
    </main>

    <footer class="footer">
        <span><?= e($footer ?? '') ?></span>
    </footer>
</div>
<div class="toasts" id="toasts" aria-live="polite" aria-atomic="false"></div>
<script src="<?= e(asset('app.js')) ?>" defer></script>
</body>
</html>
