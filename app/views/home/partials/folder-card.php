<?php
/** A team folder as a card on the home page (the folders list's look): the name as a link carrying ?back=, "Team", the boards in it. Data: f (a home_sections team_folders row), back */
$n = (int) $f['board_count'];
?>
<div class="card record-card mb-0 h-100" id="home-folder-<?= (int) $f['folder_id'] ?>"><div class="card-body py-3 d-flex gap-3 align-items-start">
    <span class="avatar-text avatar-lg rounded flex-shrink-0"><i class="feather-folder"></i></span>
    <div class="min-w-0 flex-grow-1">
        <div class="fw-semibold text-break"><?= hx_link(with_back('/folders/' . (int) $f['folder_id'], $back), e((string) $f['name']), 'text-dark', 'id="home-folder-' . (int) $f['folder_id'] . '-link"') ?></div>
        <div class="d-flex flex-wrap gap-1 mt-1"><span class="badge text-bg-info">Team</span><span class="fs-12 text-muted"><?= $n ?> board<?= $n === 1 ? '' : 's' ?></span></div>
    </div>
</div></div>
