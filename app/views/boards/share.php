<?php
/**
 * Share (screen `board-share`; walls-and-sharing.md "The share page"): the owner first (Transfer for `full`); everyone in the business (none /
 * view / comment / edit — "Externals never see a board through this"); people, agents and departments with their level and Remove; Add with
 * the record picker. Below `full` the same list, read-only. A widening of everyone's level comes back here with its question at the top.
 * Data: b (mcp_boards row), perm (board_permissions), may (full), business, notice, confirm? ['level', 'before']
 */
$id = (string) $b['board_id'];
$confirm = $confirm ?? null;
$levels = ['view' => 'Can view', 'comment' => 'Can comment', 'edit' => 'Can edit', 'full' => 'Full'];
$everyone = ['none' => 'No access', 'view' => 'Can view', 'comment' => 'Can comment', 'edit' => 'Can edit'];
$isOwner = (int) ($b['owner_member_id'] ?? 0) === (int) current_member_id();
?>
<?= view('shared/page-header.php', ['id' => 'board-share', 'title' => (string) $b['title'], 'crumbs' => [['Boards', '/boards'], [(string) $b['title'], '/boards/' . $id], ['Share', null]],
    'back' => back_link(), 'action' => view('shared/level-badge.php', ['level' => (string) $b['my_level'], 'owner' => $isOwner])]) ?>
<div class="main-content" id="board-share-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?php if ($confirm !== null): ?>
        <div class="alert alert-warning" id="share-everyone-confirm">
            <div class="fw-semibold mb-1" id="share-everyone-confirm-text">Everyone in <?= e($business) ?> will be able to <?= e(['view' => 'view', 'comment' => 'comment on', 'edit' => 'edit'][$confirm['level']] ?? 'open') ?> this board.</div>
            <div class="fs-12 mb-2">Every Member and agent with Boards opens it at that level (a Viewer at most comments); externals never do.</div>
            <form method="post" action="/boards/access-set.php" class="d-inline" hx-post="/boards/access-set.php" hx-target="#page-content" hx-swap="innerHTML">
                <?= csrf_field() ?><input type="hidden" name="board" value="<?= e($id) ?>"><input type="hidden" name="level" value="<?= e($confirm['level']) ?>"><input type="hidden" name="confirm" value="1">
                <button type="submit" class="btn btn-warning btn-touch" id="share-everyone-confirm-btn">Confirm</button>
            </form>
            <?= hx_link('/boards/' . $id . '/share', 'Cancel', 'btn btn-light btn-touch ms-1', 'id="share-everyone-cancel"') ?>
        </div>
    <?php endif; ?>
    <?php if (!$may): ?><p class="fs-12 text-muted" id="share-readonly">Only the board's owners change who can open it.</p><?php endif; ?>
    <?= view('boards/partials/owner.php', ['b' => $b, 'owner' => $perm['owner'], 'may' => $may]) ?>

    <div class="card mb-3" id="share-everyone"><div class="card-body">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
            <div class="min-w-0"><div class="fw-semibold"><i class="feather-globe me-1"></i>Everyone in <?= e($business) ?></div>
                <div class="fs-12 text-muted">Externals never see a board through this — share with them by name.</div></div>
            <?php if ($may): ?>
                <form method="post" action="/boards/access-set.php" id="share-everyone-form" hx-post="/boards/access-set.php" hx-target="#page-content" hx-swap="innerHTML" hx-trigger="change">
                    <?= csrf_field() ?><input type="hidden" name="board" value="<?= e($id) ?>">
                    <select name="level" id="share-everyone-level" class="form-select btn-touch w-auto share-level" aria-label="What everyone in the business may do">
                        <?php foreach ($everyone as $k => $l): ?><option value="<?= e($k) ?>" <?= $perm['everyone_level'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
                    </select>
                    <noscript><button type="submit" class="btn btn-light btn-sm mt-1">Save</button></noscript>
                </form>
            <?php else: ?>
                <span class="badge text-bg-secondary" id="share-everyone-level-ro"><?= e($everyone[$perm['everyone_level']] ?? 'No access') ?></span>
            <?php endif; ?>
        </div>
    </div></div>

    <div class="card mb-3" id="share-rows"><div class="card-header"><h5 class="card-title mb-0">People, agents and departments</h5></div>
        <div class="list-group list-group-flush" id="share-list">
            <?php if ($perm['rows'] === []): ?><div class="list-group-item text-muted fs-12" id="share-empty">Nobody else by name yet.</div><?php endif; ?>
            <?php foreach ($perm['rows'] as $p): ?><?= view('boards/partials/share-row.php', ['b' => $b, 'p' => $p, 'may' => $may, 'levels' => $levels]) ?><?php endforeach; ?>
        </div>
    </div>
    <?php if ($may): ?><?= view('boards/partials/share-add.php', ['b' => $b, 'levels' => $levels]) ?><?php endif; ?>
</div>
