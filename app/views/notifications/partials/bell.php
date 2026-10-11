<?php /** The header's bell (the shell polls GET /notifications/bell every 60 s). Data: unread */ ?>
<a href="/notifications" class="nxl-head-link me-0 position-relative" id="bell" aria-label="Notifications<?= $unread > 0 ? ', ' . (int) $unread . ' unread' : '' ?>" hx-get="/notifications" hx-target="#page-content" hx-push-url="/notifications">
    <i class="feather-bell"></i><?php if ($unread > 0): ?><span class="badge text-bg-danger nxl-h-badge" id="bell-count"><?= $unread > 99 ? '99+' : (int) $unread ?></span><?php endif; ?>
</a>
