<?php
/** A result as a card: the kind's icon, the board's title, the title or the excerpt with the matched words marked, the author, when. Data: r (a search() row), back, tz */
$icons = ['board' => 'feather-layout', 'item' => 'feather-square', 'comment' => 'feather-message-circle'];
$id = (string) $r['id'];
$href = with_back(search_result_url($r), $back);
$main = $r['title_html'] !== '' ? $r['title_html'] : $r['excerpt_html'];
$sub = $r['title_html'] !== '' ? $r['excerpt_html'] : '';
?>
<div class="card record-card mb-2" id="search-result-<?= e($r['kind']) ?>-<?= e($id) ?>"><div class="card-body py-3 d-flex gap-3 align-items-start">
    <span class="avatar-text avatar-lg rounded flex-shrink-0" aria-hidden="true"><i class="<?= e($icons[$r['kind']] ?? 'feather-layout') ?>"></i></span>
    <div class="min-w-0 flex-grow-1">
        <div class="fs-12 text-muted"><?= e(SEARCH_KINDS[$r['kind']] === 'Boards' ? 'Board' : ($r['kind'] === 'item' ? ucfirst(str_replace('_', ' ', (string) ($r['item_type'] ?? 'item'))) : 'Comment')) ?>
            <?php if ($r['kind'] !== 'board'): ?> · on <?= e($r['board_title']) ?><?php endif; ?></div>
        <div class="fw-semibold text-break"><?= hx_link($href, $main !== '' ? $main : e($r['board_title']), 'text-dark', 'id="search-result-' . e($r['kind']) . '-' . e($id) . '-link"') ?></div>
        <?php if ($sub !== ''): ?><div class="fs-12 text-muted text-break"><?= $sub ?></div><?php endif; ?>
        <div class="fs-11 text-muted mt-1"><?= e((string) ($r['author_name'] ?? 'Someone')) ?> · <span title="<?= e(format_ts($r['at'], $tz)) ?>"><?= e(ago_words($r['at']) ?? '') ?></span></div>
    </div>
</div></div>
