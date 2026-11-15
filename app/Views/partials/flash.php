<?php foreach (($flashes ?? []) as $flash): ?>
    <div class="alert <?= e($flash['type'] ?? 'info') ?>" role="status">
        <?= e($flash['message'] ?? '') ?>
    </div>
<?php endforeach; ?>
