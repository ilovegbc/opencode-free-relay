<?php
$page = (int) ($page ?? 1);
$pages = (int) ($pages ?? 1);
$base = $base ?? '';
$query = $query ?? [];
$makeUrl = static function (int $p) use ($base, $query): string {
    $query['page'] = $p;
    return $base . '?' . http_build_query($query);
};
?>
<?php if ($pages > 1): ?>
    <nav class="pagination" aria-label="Pagination">
        <?php if ($page > 1): ?>
            <a class="button ghost small" href="<?= e($makeUrl($page - 1)) ?>">← Prev</a>
        <?php endif; ?>
        <span class="muted">Page <?= $page ?> / <?= $pages ?></span>
        <?php if ($page < $pages): ?>
            <a class="button ghost small" href="<?= e($makeUrl($page + 1)) ?>">Next →</a>
        <?php endif; ?>
    </nav>
<?php endif; ?>
