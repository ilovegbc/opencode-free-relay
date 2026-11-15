<div class="section-title">
    <div>
        <span class="eyebrow"><span class="dot"></span>Content</span>
        <h1 style="margin-top:.7rem">公告</h1>
        <p class="muted" style="margin:0">在用户控制台顶部展示，可设置开始/结束时间。</p>
    </div>
    <a class="button ghost small" href="<?= e(url('/admin')) ?>">← 总览</a>
</div>

<div class="card">
    <h2>发布公告</h2>
    <form method="post" action="<?= e(url('/admin/announcements')) ?>">
        <?= csrf_field() ?>
        <div class="form-grid">
            <div class="form-row"><label>标题</label><input name="title" type="text" required></div>
            <div class="form-row"><label>级别</label><select name="level"><option value="info">info</option><option value="success">success</option><option value="warning">warning</option><option value="danger">danger</option></select></div>
            <div class="form-row full"><label>内容</label><textarea name="body"></textarea></div>
            <div class="form-row"><label>开始时间（可选）</label><input name="starts_at" type="datetime-local"></div>
            <div class="form-row"><label>结束时间（可选）</label><input name="ends_at" type="datetime-local"></div>
        </div>
        <div class="checkbox form-row"><input id="ann_active" type="checkbox" name="active" value="1" checked><label for="ann_active">启用</label></div>
        <button class="button" type="submit">发布</button>
    </form>
</div>

<div class="card" style="margin-top:1rem">
    <h2>全部公告</h2>
    <?php foreach ($announcements as $announcement): ?>
        <details>
            <summary>
                <span class="badge <?= e($announcement['level']) ?>"><?= e($announcement['level']) ?></span>
                <?= e($announcement['title']) ?>
                <span class="muted small">· <?= e(format_dt($announcement['created_at'], 'Y-m-d')) ?></span>
                <?php if (!$announcement['active']): ?><span class="badge red">off</span><?php endif; ?>
            </summary>
            <p class="small muted"><?= nl2br(e($announcement['body'])) ?></p>
            <form method="post" action="<?= e(url('/admin/announcements')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= e((string) $announcement['id']) ?>">
                <div class="form-grid">
                    <div class="form-row"><label>标题</label><input name="title" type="text" value="<?= e($announcement['title']) ?>" required></div>
                    <div class="form-row"><label>级别</label><select name="level"><?php foreach (['info', 'success', 'warning', 'danger'] as $level): ?><option value="<?= e($level) ?>" <?= $announcement['level'] === $level ? 'selected' : '' ?>><?= e($level) ?></option><?php endforeach; ?></select></div>
                    <div class="form-row full"><label>内容</label><textarea name="body"><?= e($announcement['body']) ?></textarea></div>
                    <div class="form-row"><label>开始</label><input name="starts_at" type="datetime-local" value="<?= e($announcement['starts_at'] ? date('Y-m-d\TH:i', strtotime((string) $announcement['starts_at'])) : '') ?>"></div>
                    <div class="form-row"><label>结束</label><input name="ends_at" type="datetime-local" value="<?= e($announcement['ends_at'] ? date('Y-m-d\TH:i', strtotime((string) $announcement['ends_at'])) : '') ?>"></div>
                </div>
                <div class="checkbox form-row"><input id="ann_<?= e((string) $announcement['id']) ?>" type="checkbox" name="active" value="1" <?= $announcement['active'] ? 'checked' : '' ?>><label for="ann_<?= e((string) $announcement['id']) ?>">启用</label></div>
                <div class="button-row">
                    <button class="button small" type="submit">保存</button>
                </div>
            </form>
            <form method="post" action="<?= e(url('/admin/announcements/' . $announcement['id'] . '/delete')) ?>" data-confirm="删除这条公告？" class="inline-form" style="margin-top:.5rem">
                <?= csrf_field() ?><button class="button danger small" type="submit">删除</button>
            </form>
        </details>
    <?php endforeach; ?>
    <?php if (empty($announcements)): ?><p class="muted">暂无公告。</p><?php endif; ?>
</div>
