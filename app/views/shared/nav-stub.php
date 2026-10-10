<?php
/**
 * A placeholder screen (render_nav_stub, Phase 2): the page header and one card saying which slice builds it and what it will do (the
 * manifest's line). A board's page names the board (the wall admitted the caller) and links back to Boards. The home keeps one live link.
 * Data: screen, title, icon, slice, line, board (mcp_boards row or null)
 */
$id = $screen;
$crumbs = $board !== null
    ? [['Boards', '/boards'], [(string) $board['title'], null], [$title, null]]
    : ($screen === 'home' ? [['Home', null]] : [['Home', '/'], [$title, null]]);
?>
<?= view('shared/page-header.php', ['id' => $id, 'title' => $board !== null ? (string) $board['title'] : $title, 'crumbs' => $crumbs, 'back' => back_link()]) ?>
<div class="main-content" id="<?= e($id) ?>-content">
    <div class="card coming-card" id="nav-stub"><div class="card-body">
        <div class="empty-state"><span class="avatar-text avatar-lg rounded"><i class="<?= e($icon) ?>"></i></span>
            <div class="min-w-0"><div class="fw-semibold" id="nav-stub-title"><?= e($title) ?> is not built yet</div>
                <div class="fs-12 text-muted" id="nav-stub-line">Built by slice <?= (int) $slice ?> — <?= e(lcfirst(rtrim($line, '.'))) ?>.</div>
                <?php if ($board !== null): ?>
                    <div class="mt-2"><?= view('shared/level-badge.php', ['level' => (string) $board['my_level'], 'owner' => (int) ($board['owner_member_id'] ?? 0) === (int) current_member_id()]) ?></div>
                <?php endif; ?>
                <?php if ($screen === 'home'): ?>
                    <div class="mt-3"><?= hx_link('/boards', '<i class="feather-layout me-1"></i>Boards', 'btn btn-primary btn-touch', 'id="home-boards-link"') ?></div>
                <?php endif; ?>
            </div></div>
    </div></div>
</div>
