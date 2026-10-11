<?php
/**
 * The canvas rail's History panel (html/boards/rail.php?panel=history): the board's ten newest changes as the history page draws them (Show flies to
 * one) and the way to the whole page — every change, the versions, Save this moment and Restore live there.
 * Data: b (the gate's row), rows (board_changes' first page, at most ten), total, tz
 */
$id = (string) $b['board_id'];
$q = static fn (array $over): string => '/boards/' . $id . '/history' . (($p = array_filter($over, static fn ($v) => $v !== null && $v !== '')) ? '?' . http_build_query($p) : '');
?>
<div id="rail-history" class="rail-panel p-3">
    <div class="d-flex align-items-center gap-2 mb-2">
        <span class="fw-semibold flex-grow-1">History</span>
        <a class="fs-13 fw-semibold" id="rail-history-open" href="<?= e($q([])) ?>">Versions and all changes</a>
    </div>
    <?php if ($rows === []): ?>
    <p class="text-muted fs-13 mb-0" id="rail-history-empty">Nothing has changed on this board yet.</p>
    <?php else: ?>
    <?php foreach ($rows as $r): ?><?= view('history/partials/change-row.php', ['b' => $b, 'r' => $r, 'tz' => $tz, 'q' => $q]) ?><?php endforeach; ?>
    <?php if ($total > count($rows)): ?><a class="fs-13" id="rail-history-more" href="<?= e($q([])) ?>">All <?= (int) $total ?> changes</a><?php endif; ?>
    <?php endif; ?>
</div>
