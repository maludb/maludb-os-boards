<?php
/**
 * A board's settings (screen `board-settings`): the header (crumbs Boards › title, the level badge, Share), the form — title, description,
 * background (dots / grid / plain and one of the theme's eight colours), the start view (x, y, zoom — or none) — for an editor; the folders I
 * keep it in (add with the `folder` picker, remove); Lock / Unlock and Delete for its owners; Duplicate (slice 8). Below `edit` the values
 * are read-only. Data: b (find_board), may [edit, full], errors, notice
 */
$id = (string) $b['board_id'];
$isOwner = (int) ($b['owner_member_id'] ?? 0) === (int) current_member_id();
$bg = $b['background'] + ['pattern' => 'dots', 'color' => '#ffffff'];
$sv = $b['start_view'];
$ro = !$may['edit'];
$err = static fn (string $k): string => isset($errors[$k]) ? '<div class="invalid-feedback d-block" id="board-form-field-' . e($k) . '-error">' . e($errors[$k]) . '</div>' : '';
$share = hx_link('/boards/' . $id . '/share', '<i class="feather-users me-1"></i>Share', 'btn btn-light btn-touch', 'id="board-settings-share"');
?>
<?= view('shared/page-header.php', ['id' => 'board-settings', 'title' => (string) $b['title'], 'crumbs' => [['Boards', '/boards'], [(string) $b['title'], '/boards/' . $id], ['Settings', null]],
    'back' => back_link(), 'action' => $share]) ?>
<div class="main-content" id="board-settings-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="d-flex flex-wrap align-items-center gap-2 mb-3" id="board-settings-badges">
        <?= view('shared/level-badge.php', ['level' => (string) $b['my_level'], 'owner' => $isOwner]) ?>
        <?php if ($b['is_locked']): ?><span class="badge text-bg-warning" id="board-settings-locked-badge"><i class="feather-lock me-1"></i>Locked</span><?php endif; ?>
        <span class="fs-12 text-muted"><?= (int) $b['item_count'] ?> items · owned by <?= e($isOwner ? 'you' : (string) $b['owner_name']) ?></span>
    </div>
    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card mb-0"><div class="card-header"><h5 class="card-title mb-0">The board</h5></div><div class="card-body">
                <?php if ($ro): ?><p class="fs-12 text-muted" id="board-settings-readonly">You can see this board but not change it.</p><?php endif; ?>
                <form method="post" action="/boards/save.php" id="board-form" hx-post="/boards/save.php" hx-target="#page-content" hx-swap="innerHTML">
                    <?= csrf_field() ?><input type="hidden" name="board" value="<?= e($id) ?>"><input type="hidden" name="return_to" value="/boards/<?= e($id) ?>/settings">
                    <fieldset<?= $ro ? ' disabled' : '' ?>>
                    <div class="mb-3">
                        <label class="form-label" for="board-form-field-title">Title</label>
                        <input type="text" name="title" id="board-form-field-title" class="form-control btn-touch<?= isset($errors['title']) ? ' is-invalid' : '' ?>" required maxlength="200" value="<?= e($b['title']) ?>">
                        <?= $err('title') ?>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="board-form-field-description">Description</label>
                        <textarea name="description" id="board-form-field-description" class="form-control" rows="3" maxlength="2000"><?= e($b['description'] ?? '') ?></textarea>
                        <?= $err('description') ?>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="board-form-field-background">Background</label>
                        <select name="background" id="board-form-field-background" class="form-select btn-touch">
                            <?php foreach (BOARD_PATTERNS as $k => $l): ?><option value="<?= e($k) ?>" <?= $bg['pattern'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
                        </select>
                        <div class="d-flex flex-wrap gap-2 mt-2" id="board-form-field-background_color" role="radiogroup" aria-label="Background colour">
                            <?php foreach (BOARD_COLORS as $hex => $name): ?>
                                <label class="board-swatch btn-touch" title="<?= e($name) ?>">
                                    <input type="radio" name="background_color" value="<?= e($hex) ?>" class="visually-hidden" <?= strtolower((string) $bg['color']) === $hex ? 'checked' : '' ?>>
                                    <span class="board-swatch-chip" style="background-color: <?= e($hex) ?>"></span><span class="visually-hidden"><?= e($name) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <?= $err('background') ?><?= $err('background_color') ?>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" id="board-form-field-start_view-label">The start view <span class="text-muted fs-12">(where the board opens — blank for its content)</span></label>
                        <div class="row g-2" aria-labelledby="board-form-field-start_view-label">
                            <div class="col-4"><input type="number" step="any" name="start_view_x" id="board-form-field-start_view_x" class="form-control btn-touch" placeholder="x" aria-label="x" value="<?= e($sv['x'] ?? '') ?>"></div>
                            <div class="col-4"><input type="number" step="any" name="start_view_y" id="board-form-field-start_view_y" class="form-control btn-touch" placeholder="y" aria-label="y" value="<?= e($sv['y'] ?? '') ?>"></div>
                            <div class="col-4"><input type="number" step="any" min="0.05" max="8" name="start_view_zoom" id="board-form-field-start_view_zoom" class="form-control btn-touch" placeholder="zoom" aria-label="zoom" value="<?= e($sv['zoom'] ?? '') ?>"></div>
                        </div>
                        <?= $err('start_view') ?>
                    </div>
                    <?php if (!$ro): ?><button type="submit" class="btn btn-primary btn-touch" id="board-form-save-btn">Save</button><?php endif; ?>
                    </fieldset>
                </form>
            </div></div>
        </div>
        <div class="col-lg-4">
            <div class="card mb-3" id="board-settings-folders"><div class="card-header"><h5 class="card-title mb-0">Folders</h5></div><div class="card-body">
                <?php if ($b['folders'] === []): ?><p class="fs-12 text-muted mb-2" id="board-settings-folders-empty">In no folder you can see.</p><?php endif; ?>
                <div class="d-flex flex-wrap gap-1 mb-2">
                    <?php foreach ($b['folders'] as $f): ?>
                        <span class="badge bg-soft-primary text-primary d-inline-flex align-items-center gap-1 folder-chip" id="board-settings-folder-<?= (int) $f['folder_id'] ?>">
                            <?= hx_link('/folders/' . (int) $f['folder_id'], e($f['name']), 'text-primary') ?><?php if ($f['is_team']): ?> <span class="text-info">· Team</span><?php endif; ?>
                            <?php if ($f['mine']): ?>
                                <form method="post" action="/folders/remove.php" class="d-inline" hx-post="/folders/remove.php" hx-target="#page-content" hx-swap="innerHTML">
                                    <?= csrf_field() ?><input type="hidden" name="folder" value="<?= (int) $f['folder_id'] ?>"><input type="hidden" name="board" value="<?= e($id) ?>">
                                    <input type="hidden" name="return_to" value="/boards/<?= e($id) ?>/settings">
                                    <button type="submit" class="btn btn-link p-0 text-primary" aria-label="Take it out of <?= e($f['name']) ?>" id="board-settings-folder-<?= (int) $f['folder_id'] ?>-remove"><i class="feather-x"></i></button>
                                </form>
                            <?php endif; ?>
                        </span>
                    <?php endforeach; ?>
                </div>
                <form method="post" action="/folders/add.php" id="board-settings-folder-add" hx-post="/folders/add.php" hx-target="#page-content" hx-swap="innerHTML">
                    <?= csrf_field() ?><input type="hidden" name="board" value="<?= e($id) ?>"><input type="hidden" name="return_to" value="/boards/<?= e($id) ?>/settings">
                    <?= picker_field(['id' => 'board-settings-folder-add-field', 'name' => 'folder', 'source' => 'folder', 'params' => ['own' => 1], 'placeholder' => 'Add to a folder of yours', 'title' => 'Your folders', 'required' => true]) ?>
                    <button type="submit" class="btn btn-light btn-touch mt-2" id="board-settings-folder-add-btn">Add to folder</button>
                </form>
            </div></div>
            <div class="card mb-0" id="board-settings-actions"><div class="card-header"><h5 class="card-title mb-0">The board as a whole</h5></div><div class="card-body d-grid gap-2">
                <?php if ($may['full']): ?>
                    <form method="post" action="/boards/lock.php" hx-post="/boards/lock.php" hx-target="#page-content" hx-swap="innerHTML">
                        <?= csrf_field() ?><input type="hidden" name="board" value="<?= e($id) ?>"><input type="hidden" name="locked" value="<?= $b['is_locked'] ? 'no' : 'yes' ?>">
                        <button type="submit" class="btn btn-light btn-touch w-100" id="board-settings-lock"><i class="feather-<?= $b['is_locked'] ? 'unlock' : 'lock' ?> me-1"></i><?= $b['is_locked'] ? 'Unlock' : 'Lock' ?></button>
                    </form>
                <?php endif; ?>
                <button type="button" class="btn btn-light btn-touch w-100" id="board-settings-duplicate" disabled title="Built by slice 8"><i class="feather-copy me-1"></i>Duplicate <span class="fs-11 text-muted ms-1">(built by slice 8)</span></button>
                <?php if ($may['full']): ?>
                    <form method="post" action="/boards/delete.php" hx-post="/boards/delete.php" hx-target="#page-content" hx-swap="innerHTML" hx-confirm="Delete <?= e($b['title']) ?>? It moves to the trash for 30 days.">
                        <?= csrf_field() ?><input type="hidden" name="board" value="<?= e($id) ?>">
                        <button type="submit" class="btn btn-outline-danger btn-touch w-100" id="board-settings-delete"><i class="feather-trash-2 me-1"></i>Delete</button>
                    </form>
                    <p class="fs-11 text-muted mb-0">Delete moves the board to the trash for 30 days.</p>
                <?php endif; ?>
            </div></div>
        </div>
    </div>
</div>
