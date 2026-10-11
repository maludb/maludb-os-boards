<?php /** The morning digest by email (the outbox). Data: title, items [[title, who, link, when]], more, link, business */ ?>
<!DOCTYPE html>
<html lang="en"><body style="margin:0;padding:24px;background:#f4f5f7;font-family:Arial,Helvetica,sans-serif;color:#1f2937">
<div style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:8px;padding:24px">
    <h2 style="margin:0 0 16px 0;font-size:18px"><?= e($title) ?></h2>
    <?php foreach ($items as $i): ?>
        <p style="margin:0 0 14px 0;font-size:15px;line-height:1.4">
            <a href="<?= e($i['link']) ?>" style="color:#3454d1;text-decoration:none"><?= e($i['title']) ?></a><br>
            <span style="font-size:12px;color:#6b7280"><?= $i['who'] !== '' ? e($i['who']) . ' · ' : '' ?><?= e($i['when']) ?></span>
        </p>
    <?php endforeach; ?>
    <?php if ($more > 0): ?><p style="margin:0 0 16px 0;font-size:14px">and <?= (int) $more ?> more in <a href="<?= e($link) ?>" style="color:#3454d1">your notifications</a>.</p><?php endif; ?>
    <p style="margin:16px 0 0 0;font-size:12px;color:#6b7280"><?= e($business) ?></p>
</div></body></html>
