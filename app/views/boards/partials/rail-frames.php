<?php
/**
 * The rail's Frames panel — the navigator (slice 4): the board's frames in reading order (top to bottom, then left to right —
 * bd_board_overview()'s order) with what each holds; a click flies the canvas there and fits it. At 375 px it is the way around a board (the
 * minimap is hidden). Refreshed after changes. Data: board, frames (frames_in_order)
 */
$words = static function (array $counts): string {
    $n = array_sum($counts);
    return $n === 0 ? 'empty' : $n . ' item' . ($n === 1 ? '' : 's');
};
?>
<div id="rail-frames" class="rail-panel p-3" hx-get="/boards/<?= e($board) ?>/rail?panel=frames" hx-trigger="boards:changed from:body throttle:2s" hx-target="this" hx-swap="outerHTML">
    <?php if ($frames === []): ?>
        <p class="text-muted fs-13 mb-0" id="rail-frames-empty">No frames yet. Draw one with the Frame tool to group a part of the board.</p>
    <?php else: ?>
        <ol class="frame-list mb-0" id="rail-frames-list">
            <?php foreach ($frames as $k => $f): ?>
                <li><a href="/boards/<?= e($board) ?>?item=<?= e($f['item_id']) ?>" class="outline-item frame-item" data-item="<?= e($f['item_id']) ?>" data-fit="1" id="rail-frame-<?= (int) $k ?>">
                    <span class="frame-item-title"><?= e(trim((string) ($f['title'] ?? '')) !== '' ? (string) $f['title'] : 'Untitled frame') ?></span>
                    <span class="text-muted fs-12"><?= e($words((array) ($f['counts_by_type'] ?? []))) ?></span>
                </a></li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>
</div>
