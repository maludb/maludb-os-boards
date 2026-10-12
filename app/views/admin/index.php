<?php
/**
 * The admin's page (screen `admin`): sections by right — Usage, The trash, Agents, Most active this month, Settings. Data: may, d (usage, budget, trash, agents, active), tz, notice
 */
$tile = static fn (string $id, string $label, int $n, string $url, string $tone = ''): string => '<div class="col"><a href="' . e($url) . '" hx-get="' . e($url) . '" hx-target="#page-content" hx-swap="innerHTML" hx-push-url="' . e($url)
    . '" class="card record-card mb-0 h-100 text-decoration-none" id="' . e($id) . '"><div class="card-body py-3"><div class="fs-4 fw-semibold ' . $tone . '">' . number_format($n) . '</div><div class="fs-12 text-muted">' . e($label) . '</div></div></a></div>';
?>
<?= view('shared/page-header.php', ['id' => 'admin', 'title' => 'Admin', 'crumbs' => [['Home', '/'], ['Admin', null]], 'back' => back_link()]) ?>
<div class="main-content" id="admin-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?php if ($may['usage']): $u = $d['usage']; ?>
        <section class="mb-4" id="admin-section-usage">
            <div class="d-flex align-items-center justify-content-between mb-2"><h6 class="mb-0"><i class="feather-bar-chart-2 me-2"></i>Usage</h6>
                <?= hx_link('/admin/usage', 'All boards', 'fs-12 fw-semibold', 'id="admin-section-usage-all"') ?></div>
            <div class="row row-cols-2 row-cols-md-3 row-cols-xl-5 g-2">
                <?= $tile('admin-usage-boards', 'boards', $u['boards'], '/admin/usage') ?>
                <?= $tile('admin-usage-items', 'items in all', $u['items'], '/admin/usage') ?>
                <?= $tile('admin-usage-over', 'over the soft budget (' . $d['budget'][0] . ')', $u['over_soft'], '/admin/usage?sort=items', $u['over_soft'] > 0 ? 'text-warning' : '') ?>
                <?= $tile('admin-usage-idle', 'idle for 90 days', $u['idle_90'], '/admin/usage?sort=idle&idle_days=90') ?>
                <?= $tile('admin-usage-left', 'whose owner has left', $u['owner_left'], '/admin/usage?owner_left=1', $u['owner_left'] > 0 ? 'text-danger' : '') ?>
            </div>
        </section>
    <?php endif; ?>
    <?php if ($may['trash']): ?>
        <section class="mb-4" id="admin-section-trash">
            <h6 class="mb-2" id="trash"><i class="feather-trash-2 me-2"></i>The trash</h6>
            <?php if ($d['trash'] === []): ?>
                <div class="fs-12 text-muted" id="admin-trash-empty">The trash is empty. A deleted board waits here for its days, then it is purged with everything on it.</div>
            <?php else: ?>
                <div class="row row-cols-1 row-cols-md-2 row-cols-xl-3 g-2" id="admin-trash-grid">
                    <?php foreach ($d['trash'] as $t): echo view('admin/partials/trash-row.php', ['t' => $t, 'may_purge' => $may['purge'], 'tz' => $tz]); endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>
    <?php if ($may['agents']): $a = $d['agents']; ?>
        <section class="mb-4" id="admin-section-agents">
            <div class="d-flex align-items-center justify-content-between mb-2"><h6 class="mb-0"><i class="feather-cpu me-2"></i>Agents</h6>
                <?= hx_link('/admin/agents', 'The agents', 'fs-12 fw-semibold', 'id="admin-section-agents-all"') ?></div>
            <div class="row row-cols-2 row-cols-md-3 g-2">
                <?= $tile('admin-agents-failed', 'dispatches failed', $a['failed'], '/admin/dispatches?status=failed', $a['failed'] > 0 ? 'text-danger' : '') ?>
                <?= $tile('admin-agents-waiting', 'waiting for an approval', $a['waiting'], '/admin/dispatches?status=awaiting_approval', $a['waiting'] > 0 ? 'text-warning' : '') ?>
            </div>
        </section>
    <?php endif; ?>
    <?php if ($may['active']): ?>
        <section class="mb-4" id="admin-section-active">
            <h6 class="mb-2"><i class="feather-activity me-2"></i>Most active this month</h6>
            <?php if ($d['active'] === []): ?>
                <div class="fs-12 text-muted" id="admin-active-empty">No board has been worked on in the last 30 days.</div>
            <?php else: ?>
                <div class="card mb-0"><ul class="list-group list-group-flush" id="admin-active-list">
                    <?php foreach ($d['active'] as $b): ?>
                        <li class="list-group-item d-flex flex-wrap justify-content-between gap-2" id="admin-active-<?= e((string) $b['board_id']) ?>">
                            <?= hx_link(with_back('/boards/' . $b['board_id'], '/admin'), e((string) $b['title']), 'text-dark fw-semibold text-break') ?>
                            <span class="fs-12 text-muted"><?= (int) $b['batches'] ?> change<?= (int) $b['batches'] === 1 ? '' : 's' ?> · <?= (int) $b['members'] ?> <?= (int) $b['members'] === 1 ? 'person' : 'people' ?> · <?= e(ago_words($b['last_at']) ?? '') ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul></div>
            <?php endif; ?>
        </section>
    <?php endif; ?>
    <?php if ($may['settings']): ?>
        <section class="mb-4" id="admin-section-settings">
            <h6 class="mb-2"><i class="feather-sliders me-2"></i>Settings</h6>
            <div class="fs-12 text-muted mb-2">A new board's everyone level, the limits, how long things are kept, and whether boards update live.</div>
            <?= hx_link('/admin/settings', '<i class="feather-settings me-1"></i>Open the settings', 'btn btn-light btn-touch', 'id="admin-section-settings-open"') ?>
        </section>
    <?php endif; ?>
</div>
