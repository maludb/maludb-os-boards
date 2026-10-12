<?php
/** One board's card in the usage list (below 768 px). Data: r (a boards_usage row), budget [soft, hard], back */
$id = (string) $r['board_id'];
$left = !$r['owner_active'];
?>
<div class="card record-card mb-0 h-100" id="usage-card-<?= e($id) ?>"><div class="card-body py-3">
    <div class="fw-semibold text-break"><?= hx_link(with_back('/boards/' . $id, $back), e((string) $r['title']), 'text-dark', 'id="usage-card-' . e($id) . '-link"') ?></div>
    <div class="fs-12 text-muted"><i class="feather-user me-1"></i><?= e((string) $r['owner_name']) ?><?php if ($left): ?> <span class="badge text-bg-secondary" id="usage-card-<?= e($id) ?>-left">left</span><?php endif; ?></div>
    <div class="fs-12 mt-2"><?= (int) $r['item_count'] ?> items <span class="text-muted">(budget <?= (int) $budget[0] ?>)</span></div>
    <?= view('admin/partials/budget-bar.php', ['items' => (int) $r['item_count'], 'soft' => $budget[0], 'hard' => $budget[1], 'id' => 'usage-card-' . $id . '-bar']) ?>
    <div class="fs-11 text-muted mt-2"><?= (int) $r['idle_days'] ?> days idle · <?= (int) $r['open_threads'] ?> open thread<?= (int) $r['open_threads'] === 1 ? '' : 's' ?> · <?= e(ago_words($r['last_activity_at']) ?? '') ?></div>
    <?php if ($left): ?><div class="mt-2"><?= hx_link(with_back('/boards/' . $id . '/share', $back) . '#share-owner', 'Transfer', 'btn btn-light btn-sm btn-touch', 'id="usage-card-' . e($id) . '-transfer"') ?></div><?php endif; ?>
</div></div>
