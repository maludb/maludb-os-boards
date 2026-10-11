<?php
/**
 * The templates gallery (screen `templates`): chips for the category (All · Retrospective · … · Other) and for the scope (All · Shipped · The business's · Mine) — plain links keeping each other —,
 * then the templates as cards, the shipped first. Data: filters, rows, total, page, may_use, notice
 */
$url = static function (array $change) use ($filters): string {
    $p = array_filter(array_merge($filters, $change), static fn ($v) => $v !== null && $v !== '');
    return '/templates' . ($p === [] ? '' : '?' . http_build_query($p));
};
$here = $url(['page' => $page > 1 ? $page : null]);
$more = $page * BOARD_PAGE < $total;
?>
<?= view('shared/page-header.php', ['id' => 'templates', 'title' => 'Templates', 'crumbs' => [['Home', '/'], ['Templates', null]], 'back' => back_link()]) ?>
<div class="main-content" id="templates-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="mb-3" id="templates-filters">
        <div class="d-flex flex-wrap gap-1 mb-2" role="group" aria-label="Category" id="templates-filter-category">
            <?php foreach (['' => 'All'] + TEMPLATE_CATEGORIES as $key => $label): $on = ($filters['category'] ?? '') === (string) $key; ?>
                <?= hx_link($url(['category' => $key === '' ? null : $key, 'page' => null]), e($label), 'btn btn-touch btn-sm ' . ($on ? 'btn-primary' : 'btn-light'),
                    'id="template-filter-category-' . ($key === '' ? 'all' : e($key)) . '"' . ($on ? ' aria-current="true"' : '')) ?>
            <?php endforeach; ?>
        </div>
        <div class="d-flex flex-wrap gap-1" role="group" aria-label="Whose" id="templates-filter-scope">
            <?php foreach (['' => 'All', 'shipped' => 'Shipped', 'business' => "The business's", 'personal' => 'Mine'] as $key => $label): $on = ($filters['scope'] ?? '') === (string) $key; ?>
                <?= hx_link($url(['scope' => $key === '' ? null : $key, 'page' => null]), e($label), 'btn btn-touch btn-sm ' . ($on ? 'btn-primary' : 'btn-light'),
                    'id="template-filter-scope-' . ($key === '' ? 'all' : e($key)) . '"' . ($on ? ' aria-current="true"' : '')) ?>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="fs-12 text-muted mb-2" id="templates-total"><?= (int) $total ?> template<?= $total === 1 ? '' : 's' ?></div>
    <?php if ($rows === []): ?>
        <div class="card"><div class="card-body"><div class="empty-state" id="templates-empty"><span class="avatar-text avatar-lg rounded"><i class="feather-layout"></i></span>
            <div class="min-w-0"><div class="fw-semibold"><?= $filters !== [] ? 'No template matches' : 'No templates yet' ?></div>
                <div class="fs-12 text-muted"><?= $filters !== [] ? 'Try another category or whose.' : 'Save a board as a template from its settings, and it will be here.' ?></div></div></div></div></div>
    <?php else: ?>
        <div class="row row-cols-1 row-cols-md-2 row-cols-xl-3 g-2" id="templates-grid">
            <?php foreach ($rows as $t): ?><div class="col"><?= view('templates/partials/card.php', ['t' => $t, 'back' => $here, 'may_use' => $may_use]) ?></div><?php endforeach; ?>
        </div>
        <?php if ($page > 1 || $more): ?>
            <nav class="mt-3" id="templates-pagination"><ul class="pagination mb-0">
                <?php if ($page > 1): ?><li class="page-item"><?= hx_link($url(['page' => $page - 1 > 1 ? $page - 1 : null]), 'Previous', 'page-link', 'id="templates-prev"') ?></li><?php endif; ?>
                <?php if ($more): ?><li class="page-item"><?= hx_link($url(['page' => $page + 1]), 'Next', 'page-link', 'id="templates-next"') ?></li><?php endif; ?>
            </ul></nav>
        <?php endif; ?>
    <?php endif; ?>
</div>
