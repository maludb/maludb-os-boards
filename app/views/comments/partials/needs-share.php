<?php
/**
 * What a comment's answer says beyond "posted": an agent mentioned who cannot see the board (a mention never grants — Share it at edit?), a person mentioned
 * who cannot (not told). Data: board, level (mine), agents ([id => name] needing a share), notShared ([names]), unresolved ([names]), shared (an agent's name just shared, or null),
 * only (render one agent's line: its id)
 */
$may = bd_level_at_least($level, 'full');
$shared = $shared ?? null;
?>
<?php foreach ($agents as $aid => $aname): ?>
    <div class="alert alert-warning py-2 px-3 mb-2 needs-share" id="needs-share-<?= (int) $aid ?>" role="status">
        <?php if ($shared === $aid): ?>
            <i class="feather-check me-1"></i><?= e($aname) ?> can see this board now (edit). Mention them again to ask.
        <?php else: ?>
            <div class="mb-1"><?= e($aname) ?> cannot see this board. <?= $may ? 'Share it at edit?' : 'Ask an owner to share it with ' . e($aname) . '.' ?></div>
            <?php if ($may): ?>
            <div class="d-flex gap-2">
                <form method="post" action="/boards/share-set.php" hx-post="/boards/share-set.php" hx-target="#needs-share-<?= (int) $aid ?>" hx-swap="outerHTML">
                    <?= csrf_field() ?><input type="hidden" name="board" value="<?= e($board) ?>"><input type="hidden" name="member" value="<?= (int) $aid ?>"><input type="hidden" name="level" value="edit">
                    <button type="submit" class="btn btn-sm btn-primary" id="needs-share-<?= (int) $aid ?>-share">Share</button>
                </form>
                <button type="button" class="btn btn-sm btn-light" id="needs-share-<?= (int) $aid ?>-skip" onclick="this.closest('.needs-share').remove()">Not now</button>
            </div>
            <?php else: ?>
            <button type="button" class="btn btn-sm btn-light" id="needs-share-<?= (int) $aid ?>-skip" onclick="this.closest('.needs-share').remove()">Not now</button>
            <?php endif; ?>
        <?php endif; ?>
    </div>
<?php endforeach; ?>
<?php foreach ($notShared as $n): ?>
    <div class="alert alert-secondary py-2 px-3 mb-2 not-shared" role="status"><?= e($n) ?> was not told — they cannot open this board.</div>
<?php endforeach; ?>
<?php if (!empty($unresolved)): ?>
    <div class="alert alert-secondary py-2 px-3 mb-2" id="comment-unresolved" role="status">More than one person or department is named <?= e(implode(', ', array_map(static fn ($n) => '“' . $n . '”', $unresolved))) ?>, so it was left as text and nobody was told. Pick the name from the list to mention one.</div>
<?php endif; ?>
