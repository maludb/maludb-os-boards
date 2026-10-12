<?php
/** The agents on Boards (screen `admin-agents`): one card each. Data: cards = [[agent, reach, week]], os_url */
?>
<?= view('shared/page-header.php', ['id' => 'admin-agents', 'title' => 'Agents', 'crumbs' => [['Home', '/'], ['Admin', null], ['Agents', null]], 'back' => back_link(),
    'action' => hx_link('/admin/dispatches', '<i class="feather-send me-1"></i>Dispatches', 'btn btn-light btn-sm btn-touch', 'id="admin-agents-dispatches"')]) ?>
<div class="main-content" id="admin-agents-content">
    <div class="alert alert-light border mb-3" id="admin-agents-note">Hiring an agent, what it may use, its model and its duties are kept in the OS, not here.
        <a href="<?= e($os_url) ?>" class="alert-link" id="admin-agents-os" target="_blank" rel="noopener">Open Agent HR in the OS</a>.
        An agent sees only the boards shared with it; a mention in a comment never shares one.</div>
    <?php if ($cards === []): ?>
        <div class="card"><div class="card-body"><div class="empty-state" id="admin-agents-empty"><span class="avatar-text avatar-lg rounded"><i class="feather-cpu"></i></span>
            <div class="min-w-0"><div class="fw-semibold">No agent works on Boards yet</div>
                <div class="fs-12 text-muted">When the OS grants an agent Boards, it is listed here.</div></div></div></div></div>
    <?php else: ?>
    <div class="row row-cols-1 row-cols-md-2 row-cols-xl-3 g-2" id="admin-agents-grid">
        <?php foreach ($cards as $c): echo '<div class="col">' . view('admin/partials/agent-card.php', ['a' => $c['agent'], 'reach' => $c['reach'], 'week' => $c['week']]) . '</div>'; endforeach; ?>
    </div>
    <?php endif; ?>
</div>
