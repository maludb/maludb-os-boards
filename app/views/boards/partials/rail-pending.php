<?php /** A rail panel a later slice builds (Comments: slice 6; History: slice 7). Data: board, panel, slice, what */ ?>
<div id="rail-<?= e($panel) ?>" class="rail-panel p-3">
    <p class="text-muted fs-13 mb-0" id="rail-<?= e($panel) ?>-pending">Built by slice <?= (int) $slice ?> — <?= e($what) ?></p>
</div>
