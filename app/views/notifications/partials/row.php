<?php
/**
 * One notice (a card row): the kind's icon, the title, the body, the board's title and when. A notice that names a board opens it at the comment — and is marked read —
 * through a POST (notification_read); unread notices are marked. Data: n (a find_my_notifications row), tz
 */
$id = (int) $n['notification_id'];
$unread = $n['read_at'] === null;
$url = notification_url($n);
?>
<div class="list-group-item notification-row<?= $unread ? ' unread' : '' ?>" id="notification-row-<?= $id ?>">
    <form method="post" action="/notifications/read.php" hx-post="/notifications/read.php" hx-target="#page-content" hx-swap="innerHTML" class="m-0">
        <?= csrf_field() ?><input type="hidden" name="notification" value="<?= $id ?>">
        <button type="submit" class="notification-open d-flex gap-3 align-items-start btn-touch" id="notification-open-<?= $id ?>" aria-label="<?= e(($unread ? 'Unread: ' : '') . $n['title']) ?>">
            <span class="avatar-text avatar-md rounded flex-shrink-0" aria-hidden="true"><i class="<?= e(notice_icon((string) $n['kind'])) ?>"></i></span>
            <span class="min-w-0 flex-grow-1">
                <span class="d-block fw-semibold text-break"><?= e($n['title']) ?><?php if ($unread): ?> <span class="badge text-bg-primary ms-1">New</span><?php endif; ?></span>
                <?php if (($n['body'] ?? '') !== ''): ?><span class="d-block fs-12 text-muted text-break"><?= e(mb_substr((string) $n['body'], 0, 200)) ?></span><?php endif; ?>
                <span class="d-block fs-11 text-muted mt-1">
                    <?php if (($n['board_title'] ?? null) !== null): ?><i class="feather-layout me-1"></i><?= e($n['board_title']) ?> · <?php elseif ($url !== null): ?><i class="feather-layout me-1"></i>a board you can no longer open · <?php endif; ?>
                    <span title="<?= e(format_ts($n['created_at'], $tz)) ?>"><?= e(ago_words($n['created_at']) ?? '') ?></span>
                </span>
            </span>
        </button>
    </form>
</div>
