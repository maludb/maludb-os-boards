<?php /** Folders (screen `folders`): mine and the team folders as cards — the name, "Team", the boards in it I may open; New folder. Data: folders, notice */ ?>
<?= view('shared/page-header.php', ['id' => 'folders', 'title' => 'Folders', 'crumbs' => [['Home', '/'], ['Folders', null]], 'back' => back_link(),
    'action' => hx_link('/folders/new', '<i class="feather-folder-plus me-1"></i>New folder', 'btn btn-primary btn-touch', 'id="folders-add-btn"')]) ?>
<div class="main-content" id="folders-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <p class="fs-12 text-muted">A folder only gathers boards — it shares nothing. Each person sees in a folder only the boards they can open.</p>
    <?php if ($folders === []): ?>
        <div class="card"><div class="card-body"><div class="empty-state" id="folders-empty"><span class="avatar-text avatar-lg rounded"><i class="feather-folder"></i></span>
            <div class="min-w-0"><div class="fw-semibold">No folders yet</div><div class="fs-12 text-muted mb-2">Gather boards by project, team or client.</div>
                <?= hx_link('/folders/new', '<i class="feather-folder-plus me-1"></i>New folder', 'btn btn-primary btn-touch', 'id="folders-empty-add-btn"') ?></div></div></div></div>
    <?php else: ?>
        <div class="row row-cols-1 row-cols-md-2 row-cols-xl-3 g-2" id="folders-grid">
            <?php foreach ($folders as $f): $n = (int) $f['board_count']; ?>
                <div class="col"><div class="card record-card mb-0 h-100" id="folder-card-<?= (int) $f['folder_id'] ?>"><div class="card-body py-3 d-flex gap-3 align-items-start">
                    <span class="avatar-text avatar-lg rounded flex-shrink-0"><i class="feather-folder"></i></span>
                    <div class="min-w-0 flex-grow-1">
                        <div class="fw-semibold text-break"><?= hx_link(with_back('/folders/' . (int) $f['folder_id'], '/folders'), e($f['name']), 'text-dark', 'id="folder-card-' . (int) $f['folder_id'] . '-link"') ?></div>
                        <div class="d-flex flex-wrap gap-1 mt-1">
                            <?php if ($f['is_team']): ?><span class="badge text-bg-info">Team</span><?php endif; ?>
                            <span class="fs-12 text-muted"><?= $n ?> board<?= $n === 1 ? '' : 's' ?><?= $f['mine'] ? '' : ' · ' . e((string) $f['owner_name']) . '\'s' ?></span>
                        </div>
                    </div>
                </div></div></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
