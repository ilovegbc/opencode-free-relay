<?php /** @var string $content */ ?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title><?= e(($title ?? '管理') . ' · ' . ($siteName ?? 'OpenCode Free Relay')) ?></title>
    <meta name="color-scheme" content="light dark">
    <script>(function(){try{var t=localStorage.getItem('ocrelay-theme');if(t==='light'||t==='dark'){document.documentElement.dataset.theme=t;}}catch(e){}})();</script>
    <link rel="stylesheet" href="<?= e(asset('app.css')) ?>">
    <link rel="icon" href="<?= e(url('/assets/logo.svg')) ?>" type="image/svg+xml">
</head>
<body class="admin-body">
<div class="bg" aria-hidden="true">
    <i class="aurora"></i><i class="orb o1"></i><i class="orb o2"></i><i class="orb o3"></i><i class="orb o4"></i>
    <i class="grid"></i><i class="dots"></i><i class="spot"></i><i class="grain"></i>
</div>
<div class="admin-shell">
    <aside class="admin-sidebar" data-nav>
        <a class="brand" href="<?= e(url('/')) ?>">
            <img src="<?= e(url('/assets/logo.svg')) ?>" alt="" width="26" height="26">
            <span><?= e($siteName ?? 'Relay') ?></span>
        </a>
        <div class="sidebar-section">运营</div>
        <a class="<?= ($activeNav ?? '') === 'admin' ? 'active' : '' ?>" href="<?= e(url('/admin')) ?>">总览</a>
        <a class="<?= ($activeNav ?? '') === 'users' ? 'active' : '' ?>" href="<?= e(url('/admin/users')) ?>">用户</a>
        <a class="<?= ($activeNav ?? '') === 'pools' ? 'active' : '' ?>" href="<?= e(url('/admin/pools')) ?>">IPv6 池</a>
        <a class="<?= ($activeNav ?? '') === 'models' ? 'active' : '' ?>" href="<?= e(url('/admin/models')) ?>">免费模型</a>
        <a class="<?= ($activeNav ?? '') === 'plans' ? 'active' : '' ?>" href="<?= e(url('/admin/plans')) ?>">套餐</a>
        <a class="<?= ($activeNav ?? '') === 'codes' ? 'active' : '' ?>" href="<?= e(url('/admin/codes')) ?>">邀请码</a>
        <div class="sidebar-section">可观测</div>
        <a class="<?= ($activeNav ?? '') === 'logs' ? 'active' : '' ?>" href="<?= e(url('/admin/logs')) ?>">请求日志</a>
        <a class="<?= ($activeNav ?? '') === 'audit' ? 'active' : '' ?>" href="<?= e(url('/admin/audit')) ?>">审计日志</a>
        <a class="<?= ($activeNav ?? '') === 'announcements' ? 'active' : '' ?>" href="<?= e(url('/admin/announcements')) ?>">公告</a>
        <a class="<?= ($activeNav ?? '') === 'diagnostics' ? 'active' : '' ?>" href="<?= e(url('/admin/diagnostics')) ?>">诊断</a>
        <div class="sidebar-section">配置</div>
        <a class="<?= ($activeNav ?? '') === 'settings' ? 'active' : '' ?>" href="<?= e(url('/admin/settings')) ?>">系统设置</a>
        <a href="<?= e(url('/dashboard')) ?>">← 用户控制台</a>
    </aside>
    <div class="admin-main">
        <header class="admin-topbar">
            <button class="nav-toggle" type="button" data-nav-toggle aria-label="Toggle navigation">☰</button>
            <span class="muted">管理员</span>
            <div class="button-row">
                <label class="dn" title="切换深色模式">
                    <input type="checkbox" id="themeSwitch" role="switch" aria-label="深色模式">
                    <b aria-hidden="true"></b>
                </label>
                <form method="post" action="<?= e(url('/logout')) ?>" class="inline-form">
                    <?= csrf_field() ?>
                    <button class="link-button" type="submit">退出</button>
                </form>
            </div>
        </header>
        <main class="admin-content">
            <?php require BASE_PATH . '/app/Views/partials/flash.php'; ?>
            <?= $content ?>
        </main>
    </div>
</div>
<div class="toasts" id="toasts" aria-live="polite" aria-atomic="false"></div>
<script src="<?= e(asset('app.js')) ?>" defer></script>
</body>
</html>
