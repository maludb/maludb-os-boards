<?php
/**
 * The app's top header (layout.php): the business name and the badge of the highest role, THE SEARCH BOX (a plain GET to /search — the
 * boards, items and comments the person can see; slice 9's screen), the switcher (K31), the bell, the theme toggle, the account menu
 * (My trail · My tokens · Sign out). On a phone the box folds into the magnifier (the tab bar carries Search too).
 * Data: screen (the search screen keeps its query in the box).
 */
$pdo = db();
$m = current_member();
$me = (int) ($m['id'] ?? 0);
$initials = strtoupper(mb_substr((string) ($m['display_name'] ?? '?'), 0, 1));
$roleBadge = role_badge();
$badgeKind = match ($roleBadge) { 'Boards admin', 'Super-admin' => 'danger', 'Member' => 'primary', default => 'secondary' };
$unread = $m !== null ? bell_count($pdo, $me) : 0;
$q = ($screen ?? '') === 'search' ? (string) ($_GET['q'] ?? '') : '';
?>
<!--! [Start] Header !-->
<header class="nxl-header" id="top-header">
    <div class="header-wrapper">
        <div class="header-left d-flex align-items-center gap-3">
            <a href="javascript:void(0);" class="nxl-head-mobile-toggler" id="mobile-collapse" aria-label="Menu">
                <div class="hamburger hamburger--arrowturn">
                    <div class="hamburger-box"><div class="hamburger-inner"></div></div>
                </div>
            </a>
            <div class="nxl-navigation-toggle">
                <a href="javascript:void(0);" id="menu-mini-button"><i class="feather-align-left"></i></a>
                <a href="javascript:void(0);" id="menu-expend-button" style="display: none"><i class="feather-arrow-right"></i></a>
            </div>
            <div class="header-role d-flex align-items-center" id="header-person">
                <span class="fw-semibold text-dark text-truncate" id="header-business"><?= e(business_name($pdo)) ?></span>
                <?php if ($roleBadge !== ''): ?><span class="badge bg-soft-<?= e($badgeKind) ?> text-<?= e($badgeKind) ?> ms-2 text-nowrap" id="header-role-badge"><?= e($roleBadge) ?></span><?php endif; ?>
            </div>
        </div>
        <div class="header-right ms-auto">
            <div class="d-flex align-items-center">
                <!-- THE SEARCH BOX: one search across the boards, items and comments I can see (slice 9). A plain GET form: works without JavaScript. -->
                <form class="header-search d-none d-md-block me-2" method="get" action="/search" role="search" id="header-search-form">
                    <div class="input-group input-group-sm">
                        <input type="search" name="q" class="form-control" id="header-search" placeholder="Search boards, stickies and comments" value="<?= e($q) ?>" maxlength="200" aria-label="Search boards" autocomplete="off" />
                        <button type="submit" class="btn btn-light-brand" id="header-search-btn" aria-label="Search" title="Search"><i class="feather-search"></i></button>
                    </div>
                </form>
                <div class="nxl-h-item d-md-none">
                    <a href="/search" class="nxl-head-link me-0" id="header-search-link" aria-label="Search" hx-get="/search" hx-target="#page-content" hx-push-url="/search"><i class="feather-search"></i></a>
                </div>
                <div class="nxl-h-item" id="header-bell" hx-get="/notifications/bell" hx-trigger="every 60s, notificationsChanged from:body" hx-swap="innerHTML">
                    <?= view('notifications/partials/bell.php', ['unread' => $unread]) ?>
                </div>
                <?= view('shared/app-switcher.php') ?>
                <div class="nxl-h-item dark-light-theme">
                    <a href="javascript:void(0);" class="nxl-head-link me-0 dark-button"><i class="feather-moon"></i></a>
                    <a href="javascript:void(0);" class="nxl-head-link me-0 light-button" style="display:none"><i class="feather-sun"></i></a>
                </div>
                <div class="dropdown nxl-h-item">
                    <a href="javascript:void(0);" data-bs-toggle="dropdown" role="button" data-bs-auto-close="outside" id="header-user-menu" aria-label="Your account">
                        <span class="avatar-text avatar-md user-avtar me-0"><?= e($initials) ?></span>
                    </a>
                    <div class="dropdown-menu dropdown-menu-end nxl-h-dropdown nxl-user-dropdown">
                        <div class="dropdown-header">
                            <div class="d-flex align-items-center">
                                <span class="avatar-text avatar-md user-avtar"><?= e($initials) ?></span>
                                <div class="min-w-0">
                                    <h6 class="text-dark mb-0" id="header-user-name"><?= e($m['display_name'] ?? 'Member') ?></h6>
                                    <span class="fs-12 fw-medium text-muted" id="header-user-status"><?= e($m['email'] ?? '') ?></span>
                                </div>
                            </div>
                        </div>
                        <div class="dropdown-divider"></div>
                        <a href="/activity" class="dropdown-item" hx-get="/activity" hx-target="#page-content" hx-push-url="/activity" id="header-trail-link">
                            <i class="feather-clock"></i><span>My trail</span>
                        </a>
                        <?php if (has_right('tokens.own')): ?>
                        <a href="/settings/tokens/" class="dropdown-item" hx-get="/settings/tokens/" hx-target="#page-content" hx-push-url="/settings/tokens/" id="header-tokens-link">
                            <i class="feather-key"></i><span>My tokens</span>
                        </a>
                        <?php endif; ?>
                        <div class="dropdown-divider"></div>
                        <form method="post" action="/logout.php" class="px-2">
                            <?= csrf_field() ?>
                            <button type="submit" id="header-logout-btn" class="dropdown-item border-0 bg-transparent w-100 text-start">
                                <i class="feather-log-out"></i><span>Sign out</span>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</header>
<!--! [End] Header !-->
