<?php /** A notice by email (the outbox). Data: title, body, link, business */ ?>
<!DOCTYPE html>
<html lang="en"><body style="margin:0;padding:24px;background:#f4f5f7;font-family:Arial,Helvetica,sans-serif;color:#1f2937">
<div style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:8px;padding:24px">
    <h2 style="margin:0 0 12px 0;font-size:18px"><?= e($title) ?></h2>
    <p style="margin:0 0 20px 0;font-size:15px;line-height:1.5"><?= nl2br(e($body)) ?></p>
    <p style="margin:0 0 24px 0"><a href="<?= e($link) ?>" style="display:inline-block;padding:10px 18px;background:#3454d1;color:#ffffff;text-decoration:none;border-radius:6px;font-size:15px">Open the board</a></p>
    <p style="margin:0;font-size:12px;color:#6b7280"><?= e($business) ?></p>
</div></body></html>
