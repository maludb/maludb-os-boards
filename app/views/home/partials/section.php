<?php
/**
 * One home section (id home-section-{name}): its heading with See all, then up to eight cards in the list's grid — boards through slice 1's card, folders through the folder card.
 * Data: name, title, icon, rows, see_all (url), kind ('board' | 'folder'), empty (the words when there are none), tz
 */
$here = '/';
?>
<section class="mb-4" id="home-section-<?= e($name) ?>">
    <div class="d-flex align-items-center justify-content-between mb-2">
        <h6 class="mb-0"><i class="<?= e($icon) ?> me-2"></i><?= e($title) ?></h6>
        <?= hx_link($see_all, 'See all', 'fs-12 fw-semibold', 'id="home-section-' . e($name) . '-all"') ?>
    </div>
    <?php if ($rows === []): ?>
        <div class="fs-12 text-muted" id="home-section-<?= e($name) ?>-empty"><?= e($empty) ?></div>
    <?php else: ?>
        <div class="row row-cols-1 row-cols-md-2 row-cols-xl-3 g-2">
            <?php foreach ($rows as $r): ?>
                <div class="col"><?= $kind === 'folder'
                    ? view('home/partials/folder-card.php', ['f' => $r, 'back' => $here])
                    : view('boards/partials/card.php', ['r' => $r, 'back' => $here, 'folder' => null, 'tz' => $tz]) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
