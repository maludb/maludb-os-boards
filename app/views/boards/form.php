<?php
/** New board (screen `board-add`): the page-header form — title, description, a folder of mine (the `folder` picker, own=1); Save lands on the board. Data: v, errors */
$err = static fn (string $k): string => isset($errors[$k]) ? '<div class="invalid-feedback d-block" id="board-form-field-' . e($k) . '-error">' . e($errors[$k]) . '</div>' : '';
?>
<?= view('shared/page-header.php', ['id' => 'board-add', 'title' => 'New board', 'crumbs' => [['Boards', '/boards'], ['New board', null]], 'back' => back_link()]) ?>
<div class="main-content" id="board-add-content">
    <div class="card"><div class="card-body">
        <form method="post" action="/boards/save.php" id="board-form" class="page-header-form" hx-post="/boards/save.php" hx-target="#page-content" hx-swap="innerHTML">
            <?= csrf_field() ?>
            <div class="mb-3">
                <label class="form-label" for="board-form-field-title">Title</label>
                <input type="text" name="title" id="board-form-field-title" class="form-control btn-touch<?= isset($errors['title']) ? ' is-invalid' : '' ?>" required maxlength="200" value="<?= e($v['title']) ?>" placeholder="Q4 retro" autofocus>
                <?= $err('title') ?>
            </div>
            <div class="mb-3">
                <label class="form-label" for="board-form-field-description">Description <span class="text-muted fs-12">(optional)</span></label>
                <textarea name="description" id="board-form-field-description" class="form-control<?= isset($errors['description']) ? ' is-invalid' : '' ?>" rows="3" maxlength="2000"><?= e($v['description']) ?></textarea>
                <?= $err('description') ?>
            </div>
            <div class="mb-3">
                <label class="form-label" id="board-form-field-folder-label">Folder <span class="text-muted fs-12">(optional)</span></label>
                <?= picker_field(['id' => 'board-form-field-folder', 'name' => 'folder', 'source' => 'folder', 'value' => $v['folder'], 'params' => ['own' => 1],
                    'placeholder' => 'No folder', 'title' => 'Your folders', 'labelledby' => 'board-form-field-folder-label', 'invalid' => isset($errors['folder'])]) ?>
                <?= $err('folder') ?>
            </div>
            <div class="d-flex flex-wrap align-items-center gap-2">
                <button type="submit" class="btn btn-primary btn-touch" id="board-form-save-btn">Make the board</button>
                <?= hx_link('/boards', 'Cancel', 'btn btn-light btn-touch', 'id="board-form-cancel"') ?>
                <?= hx_link('/templates', 'Start from a template →', 'ms-auto fs-12', 'id="board-form-template-link"') ?>
            </div>
            <p class="fs-12 text-muted mt-3 mb-0">A new board is private: only you can open it until you share it.</p>
        </form>
    </div></div>
</div>
