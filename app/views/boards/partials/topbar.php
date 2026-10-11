<?php
/**
 * The canvas's top bar (canvas-core.md "#board-header"): back to the boards, the title, the level badge (refreshed when the relay says the
 * level changed), who is here (the presence strip, filled by the island from the relay), Share for `full`, Outline (always — the 375 px reader), the rail's toggle, and the board's
 * menu: Settings, History, Outline, Export (slice 8: the PNGs from the island, the SVG from the server), Duplicate, Save as template (slice 8: links to the settings page's forms). Data: b, level, back
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
    <?= view('boards/partials/presence.php') ?>
    <a href="/boards/<?= e($id) ?>/outline" class="btn btn-light btn-touch board-icon-btn" id="board-outline-link" title="Outline" aria-label="The board as a list"><i class="feather-list"></i></a>
    <?php if (bd_level_at_least($level, 'full')): ?>
        <a href="/boards/<?= e($id) ?>/share" class="btn btn-primary btn-touch" id="board-share-btn"><i class="feather-user-plus me-1"></i><span class="d-none d-sm-inline">Share</span></a>
    <?php endif; ?>
    <button type="button" class="btn btn-light btn-touch board-icon-btn d-none d-lg-inline-flex" id="board-rail-toggle" title="Show or hide the panel" aria-controls="board-rail" aria-expanded="true"><i class="feather-sidebar"></i></button>
    <div class="dropdown">
        <button type="button" class="btn btn-light btn-touch board-icon-btn" id="board-menu-btn" data-bs-toggle="dropdown" aria-expanded="false" aria-label="The board's menu"><i class="feather-more-vertical"></i></button>
        <ul class="dropdown-menu dropdown-menu-end" id="board-menu">
            <li class="d-lg-none"><button type="button" class="dropdown-item" id="board-menu-frames" data-open-panel="frames"><i class="feather-layout me-2"></i>Frames</button></li>
            <li><a class="dropdown-item" href="/boards/<?= e($id) ?>/settings" id="board-menu-settings"><i class="feather-settings me-2"></i>Settings</a></li>
            <li><a class="dropdown-item" href="/boards/<?= e($id) ?>/history" id="board-menu-history"><i class="feather-clock me-2"></i>History</a></li>
            <li><a class="dropdown-item" href="/boards/<?= e($id) ?>/outline" id="board-menu-outline"><i class="feather-list me-2"></i>Outline</a></li>
            <li><a class="dropdown-item" href="/boards/<?= e($id) ?>/comments" id="board-menu-comments"><i class="feather-message-circle me-2"></i>Comments</a></li>
            <li><hr class="dropdown-divider"></li>
            <?php if (has_right('boards.export')): ?>
            <li id="export-menu">
                <h6 class="dropdown-header">Export</h6>
                <ul class="list-unstyled mb-0">
                    <li><button type="button" class="dropdown-item" id="export-png-view" data-export="png-view"><i class="feather-image me-2"></i>PNG of the view</button></li>
                    <li><button type="button" class="dropdown-item disabled" id="export-png-selection" data-export="png-selection" aria-disabled="true"><i class="feather-image me-2"></i>PNG of the selection</button></li>
                    <li><button type="button" class="dropdown-item disabled" id="export-png-frame" data-export="png-frame" aria-disabled="true"><i class="feather-image me-2"></i>PNG of a frame</button></li>
                    <li><a class="dropdown-item" id="export-svg-board" hx-boost="false" data-export="svg-board" href="/boards/<?= e($id) ?>/export?format=svg" download><i class="feather-code me-2"></i>SVG (structure) of the board</a></li>
                    <li><a class="dropdown-item disabled" id="export-svg-frame" hx-boost="false" data-export="svg-frame" href="#" aria-disabled="true" download><i class="feather-code me-2"></i>SVG (structure) of a frame</a></li>
                </ul>
            </li>
            <?php endif; ?>
            <?php if (has_right('boards.create')): ?>
            <li><a class="dropdown-item" href="/boards/<?= e($id) ?>/settings#board-actions" id="board-menu-duplicate"><i class="feather-copy me-2"></i>Duplicate</a></li>
            <li><a class="dropdown-item" href="/boards/<?= e($id) ?>/settings#board-template-form" id="board-menu-template"><i class="feather-layout me-2"></i>Save as template</a></li>
            <?php endif; ?>
        </ul>
    </div>
</div>
