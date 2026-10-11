<?php
/**
 * The comments page (screen `board-comments`): a board's threads as cards, open first, then resolved; All / Open / Resolved and Mine; a card opens its thread inline.
 * Data: b (the gate's mcp_boards row), rows, total, page, pages, f (open: null|1|0, mine), tz
 */
$id = (string) $b['board_id'];
$base = '/boards/' . $id . '/comments';
$chip = static function (string $label, array $q, bool $on, string $cid) use ($base): string {
    $q = array_filter($q, static fn ($v) => $v !== null);
    return '<a class="btn btn-sm ' . ($on ? 'btn-primary' : 'btn-light') . ' btn-touch" id="' . $cid . '" href="' . e($base . ($q ? '?' . http_build_query($q) : '')) . '"'
        . ' hx-get="' . e($base . ($q ? '?' . http_build_query($q) : '')) . '" hx-target="#page-content" hx-push-url="' . e($base . ($q ? '?' . http_build_query($q) : '')) . '">' . e($label) . '</a>';
};
$mine = !empty($f['mine']) ? 1 : null;
?>
<?= view('shared/page-header.php', ['id' => 'board-comments', 'title' => 'Comments', 'crumbs' => [['Boards', '/boards'], [(string) $b['title'], '/boards/' . $id], ['Comments', null]], 'back' => back_link(),
    'action' => '<a class="btn btn-primary btn-touch" href="/boards/' . e($id) . '" id="board-comments-canvas"><i class="feather-edit-3 me-1"></i>Open the canvas</a>']) ?>
<div class="main-content" id="board-comments-content">
    <div class="d-flex flex-wrap gap-2 mb-3" id="board-comments-filters">
        <?= $chip('All', ['mine' => $mine], $f['open'] === null && $mine === null, 'comments-filter-all') ?>
        <?= $chip('Open', ['open' => 1, 'mine' => $mine], $f['open'] === 1, 'comments-filter-open') ?>
        <?= $chip('Resolved', ['open' => 0, 'mine' => $mine], $f['open'] === 0, 'comments-filter-resolved') ?>
        <?= $chip('Mine', ['open' => $f['open'], 'mine' => $mine === null ? 1 : null], $mine !== null, 'comments-filter-mine') ?>
    </div>
    <?php if ($rows === []): ?>
        <div class="card"><div class="card-body text-muted" id="board-comments-empty"><?= $f['open'] === null && $mine === null ? 'No comments on this board yet. Open the canvas, pick the Comment tool and click.' : 'No threads match.' ?></div></div>
    <?php endif; ?>
    <?php foreach ($rows as $r): ?><?= view('comments/partials/card.php', ['r' => $r, 'tz' => $tz, 'rail' => false]) ?><?php endforeach; ?>
    <?php if ($pages > 1): ?>
    <nav class="d-flex justify-content-between align-items-center mt-3 fs-12" id="board-comments-pager" aria-label="Pages">
        <?php if ($page > 1): ?><a class="btn btn-light btn-touch" id="board-comments-prev" href="<?= e($base . '?' . http_build_query(array_filter(['open' => $f['open'], 'mine' => $mine, 'page' => $page - 1], static fn ($v) => $v !== null))) ?>"><i class="feather-arrow-left me-1"></i>Newer</a><?php else: ?><span></span><?php endif; ?>
        <span class="text-muted">Page <?= (int) $page ?> of <?= (int) $pages ?></span>
        <?php if ($page < $pages): ?><a class="btn btn-light btn-touch" id="board-comments-next" href="<?= e($base . '?' . http_build_query(array_filter(['open' => $f['open'], 'mine' => $mine, 'page' => $page + 1], static fn ($v) => $v !== null))) ?>">Older<i class="feather-arrow-right ms-1"></i></a><?php else: ?><span></span><?php endif; ?>
    </nav>
    <?php endif; ?>
</div>
