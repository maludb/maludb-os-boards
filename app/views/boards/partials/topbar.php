<?php
/**
 * The canvas's top bar (canvas-core.md "#board-header"): back to the boards, the title, the level badge (refreshed when the relay says the
 * level changed — slice 3), who is here (slice 3), Share for `full`, Outline (always — the 375 px reader), the rail's toggle, and the board's
 * menu: Settings, History, Outline, Export, Duplicate, Save as template (the last three are slice 8's). Data: b, level, back
 */
$id = (string) $b['board_id'];
$isOwner = (int) ($b['owner_member_id'] ?? 0) === (int) current_member_id();
?>
<div class="board-topbar" id="board-header">
    <a href="<?= e($back ?? '/boards') ?>" class="btn btn-light btn-touch board-icon-btn" id="board-back" title="Back to the boards" aria-label="Back to the boards"><i class="feather-arrow-left"></i></a>
    <h1 class="board-title text-truncate mb-0" id="board-title" title="<?= e((string) $b['title']) ?>"><?= e((string) $b['title']) ?></h1>
    <span id="board-level-badge" hx-get="/boards/<?= e($id) ?>/rail?panel=badge" hx-trigger="boards:level from:body" hx-swap="innerHTML">
        <?= view('shared/level-badge.php', ['level' => $level, 'owner' => $isOwner]) ?>
    </span>
    <?php if ($b['is_locked']): ?><span class="badge text-bg-warning" id="board-locked-badge"><i class="feather-lock me-1"></i>Locked</span><?php endif; ?>
    <div class="board-presence ms-auto" id="board-presence" aria-label="Who is here"></div>
    <a href="/boards/<?= e($id) ?>/outline" class="btn btn-light btn-touch board-icon-btn" id="board-outline-link" title="Outline" aria-label="The board as a list"><i class="feather-list"></i></a>
    <?php if (bd_level_at_least($level, 'full')): ?>
        <a href="/boards/<?= e($id) ?>/share" class="btn btn-primary btn-touch" id="board-share-btn"><i class="feather-user-plus me-1"></i><span class="d-none d-sm-inline">Share</span></a>
    <?php endif; ?>
    <button type="button" class="btn btn-light btn-touch board-icon-btn d-none d-lg-inline-flex" id="board-rail-toggle" title="Show or hide the panel" aria-controls="board-rail" aria-expanded="true"><i class="feather-sidebar"></i></button>
    <div class="dropdown">
        <button type="button" class="btn btn-light btn-touch board-icon-btn" id="board-menu-btn" data-bs-toggle="dropdown" aria-expanded="false" aria-label="The board's menu"><i class="feather-more-vertical"></i></button>
        <ul class="dropdown-menu dropdown-menu-end" id="board-menu">
            <li><a class="dropdown-item" href="/boards/<?= e($id) ?>/settings" id="board-menu-settings"><i class="feather-settings me-2"></i>Settings</a></li>
            <li><a class="dropdown-item" href="/boards/<?= e($id) ?>/history" id="board-menu-history"><i class="feather-clock me-2"></i>History</a></li>
            <li><a class="dropdown-item" href="/boards/<?= e($id) ?>/outline" id="board-menu-outline"><i class="feather-list me-2"></i>Outline</a></li>
            <li><a class="dropdown-item" href="/boards/<?= e($id) ?>/comments" id="board-menu-comments"><i class="feather-message-circle me-2"></i>Comments</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><span class="dropdown-item disabled" id="board-menu-export"><i class="feather-download me-2"></i>Export <small class="text-muted">(slice 8)</small></span></li>
            <li><span class="dropdown-item disabled" id="board-menu-duplicate"><i class="feather-copy me-2"></i>Duplicate <small class="text-muted">(slice 8)</small></span></li>
            <li><span class="dropdown-item disabled" id="board-menu-template"><i class="feather-layout me-2"></i>Save as template <small class="text-muted">(slice 8)</small></span></li>
        </ul>
    </div>
</div>
