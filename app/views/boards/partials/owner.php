<?php
/** The owner's row first, with Transfer for `full` (the `member` picker narrowed to people; ✔; the previous owner keeps edit). Data: b, owner [member_id, display_name], may */
$id = (string) $b['board_id'];
$me = (int) current_member_id();
?>
<div class="card mb-3" id="share-owner"><div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-2">
    <div class="d-flex align-items-center gap-2 min-w-0">
        <span class="avatar-text avatar-md rounded-circle"><?= e(mb_strtoupper(mb_substr((string) ($owner['display_name'] ?? '?'), 0, 1))) ?></span>
        <div class="min-w-0"><div class="fw-semibold text-truncate" id="share-owner-name"><?= e((string) ($owner['display_name'] ?? '')) ?><?= $owner['member_id'] === $me ? ' (you)' : '' ?></div>
            <span class="badge text-bg-dark">Owner</span></div>
    </div>
    <?php if ($may): ?>
        <form method="post" action="/boards/transfer.php" id="share-owner-transfer-form" class="d-flex flex-wrap align-items-start gap-2" hx-post="/boards/transfer.php" hx-target="#page-content" hx-swap="innerHTML"
              hx-confirm="Hand <?= e($b['title']) ?> to someone else? They become its owner; you keep edit. Only they can hand it back.">
            <?= csrf_field() ?><input type="hidden" name="board" value="<?= e($id) ?>">
            <div class="share-picker"><?= picker_field(['id' => 'share-owner-transfer-to', 'name' => 'new_owner', 'source' => 'member', 'params' => ['kind' => 'human'], 'placeholder' => 'A new owner', 'title' => 'Hand the board to', 'required' => true]) ?></div>
            <button type="submit" class="btn btn-light btn-touch" id="share-owner-transfer">Transfer</button>
        </form>
    <?php endif; ?>
</div></div>
