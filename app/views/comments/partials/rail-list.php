<?php /** The rail's list of threads (cards, open first). Data: list (find_threads rows), total, tz */ ?>
<?php if ($list === []): ?>
    <div class="fs-12 text-muted" id="comment-rail-empty">No comments yet. Pick the Comment tool (Shift+C) and click the board.</div>
<?php endif; ?>
<?php foreach ($list as $r): ?>
    <?= view('comments/partials/card.php', ['r' => $r, 'tz' => $tz, 'rail' => true]) ?>
<?php endforeach; ?>
<?php if ($total > count($list)): ?>
    <div class="fs-12 text-muted"><a href="/boards/<?= e((string) ($list[0]['board_id'] ?? '')) ?>/comments" id="comment-rail-all">All <?= (int) $total ?> comments</a></div>
<?php endif; ?>
