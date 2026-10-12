<?php
/** One search (screen `search`). Data: q, kind, board, filters, rows, total, page, tz. Ids: search-form-field-q, search-filter-kind-*, search-form-field-board, search-result-{kind}-{id} */
$url = static function (array $change) use ($filters): string {
    $p = array_filter(array_merge($filters, $change), static fn ($v) => $v !== null && $v !== '');
    return '/search' . ($p === [] ? '' : '?' . http_build_query($p));
};
$searched = mb_strlen($q) >= SEARCH_MIN;
$more = $page * SEARCH_PAGE < $total;
?>
<?= view('shared/page-header.php', ['id' => 'search', 'title' => 'Search', 'crumbs' => [['Home', '/'], ['Search', null]], 'back' => back_link()]) ?>
<div class="main-content" id="search-content">
    <form method="get" action="/search" id="search-form" class="mb-3" role="search">
        <div class="input-group mb-2">
            <input type="search" name="q" id="search-form-field-q" class="form-control btn-touch" value="<?= e($q) ?>" placeholder="Search boards, items and comments" minlength="<?= SEARCH_MIN ?>" maxlength="200" aria-label="Search" autocomplete="off" autofocus>
            <button type="submit" class="btn btn-primary btn-touch" id="search-submit"><i class="feather-search me-1"></i>Search</button>
        </div>
        <?php if ($kind !== null): ?><input type="hidden" name="kind" value="<?= e($kind) ?>"><?php endif; ?>
        <div class="mb-1" style="max-width: 24rem"><?= picker_field(['id' => 'search-form-field-board', 'name' => 'board', 'source' => 'board', 'value' => $board, 'placeholder' => 'Any board', 'title' => 'Boards', 'search' => 'Search your boards…']) ?></div>
    </form>
    <div class="d-flex flex-wrap gap-1 mb-3" role="group" aria-label="What to search" id="search-filter-kind">
        <?php foreach (['' => 'All'] + SEARCH_KINDS as $key => $label): $on = ($kind ?? '') === (string) $key; ?>
            <?= hx_link($url(['kind' => $key === '' ? null : $key, 'page' => null]), e($label), 'btn btn-touch btn-sm ' . ($on ? 'btn-primary' : 'btn-light'),
                'id="search-filter-kind-' . ($key === '' ? 'all' : e($key)) . '"' . ($on ? ' aria-current="true"' : '')) ?>
        <?php endforeach; ?>
    </div>
    <?php if (!$searched): ?>
        <div class="card"><div class="card-body"><div class="empty-state" id="search-prompt"><span class="avatar-text avatar-lg rounded"><i class="feather-search"></i></span>
            <div class="min-w-0"><div class="fw-semibold">Search everything you can open</div>
                <div class="fs-12 text-muted">Type at least <?= SEARCH_MIN ?> characters: a word on a sticky, in a comment or in a board's title.</div></div></div></div></div>
    <?php elseif ($rows === []): ?>
        <div class="card"><div class="card-body"><div class="empty-state" id="search-empty"><span class="avatar-text avatar-lg rounded"><i class="feather-search"></i></span>
            <div class="min-w-0"><div class="fw-semibold">Nothing found</div><div class="fs-12 text-muted">Try fewer words.</div></div></div></div></div>
    <?php else: ?>
        <div class="fs-12 text-muted mb-2" id="search-total"><?= (int) $total ?> result<?= $total === 1 ? '' : 's' ?></div>
        <div id="search-results"><?php foreach ($rows as $r): echo view('search/partials/result.php', ['r' => $r, 'back' => $url(['page' => $page > 1 ? $page : null]), 'tz' => $tz]); endforeach; ?></div>
        <?php if ($page > 1 || $more): ?>
            <nav class="mt-3" id="search-pagination"><ul class="pagination mb-0">
                <?php if ($page > 1): ?><li class="page-item"><?= hx_link($url(['page' => $page - 1 > 1 ? $page - 1 : null]), 'Previous', 'page-link', 'id="search-prev"') ?></li><?php endif; ?>
                <?php if ($more): ?><li class="page-item"><?= hx_link($url(['page' => $page + 1]), 'Next', 'page-link', 'id="search-next"') ?></li><?php endif; ?>
            </ul></nav>
        <?php endif; ?>
    <?php endif; ?>
</div>
