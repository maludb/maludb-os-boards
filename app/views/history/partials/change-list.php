<?php
/**
 * The changes' rows grouped by day, with the "Since your last visit" divider, and the next page's button. Data: b, rows, last_visit (string|null), tz, page, total, f, q (url builder), keep
 */
$id = (string) $b['board_id'];
$day = null;
$dividerShown = $last_visit === null;
$more = $page * CHANGE_PAGE < $total;
?>
<div id="history-changes-list-<?= (int) $page ?>">
<?php foreach ($rows as $r):
    $d = format_ts($r['at'], $tz, 'D, M j');
    if (!$dividerShown && strtotime((string) $r['at']) <= strtotime((string) $last_visit)): $dividerShown = true; ?>
        <div class="d-flex align-items-center gap-2 my-2 text-primary fs-12 fw-semibold" id="history-last-visit" role="separator"><hr class="flex-grow-1 m-0"><span>Since your last visit</span><hr class="flex-grow-1 m-0"></div>
    <?php endif;
    if ($d !== $day): $day = $d; ?><div class="fs-12 fw-semibold text-muted mt-3 mb-1 history-day"><?= e($d) ?></div><?php endif; ?>
    <?= view('history/partials/change-row.php', ['b' => $b, 'r' => $r, 'tz' => $tz, 'f' => $f, 'q' => $q]) ?>
<?php endforeach; ?>
<?php if ($more): $next = $q($keep + ['page' => $page + 1]); ?>
    <div class="text-center my-3" id="history-more-<?= (int) $page ?>">
        <a class="btn btn-light btn-touch" id="history-more" href="<?= e($next) ?>" hx-get="<?= e($q($keep + ['part' => 'changes', 'page' => $page + 1])) ?>" hx-target="#history-more-<?= (int) $page ?>" hx-swap="outerHTML">Older changes</a>
    </div>
<?php endif; ?>
</div>
