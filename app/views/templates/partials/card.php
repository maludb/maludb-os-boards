<?php
/**
 * A template as a card (the board card's look, the scope chip in place of the owner): the thumbnail or the category's tile, the title as a link to its page, the scope and category chips, the items; Preview, and — for a
 * person who may create boards — Use, which opens the title-and-folder form in place (no JavaScript needed). Data: t (a find_templates row), back, may_use (bool)
 */
$id = (string) $t['board_id'];
$scope = (string) $t['template_scope'];
[$scopeWords, $scopeColor] = TEMPLATE_SCOPES[$scope] ?? [$scope, 'secondary'];
$page = with_back('/templates/' . $id, $back);
?>
<div class="card record-card template-card mb-0 h-100" id="template-card-<?= e($id) ?>"><div class="card-body py-3">
    <div class="d-flex gap-3 align-items-start">
        <a href="<?= e($page) ?>" class="flex-shrink-0" aria-hidden="true" tabindex="-1">
            <?php if (!empty($t['thumbnail_id'])): ?>
                <span class="board-card-thumb rounded"><img src="/boards/<?= e($id) ?>/thumbnail?v=<?= (int) $t['thumbnail_id'] ?>" alt="" loading="lazy"></span>
            <?php else: ?><?= view('templates/partials/category-tile.php', ['category' => $t['template_category'], 'id' => 'template-card-' . $id . '-tile']) ?><?php endif; ?>
        </a>
        <div class="min-w-0 flex-grow-1">
            <div class="fw-semibold text-break"><?= hx_link($page, e((string) $t['title']), 'text-dark', 'id="template-card-' . e($id) . '-link"') ?></div>
            <div class="d-flex flex-wrap gap-1 mt-1">
                <span class="badge text-bg-<?= e($scopeColor) ?>" id="template-card-<?= e($id) ?>-scope"><?= e($scopeWords) ?></span>
                <span class="badge text-bg-light" id="template-card-<?= e($id) ?>-category"><?= e(TEMPLATE_CATEGORIES[$t['template_category']] ?? 'Other') ?></span>
            </div>
            <div class="fs-11 text-muted mt-1"><?= (int) $t['item_count'] ?> item<?= (int) $t['item_count'] === 1 ? '' : 's' ?><?= $scope === 'shipped' ? '' : ' · ' . e((string) ($t['owner_name'] ?? '')) ?></div>
        </div>
    </div>
    <?php if (!empty($t['description'])): ?><p class="fs-12 text-muted mt-2 mb-0"><?= e((string) $t['description']) ?></p><?php endif; ?>
    <div class="d-flex flex-wrap gap-2 mt-2">
        <?= hx_link($page, '<i class="feather-eye me-1"></i>Preview', 'btn btn-light btn-sm btn-touch', 'id="template-card-' . e($id) . '-preview"') ?>
    </div>
    <?php if ($may_use): ?>
        <details class="mt-2" id="template-use-<?= e($id) ?>">
            <summary class="btn btn-primary btn-sm btn-touch" id="template-use-open-<?= e($id) ?>"><i class="feather-copy me-1"></i>Use</summary>
            <?= view('templates/partials/use-form.php', ['t' => $t, 'suffix' => '-' . $id]) ?>
        </details>
    <?php endif; ?>
</div></div>
