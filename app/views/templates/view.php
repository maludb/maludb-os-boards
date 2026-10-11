<?php
/**
 * A template's page (screen `template-view`): the header (title, category and scope chips, items, by whom), its outline (frames as headings, items as lines — never a picture), Use (the same title-and-folder form),
 * Open on the canvas (read-only for most), Delete for whoever may (a shipped template never changes). Data: t, outline (html), level, may_use, notice, tz
 */
$id = (string) $t['board_id'];
$scope = (string) $t['template_scope'];
[$scopeWords, $scopeColor] = TEMPLATE_SCOPES[$scope] ?? [$scope, 'secondary'];
$by = $scope === 'shipped' ? 'Shipped with Boards' : (((int) ($t['owner_member_id'] ?? 0) === (int) current_member_id()) ? 'you' : (string) ($t['owner_name'] ?? ''));
$mayDelete = $scope !== 'shipped' && bd_level_at_least($level, 'full');
$open = hx_link('/boards/' . $id, '<i class="feather-external-link me-1"></i>Open on the canvas', 'btn btn-light btn-touch', 'id="template-open"');
?>
<?= view('shared/page-header.php', ['id' => 'template-view', 'title' => (string) $t['title'], 'crumbs' => [['Home', '/'], ['Templates', '/templates'], [(string) $t['title'], null]], 'back' => back_link(), 'action' => $open]) ?>
<div class="main-content" id="template-view-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="d-flex flex-wrap align-items-center gap-2 mb-3" id="template-badges">
        <span class="badge text-bg-<?= e($scopeColor) ?>" id="template-scope"><?= e($scopeWords) ?></span>
        <span class="badge text-bg-light" id="template-category"><?= e(TEMPLATE_CATEGORIES[$t['template_category']] ?? 'Other') ?></span>
        <span class="fs-12 text-muted" id="template-meta"><?= (int) $t['item_count'] ?> item<?= (int) $t['item_count'] === 1 ? '' : 's' ?> · <?= e($by) ?></span>
    </div>
    <?php if (!empty($t['description'])): ?><p class="fs-13" id="template-description"><?= e((string) $t['description']) ?></p><?php endif; ?>
    <div class="row g-3">
        <div class="col-lg-8">
            <section class="card mb-0" id="template-outline-card" aria-labelledby="template-outline-title"><div class="card-header"><h5 class="card-title mb-0" id="template-outline-title">What is on it</h5></div>
                <div class="card-body history-outline fs-13" id="template-outline"><?= $outline !== '' ? $outline : '<span class="text-muted">This template is an empty board.</span>' ?></div></section>
        </div>
        <div class="col-lg-4">
            <?php if ($may_use): ?>
                <section class="card mb-3" id="template-use-card"><div class="card-header"><h5 class="card-title mb-0">Use this template</h5></div><div class="card-body">
                    <p class="fs-12 text-muted">A new board of yours, with everything on it copied. The template stays as it is.</p>
                    <?= view('templates/partials/use-form.php', ['t' => $t]) ?>
                </div></section>
            <?php else: ?><p class="fs-12 text-muted" id="template-no-use">You may open this template but not make boards from it.</p><?php endif; ?>
            <?php if ($mayDelete): ?>
                <section class="card mb-0" id="template-delete-card"><div class="card-body">
                    <form method="post" action="/boards/delete.php" hx-post="/boards/delete.php" hx-target="#page-content" hx-swap="innerHTML" hx-confirm="Delete <?= e((string) $t['title']) ?>? It moves to the trash for 30 days.">
                        <?= csrf_field() ?><input type="hidden" name="board" value="<?= e($id) ?>">
                        <button type="submit" class="btn btn-outline-danger btn-touch w-100" id="template-delete"><i class="feather-trash-2 me-1"></i>Delete this template</button>
                    </form>
                </div></section>
            <?php endif; ?>
        </div>
    </div>
</div>
