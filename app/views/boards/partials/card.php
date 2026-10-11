<?php
/**
 * A board as a card (RecordCard's look — the owner's rule for named things): the thumbnail (the canvas's preview, slice 2 — the board's
 * initial on its background colour until an editor's canvas has posted one), the title as a link carrying ?back=, the owner, the level badge, "Locked", "{n} items", "{n} open threads", "updated …",
 * the star. On a folder's page its owner sees "Remove from folder". Data: r (a find_boards row), back, folder (row or null), tz
 */
$id = (string) $r['board_id'];
$bg = is_array($r['background'] ?? null) ? $r['background'] : (json_decode((string) ($r['background'] ?? ''), true) ?: []);
$color = (string) ($bg['color'] ?? '#ffffff');
$dark = $color === '#212529';
$initial = mb_strtoupper(mb_substr(trim((string) $r['title']) ?: '?', 0, 1));
$isOwner = (int) ($r['owner_member_id'] ?? 0) === (int) current_member_id();
?>
<div class="card record-card board-card mb-0 h-100" id="board-card-<?= e($id) ?>"><div class="card-body py-3 d-flex gap-3 align-items-start">
    <a href="<?= e(with_back('/boards/' . $id, $back)) ?>" class="board-card-thumb rounded flex-shrink-0" style="background-color: <?= e($color) ?>" aria-hidden="true" tabindex="-1">
        <?php if (!empty($r['thumbnail_id'])): ?>
            <img src="/boards/<?= e($id) ?>/thumbnail?v=<?= (int) $r['thumbnail_id'] ?>" alt="" loading="lazy" id="board-card-<?= e($id) ?>-thumb">
        <?php else: ?>
            <span class="<?= $dark ? 'text-white' : 'text-dark' ?>"><?= e($initial) ?></span>
        <?php endif; ?>
    </a>
    <div class="min-w-0 flex-grow-1">
        <div class="d-flex align-items-start gap-1">
            <div class="fw-semibold text-break flex-grow-1"><?= hx_link(with_back('/boards/' . $id, $back), e((string) $r['title']), 'text-dark', 'id="board-card-' . e($id) . '-link"') ?></div>
            <?= view('boards/partials/star.php', ['id' => $id, 'on' => (bool) $r['is_favorite'], 'return' => $back]) ?>
        </div>
        <div class="fs-12 text-muted text-truncate"><i class="feather-user me-1"></i><?= e($isOwner ? 'You' : (string) ($r['owner_name'] ?? '')) ?></div>
        <div class="d-flex flex-wrap gap-1 mt-1">
            <?= view('shared/level-badge.php', ['level' => (string) $r['my_level'], 'owner' => $isOwner]) ?>
            <?php if ($r['is_locked']): ?><span class="badge text-bg-warning" id="board-card-<?= e($id) ?>-locked"><i class="feather-lock me-1"></i>Locked</span><?php endif; ?>
        </div>
        <div class="fs-11 text-muted mt-1">
            <span><?= (int) $r['item_count'] ?> item<?= (int) $r['item_count'] === 1 ? '' : 's' ?></span>
            <?php if ((int) $r['open_threads'] > 0): ?><span class="ms-2"><i class="feather-message-circle me-1"></i><?= (int) $r['open_threads'] ?> open thread<?= (int) $r['open_threads'] === 1 ? '' : 's' ?></span><?php endif; ?>
            <span class="ms-2" title="<?= e(format_ts($r['last_activity_at'], $tz)) ?>">updated <?= e(ago_words($r['last_activity_at']) ?? '') ?></span>
        </div>
        <?php if ($folder !== null && $folder['may_change']): ?>
            <form method="post" action="/folders/remove.php" class="mt-1" hx-post="/folders/remove.php" hx-target="#page-content" hx-swap="innerHTML">
                <?= csrf_field() ?><input type="hidden" name="folder" value="<?= (int) $folder['folder_id'] ?>"><input type="hidden" name="board" value="<?= e($id) ?>">
                <input type="hidden" name="return_to" value="/folders/<?= (int) $folder['folder_id'] ?>">
                <button type="submit" class="btn btn-link btn-sm p-0 fs-12 btn-touch" id="board-card-<?= e($id) ?>-unfile"><i class="feather-folder-minus me-1"></i>Remove from folder</button>
            </form>
        <?php endif; ?>
    </div>
</div></div>
