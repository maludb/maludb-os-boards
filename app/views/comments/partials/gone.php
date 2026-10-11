<?php /** A thread that was just removed (its root deleted — its replies went with it). Data: thread, board */ ?>
<div class="comment-thread comment-thread-gone" id="comment-thread-<?= e($thread) ?>" role="status">
    <div class="alert alert-secondary mb-0">That comment is removed. <a href="/boards/<?= e($board) ?>/comments" id="comment-gone-back">Back to the comments</a></div>
</div>
