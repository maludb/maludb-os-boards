<?php
/** One board's row in the usage table (1280 and up). Data: r (a boards_usage row), budget [soft, hard], back */
$id = (string) $r['board_id'];
$left = !$r['owner_active'];
?>
<tr id="usage-row-<?= e($id) ?>">
    <td class="text-break"><?= hx_link(with_back('/boards/' . $id, $back), e((string) $r['title']), 'text-dark fw-semibold', 'id="usage-row-' . e($id) . '-link"') ?></td>
    <td><?= e((string) $r['owner_name']) ?><?php if ($left): ?> <span class="badge text-bg-secondary" id="usage-row-<?= e($id) ?>-left">left</span><?php endif; ?></td>
    <td style="min-width: 12rem"><div class="fs-12"><?= (int) $r['item_count'] ?> / <?= (int) $budget[0] ?></div><?= view('admin/partials/budget-bar.php', ['items' => (int) $r['item_count'], 'soft' => $budget[0], 'hard' => $budget[1], 'id' => 'usage-row-' . $id . '-bar']) ?></td>
    <td><?= (int) $r['idle_days'] ?> d</td>
    <td><?= (int) $r['open_threads'] ?></td>
    <td class="text-nowrap"><?= e(ago_words($r['last_activity_at']) ?? '') ?></td>
    <td><?php if ($left): ?><?= hx_link(with_back('/boards/' . $id . '/share', $back) . '#share-owner', 'Transfer', 'btn btn-light btn-sm btn-touch', 'id="usage-row-' . e($id) . '-transfer"') ?><?php endif; ?></td>
</tr>
