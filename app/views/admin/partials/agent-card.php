<?php
/** An agent as a card. Data: a (a mcp_members row), reach (agent_reach), week (agent_week). Ids: agent-card-{id} */
$id = (int) $a['member_id'];
$roles = is_array($a['roles_resolved'] ?? null) ? $a['roles_resolved'] : pg_text_array((string) ($a['roles_resolved'] ?? ''));
?>
<div class="card record-card h-100 mb-0" id="agent-card-<?= $id ?>"><div class="card-body py-3">
    <div class="d-flex gap-3 align-items-start">
        <span class="comment-avatar is-agent" aria-hidden="true"><?= e(mb_strtoupper(mb_substr(trim((string) $a['display_name']) ?: '?', 0, 1))) ?></span>
        <div class="min-w-0 flex-grow-1">
            <div class="d-flex flex-wrap align-items-center gap-1"><span class="fw-semibold text-break"><?= e((string) $a['display_name']) ?></span><span class="badge bg-soft-info text-info">agent</span></div>
            <?php if (($a['job_title'] ?? '') !== ''): ?><div class="fs-12 text-muted"><?= e((string) $a['job_title']) ?></div><?php endif; ?>
            <?php if ($roles !== []): ?><div class="d-flex flex-wrap gap-1 mt-1"><?php foreach ($roles as $r): ?><span class="badge text-bg-light"><?= e((string) $r) ?></span><?php endforeach; ?></div><?php endif; ?>
        </div>
    </div>
    <div class="fs-13 mt-2" id="agent-card-<?= $id ?>-boards"><i class="feather-layout me-1"></i><?= (int) $reach['boards'] ?> board<?= $reach['boards'] === 1 ? '' : 's' ?> it can open</div>
    <?php if ($reach['shared'] !== []): ?>
        <div class="fs-12 text-muted mt-1" id="agent-card-<?= $id ?>-shared">Shared with it by name:
            <?php foreach ($reach['shared'] as $i => $b): ?><?= $i > 0 ? ', ' : ' ' ?><?= hx_link('/boards/' . $b['board_id'], e((string) $b['title']), '', 'class="fw-semibold"') ?><?php endforeach; ?></div>
    <?php endif; ?>
    <div class="fs-12 text-muted mt-2" id="agent-card-<?= $id ?>-week"><i class="feather-activity me-1"></i>This week: <?= (int) $week['batches'] ?> batch<?= $week['batches'] === 1 ? '' : 'es' ?> on boards, <?= (int) $week['comments'] ?> comment<?= $week['comments'] === 1 ? '' : 's' ?></div>
    <div class="d-flex flex-wrap gap-2 mt-2 fs-12" id="agent-card-<?= $id ?>-dispatches">
        <a href="/admin/dispatches?agent=<?= $id ?>&amp;status=answered" class="badge bg-soft-success text-success"><?= (int) $reach['answered'] ?> answered</a>
        <a href="/admin/dispatches?agent=<?= $id ?>&amp;status=failed" class="badge bg-soft-danger text-danger"><?= (int) $reach['failed'] ?> failed</a>
        <a href="/admin/dispatches?agent=<?= $id ?>" class="badge bg-soft-warning text-warning"><?= (int) $reach['waiting'] ?> waiting</a>
    </div>
</div></div>
