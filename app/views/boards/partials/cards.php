<?php
/**
 * The cards — the swapped region (#boards-cards): the count, the boards as RecordCards in a grid, paged 24; an empty state with one button.
 * Shared by the list and a folder's page. Data: rows, total, page, filters, base (the page's own path), may_create, folder? (a folder row: its
 * cards carry "Remove from folder" when may_change), tz
 */
$folder = $folder ?? null;
$url = static function (int $p) use ($filters, $base): string {
    $q = array_filter($filters + ['page' => $p > 1 ? $p : null], static fn ($v) => $v !== null && $v !== '' && $v !== false);
    foreach (['shared', 'favorites'] as $k) { if (isset($q[$k])) { $q[$k] = 1; } }
    return $base . ($q === [] ? '' : '?' . http_build_query($q));
};
$here = $url($page);
$more = $page * BOARD_PAGE < $total;
$any = $filters !== [];
?>
<div id="boards-cards">
    <div class="fs-12 text-muted mb-2" id="boards-total"><?= (int) $total ?> board<?= $total === 1 ? '' : 's' ?></div>
    <?php if ($rows === []): ?>
        <div class="card"><div class="card-body"><div class="empty-state" id="boards-empty"><span class="avatar-text avatar-lg rounded"><i class="feather-layout"></i></span>
            <div class="min-w-0"><div class="fw-semibold"><?= $folder !== null ? 'No board here that you can open' : ($any ? 'No board matches' : 'No boards yet') ?></div>
                <div class="fs-12 text-muted mb-2"><?= $folder !== null ? 'File a board here from its settings.' : ($any ? 'Try another chip, folder or word.' : 'A board is an infinite canvas for stickies, shapes and connectors — yours, or shared with your team.') ?></div>
                <?php if ($may_create && !$any && $folder === null): ?><?= hx_link('/boards/new', '<i class="feather-plus me-1"></i>New board', 'btn btn-primary btn-touch', 'id="boards-empty-add-btn"') ?><?php endif; ?>
            </div></div></div></div>
    <?php else: ?>
        <div class="row row-cols-1 row-cols-md-2 row-cols-xl-3 g-2" id="boards-grid">
            <?php foreach ($rows as $r): ?><div class="col"><?= view('boards/partials/card.php', ['r' => $r, 'back' => $here, 'folder' => $folder, 'tz' => $tz]) ?></div><?php endforeach; ?>
        </div>
        <?php if ($page > 1 || $more): ?>
            <nav class="mt-3" id="boards-pagination"><ul class="pagination mb-0">
                <?php if ($page > 1): ?><li class="page-item"><?= hx_link($url($page - 1), 'Previous', 'page-link', 'id="boards-prev"') ?></li><?php endif; ?>
                <?php if ($more): ?><li class="page-item"><?= hx_link($url($page + 1), 'Next', 'page-link', 'id="boards-next"') ?></li><?php endif; ?>
            </ul></nav>
        <?php endif; ?>
    <?php endif; ?>
</div>
