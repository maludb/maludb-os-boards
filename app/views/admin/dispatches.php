<?php
/** Every dispatch (screen `admin-dispatches`). Data: filters, rows, total, page, agent_name, tz, notice */
$url = static function (array $change) use ($filters): string {
    $p = array_filter(array_merge($filters, $change), static fn ($v) => $v !== null && $v !== '');
    return '/admin/dispatches' . ($p === [] ? '' : '?' . http_build_query($p));
};
$more = $page * DISPATCH_PAGE < $total;
?>
<?= view('shared/page-header.php', ['id' => 'admin-dispatches', 'title' => 'Dispatches', 'crumbs' => [['Home', '/'], ['Admin', null], ['Dispatches', null]], 'back' => back_link(),
    'action' => hx_link('/admin/agents', '<i class="feather-cpu me-1"></i>Agents', 'btn btn-light btn-sm btn-touch', 'id="admin-dispatches-agents"')]) ?>
<div class="main-content" id="admin-dispatches-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="mb-3" id="dispatches-filters">
        <div class="d-flex flex-wrap gap-1 mb-2" role="group" aria-label="Status" id="dispatches-filter-status">
            <?php foreach (['' => 'All', 'sent' => 'Sending', 'awaiting_approval' => 'Waiting for approval', 'answered' => 'Answered', 'refused' => 'Refused', 'failed' => 'Failed'] as $key => $label): $on = ($filters['status'] ?? '') === (string) $key; ?>
                <?= hx_link($url(['status' => $key === '' ? null : $key]), e($label), 'btn btn-touch btn-sm ' . ($on ? 'btn-primary' : 'btn-light'),
                    'id="dispatch-filter-status-' . ($key === '' ? 'all' : e($key)) . '"' . ($on ? ' aria-current="true"' : '')) ?>
            <?php endforeach; ?>
        </div>
        <form method="get" action="/admin/dispatches" class="d-flex flex-wrap gap-2 align-items-center" id="dispatches-filter-form">
            <?php if (isset($filters['status'])): ?><input type="hidden" name="status" value="<?= e($filters['status']) ?>"><?php endif; ?>
            <div class="flex-grow-1" style="max-width: 24rem"><?= picker_field(['id' => 'dispatch-filter-agent', 'name' => 'agent', 'source' => 'member', 'params' => ['kind' => 'agent'], 'value' => $filters['agent'] ?? null,
                'label' => $agent_name, 'placeholder' => 'Any agent', 'title' => 'Agents', 'search' => 'Search agents…']) ?></div>
            <button type="submit" class="btn btn-light btn-touch" id="dispatch-filter-apply">Show</button>
        </form>
    </div>
    <div class="fs-12 text-muted mb-2" id="dispatches-total"><?= (int) $total ?> dispatch<?= $total === 1 ? '' : 'es' ?></div>
    <?php if ($rows === []): ?>
        <div class="card"><div class="card-body"><div class="empty-state" id="dispatches-empty"><span class="avatar-text avatar-lg rounded"><i class="feather-send"></i></span>
            <div class="min-w-0"><div class="fw-semibold"><?= $filters !== [] ? 'No dispatch matches' : 'Nothing has been dispatched yet' ?></div>
                <div class="fs-12 text-muted">A comment that mentions an agent who can see the board is dispatched to it.</div></div></div></div></div>
    <?php else: ?>
        <div id="dispatches-list"><?php foreach ($rows as $r): echo view('admin/partials/dispatch-row.php', ['r' => $r, 'tz' => $tz]); endforeach; ?></div>
        <?php if ($page > 1 || $more): ?>
            <nav class="mt-3" id="dispatches-pagination"><ul class="pagination mb-0">
                <?php if ($page > 1): ?><li class="page-item"><?= hx_link($url(['page' => $page - 1 > 1 ? $page - 1 : null]), 'Newer', 'page-link', 'id="dispatches-prev"') ?></li><?php endif; ?>
                <?php if ($more): ?><li class="page-item"><?= hx_link($url(['page' => $page + 1]), 'Older', 'page-link', 'id="dispatches-next"') ?></li><?php endif; ?>
            </ul></nav>
        <?php endif; ?>
    <?php endif; ?>
</div>
