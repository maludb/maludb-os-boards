<?php
/**
 * Notifications (screen `notifications`): unread first, 30 a page; a notice opens its board at the comment; Mark all read.
 * Data: rows, total, unread, page, pages, unreadOnly, tz, notice
 */
?>
<?= view('shared/page-header.php', ['id' => 'notifications', 'title' => 'Notifications', 'crumbs' => [['Home', '/'], ['Notifications', null]], 'back' => back_link(),
    'action' => $unread > 0 ? '<form method="post" action="/notifications/read-all.php" hx-post="/notifications/read-all.php" hx-target="#page-content" hx-swap="innerHTML" class="m-0">'
        . csrf_field() . '<input type="hidden" name="all" value="yes"><button type="submit" class="btn btn-primary btn-touch" id="notifications-read-all"><i class="feather-check-circle me-1"></i>Mark all read</button></form>' : '']) ?>
<div class="main-content" id="notifications-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="d-flex flex-wrap gap-2 mb-3" id="notifications-filters">
        <a class="btn btn-sm btn-touch <?= $unreadOnly ? 'btn-light' : 'btn-primary' ?>" id="notifications-filter-all" href="/notifications" hx-get="/notifications" hx-target="#page-content" hx-push-url="/notifications">All</a>
        <a class="btn btn-sm btn-touch <?= $unreadOnly ? 'btn-primary' : 'btn-light' ?>" id="notifications-filter-unread" href="/notifications?unread=1" hx-get="/notifications?unread=1" hx-target="#page-content" hx-push-url="/notifications?unread=1">Unread<?= $unread > 0 ? ' (' . (int) $unread . ')' : '' ?></a>
    </div>
    <?php if ($rows === []): ?>
        <div class="card"><div class="card-body text-muted" id="notifications-empty"><?= $unreadOnly ? 'Nothing unread.' : 'No notices yet. A mention, a reply or a shared board shows up here.' ?></div></div>
    <?php else: ?>
        <div class="list-group" id="notifications-list">
            <?php foreach ($rows as $n): ?><?= view('notifications/partials/row.php', ['n' => $n, 'tz' => $tz]) ?><?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php if ($pages > 1): ?>
    <nav class="d-flex justify-content-between align-items-center mt-3 fs-12" id="notifications-pager" aria-label="Pages">
        <?php if ($page > 1): ?><a class="btn btn-light btn-touch" id="notifications-prev" href="/notifications?<?= e(http_build_query(array_filter(['unread' => $unreadOnly ? 1 : null, 'page' => $page - 1]))) ?>"><i class="feather-arrow-left me-1"></i>Newer</a><?php else: ?><span></span><?php endif; ?>
        <span class="text-muted">Page <?= (int) $page ?> of <?= (int) $pages ?></span>
        <?php if ($page < $pages): ?><a class="btn btn-light btn-touch" id="notifications-next" href="/notifications?<?= e(http_build_query(array_filter(['unread' => $unreadOnly ? 1 : null, 'page' => $page + 1]))) ?>">Older<i class="feather-arrow-right ms-1"></i></a><?php else: ?><span></span><?php endif; ?>
    </nav>
    <?php endif; ?>
</div>
