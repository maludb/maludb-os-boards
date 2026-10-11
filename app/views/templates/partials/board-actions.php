<?php
/**
 * Duplicate, Save as template and — for templates.publish — Publish for the business, on a board's settings page (templates-export.md "Screens"): each a small form of its own. Duplicate: a title
 * (else "{title} (copy)") and a folder of mine, the record picker. Save as template: a title and a category — only its maker sees and uses it. Publish: the same, with a confirm — every Member will see it.
 * Data: board (the board row), level. Ids: board-actions, board-duplicate-form, board-template-form, board-publish-form, template-form-field-{name}.
 */
$id = (string) $board['board_id'];
$mayCreate = has_right('boards.create');
$mayPublish = has_right('templates.publish');
$cats = static function (string $field, string $selected): string {
    $o = '';
    foreach (TEMPLATE_CATEGORIES as $k => $label) {
        $o .= '<option value="' . e($k) . '"' . ($k === $selected ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    return '<select name="category" id="' . e($field) . '" class="form-select btn-touch">' . $o . '</select>';
};
if (!$mayCreate) { return; }
?>
<div class="card mb-3" id="board-actions"><div class="card-header"><h5 class="card-title mb-0">Copies and templates</h5></div><div class="card-body">
    <form method="post" action="/boards/duplicate.php" id="board-duplicate-form" class="mb-3 pb-3 border-bottom" hx-post="/boards/duplicate.php" hx-target="#page-content" hx-swap="innerHTML">
        <?= csrf_field() ?><input type="hidden" name="board" value="<?= e($id) ?>">
        <div class="fw-semibold fs-13 mb-1"><i class="feather-copy me-1"></i>Duplicate</div>
        <div class="mb-2">
            <label class="form-label fs-12 mb-1" for="template-form-field-title">Title of the copy</label>
            <input type="text" name="title" id="template-form-field-title" class="form-control btn-touch" maxlength="200" placeholder="<?= e((string) $board['title']) ?> (copy)">
        </div>
        <div class="mb-2">
            <label class="form-label fs-12 mb-1" for="template-form-field-folder">Folder <span class="text-muted">(optional)</span></label>
            <?= picker_field(['id' => 'template-form-field-folder', 'name' => 'folder', 'source' => 'folder', 'params' => ['own' => 1], 'placeholder' => 'No folder', 'title' => 'Your folders', 'search' => 'Search your folders…']) ?>
        </div>
        <button type="submit" class="btn btn-light btn-touch w-100" id="board-duplicate-btn"><i class="feather-copy me-1"></i>Make a copy</button>
        <p class="fs-11 text-muted mb-0 mt-1">A private board of yours, with everything on it copied.</p>
    </form>
    <form method="post" action="/templates/save.php" id="board-template-form" class="<?= $mayPublish ? 'mb-3 pb-3 border-bottom' : '' ?>" hx-post="/templates/save.php" hx-target="#page-content" hx-swap="innerHTML">
        <?= csrf_field() ?><input type="hidden" name="board" value="<?= e($id) ?>">
        <div class="fw-semibold fs-13 mb-1"><i class="feather-layout me-1"></i>Save as template</div>
        <div class="mb-2">
            <label class="form-label fs-12 mb-1" for="template-form-field-title-save">Title</label>
            <input type="text" name="title" id="template-form-field-title-save" class="form-control btn-touch" maxlength="200" required value="<?= e((string) $board['title']) ?>">
        </div>
        <div class="mb-2">
            <label class="form-label fs-12 mb-1" for="template-form-field-category-save">Category</label>
            <?= $cats('template-form-field-category-save', 'other') ?>
        </div>
        <button type="submit" class="btn btn-light btn-touch w-100" id="board-template-btn"><i class="feather-layout me-1"></i>Save as my template</button>
        <p class="fs-11 text-muted mb-0 mt-1">Only you see and use it.</p>
    </form>
    <?php if ($mayPublish): ?>
    <form method="post" action="/templates/publish.php" id="board-publish-form" hx-post="/templates/publish.php" hx-target="#page-content" hx-swap="innerHTML" hx-confirm="Every Member will see it. Publish <?= e((string) $board['title']) ?> as a template?">
        <?= csrf_field() ?><input type="hidden" name="board" value="<?= e($id) ?>">
        <div class="fw-semibold fs-13 mb-1"><i class="feather-globe me-1"></i>Publish for the business</div>
        <div class="mb-2">
            <label class="form-label fs-12 mb-1" for="template-form-field-title-publish">Title</label>
            <input type="text" name="title" id="template-form-field-title-publish" class="form-control btn-touch" maxlength="200" required value="<?= e((string) $board['title']) ?>">
        </div>
        <div class="mb-2">
            <label class="form-label fs-12 mb-1" for="template-form-field-category-publish">Category</label>
            <?= $cats('template-form-field-category-publish', 'other') ?>
        </div>
        <button type="submit" class="btn btn-primary btn-touch w-100" id="board-publish-btn"><i class="feather-globe me-1"></i>Publish as a business template</button>
        <p class="fs-11 text-muted mb-0 mt-1">Every Member will see it and may use it.</p>
    </form>
    <?php endif; ?>
</div></div>
