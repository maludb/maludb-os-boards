<?php
/** Usage (screen `admin-usage`): the sort chips and filters, then a table at 768 and up, cards below. Data: filters, rows, total, page, budget, tz */
$url = static function (array $change) use ($filters): string {
    $p = array_filter(array_merge($filters, $change), static fn ($v) => $v !== null && $v !== '' && $v !== false);
    if (($p['sort'] ?? '') === 'items') { unset($p['sort']); }
    return '/admin/usage' . ($p === [] ? '' : '?' . http_build_query($p));
};
$here = $url(['page' => $page > 1 ? $page : null]);
$more = $page * USAGE_PAGE < $total;
?>
<?= view('shared/page-header.php', ['id' => 'admin-usage', 'title' => 'Usage', 'crumbs' => [['Home', '/'], ['Admin', '/admin'], ['Usage', null]], 'back' => back_link()]) ?>
<div class="main-content" id="admin-usage-content">
    <div class="d-flex flex-wrap gap-1 mb-2" role="group" aria-label="Sort" id="usage-sorts">
        <?php foreach (USAGE_SORTS as $key => [$label]): $on = $filters['sort'] === $key; ?>
            <?= hx_link($url(['sort' => $key, 'page' => null]), e($label), 'btn btn-touch btn-sm ' . ($on ? 'btn-primary' : 'btn-light'), 'id="usage-sort-' . e($key) . '"' . ($on ? ' aria-current="true"' : '')) ?>
        <?php endforeach; ?>
    </div>
    <div class="d-flex flex-wrap gap-1 mb-3" role="group" aria-label="Filter" id="usage-filters">
        <?php $idle = $filters['idle_days'] ?? null; $left = !empty($filters['owner_left']); ?>
        <?= hx_link($url(['idle_days' => $idle === 90 ? null : 90, 'page' => null]), 'Idle 90 days or more', 'btn btn-touch btn-sm ' . ($idle === 90 ? 'btn-primary' : 'btn-light'), 'id="usage-filter-idle"') ?>
        <?= hx_link($url(['owner_left' => $left ? null : 1, 'page' => null]), 'Owner has left', 'btn btn-touch btn-sm ' . ($left ? 'btn-primary' : 'btn-light'), 'id="usage-filter-owner-left"') ?>
    </div>
    <div class="fs-12 text-muted mb-2" id="usage-total"><?= (int) $total ?> board<?= $total === 1 ? '' : 's' ?> · the soft budget is <?= (int) $budget[0] ?> items, the hard cap <?= (int) $budget[1] ?></div>
    <?php if ($rows === []): ?>
        <div class="card"><div class="card-body"><div class="empty-state" id="usage-empty"><span class="avatar-text avatar-lg rounded"><i class="feather-bar-chart-2"></i></span>
            <div class="min-w-0"><div class="fw-semibold"><?= $filters !== ['sort' => 'items'] ? 'No board matches' : 'No boards yet' ?></div></div></div></div></div>
    <?php else: ?>
        <div class="d-none d-md-block card mb-0"><div class="table-responsive"><table class="table table-hover mb-0" id="usage-table">
            <thead><tr><th>Board</th><th>Owner</th><th>Items</th><th>Idle</th><th>Open threads</th><th>Last activity</th><th></th></tr></thead>
            <tbody><?php foreach ($rows as $r) { echo view('admin/partials/usage-row.php', ['r' => $r, 'budget' => $budget, 'back' => $here]); } ?></tbody>
        </table></div></div>
        <div class="d-md-none row row-cols-1 g-2" id="usage-cards">
            <?php foreach ($rows as $r): ?><div class="col"><?= view('admin/partials/usage-card.php', ['r' => $r, 'budget' => $budget, 'back' => $here]) ?></div><?php endforeach; ?>
        </div>
        <?php if ($page > 1 || $more): ?>
            <nav class="mt-3" id="usage-pagination"><ul class="pagination mb-0">
                <?php if ($page > 1): ?><li class="page-item"><?= hx_link($url(['page' => $page - 1 > 1 ? $page - 1 : null]), 'Previous', 'page-link', 'id="usage-prev"') ?></li><?php endif; ?>
                <?php if ($more): ?><li class="page-item"><?= hx_link($url(['page' => $page + 1]), 'Next', 'page-link', 'id="usage-next"') ?></li><?php endif; ?>
            </ul></nav>
        <?php endif; ?>
    <?php endif; ?>
</div>
