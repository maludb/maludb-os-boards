<?php
/** The items against the budget as a bar (the width is the share of the hard cap): `success` under the soft budget, `warning` past it, `danger` within 10 % of the hard cap. Data: items, soft, hard, id */
$pct = max(1, min(100, (int) round($items / max(1, $hard) * 100)));
$status = budget_status((int) $items, (int) $soft, (int) $hard);
?>
<div class="progress" style="height: 8px" role="progressbar" aria-valuenow="<?= (int) $items ?>" aria-valuemin="0" aria-valuemax="<?= (int) $hard ?>" id="<?= e($id) ?>" data-status="<?= e($status) ?>" title="<?= (int) $items ?> of <?= (int) $hard ?> items (soft budget <?= (int) $soft ?>)">
    <div class="progress-bar bg-<?= e($status) ?>" style="width: <?= $pct ?>%"></div>
</div>
