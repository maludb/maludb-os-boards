<?php /** A folder's form (screens `folder-add`, `folder-edit`): the name, and Team (seen by every Member — boards.create). Data: f (row or null), v, may_team, errors */
$editing = $f !== null;
?>
<?= view('shared/page-header.php', ['id' => $editing ? 'folder-edit' : 'folder-add', 'title' => $editing ? 'Rename ' . $f['name'] : 'New folder',
    'crumbs' => $editing ? [['Folders', '/folders'], [(string) $f['name'], '/folders/' . (int) $f['folder_id']], ['Edit', null]] : [['Folders', '/folders'], ['New folder', null]], 'back' => back_link()]) ?>
<div class="main-content" id="<?= $editing ? 'folder-edit' : 'folder-add' ?>-content">
    <div class="card"><div class="card-body">
        <form method="post" action="/folders/save.php" id="folder-form" class="page-header-form" hx-post="/folders/save.php" hx-target="#page-content" hx-swap="innerHTML">
            <?= csrf_field() ?><?php if ($editing): ?><input type="hidden" name="folder" value="<?= (int) $f['folder_id'] ?>"><?php endif; ?>
            <div class="mb-3">
                <label class="form-label" for="folder-form-field-name">Name</label>
                <input type="text" name="name" id="folder-form-field-name" class="form-control btn-touch" required maxlength="120" value="<?= e($v['name']) ?>" placeholder="Q4 planning" autofocus>
            </div>
            <?php if ($may_team): ?>
                <div class="form-check form-switch mb-3">
                    <input type="hidden" name="team" value="no">
                    <input class="form-check-input" type="checkbox" role="switch" name="team" value="yes" id="folder-form-field-team" <?= $v['is_team'] ? 'checked' : '' ?>>
                    <label class="form-check-label" for="folder-form-field-team">A team folder — every Member sees it (and only the boards in it they can open)</label>
                </div>
            <?php endif; ?>
            <button type="submit" class="btn btn-primary btn-touch" id="folder-form-save-btn"><?= $editing ? 'Save' : 'Make the folder' ?></button>
            <?= hx_link($editing ? '/folders/' . (int) $f['folder_id'] : '/folders', 'Cancel', 'btn btn-light btn-touch ms-1', 'id="folder-form-cancel"') ?>
        </form>
    </div></div>
</div>
