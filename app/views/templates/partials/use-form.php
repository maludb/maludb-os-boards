<?php
/**
 * Use a template: the title (the template's by default) and a folder of mine (the record picker) — a new private board owned by me. Data: t (the template row), suffix? ('-{id}' where several forms
 * share a page, else ''). Ids: template-use-form-{id}, template-form-field-title{suffix}, template-form-field-folder{suffix}.
 */
$id = (string) $t['board_id'];
$suffix = $suffix ?? '';
?>
<form method="post" action="/templates/use.php" id="template-use-form-<?= e($id) ?>" class="mt-2" hx-post="/templates/use.php" hx-target="#page-content" hx-swap="innerHTML">
    <?= csrf_field() ?><input type="hidden" name="template" value="<?= e($id) ?>">
    <div class="mb-2">
        <label class="form-label fs-12 mb-1" for="template-form-field-title<?= e($suffix) ?>">Title of your board</label>
        <input type="text" name="title" id="template-form-field-title<?= e($suffix) ?>" class="form-control btn-touch" maxlength="200" value="<?= e((string) $t['title']) ?>">
    </div>
    <div class="mb-2">
        <label class="form-label fs-12 mb-1" for="template-form-field-folder<?= e($suffix) ?>">Folder <span class="text-muted">(optional)</span></label>
        <?= picker_field(['id' => 'template-form-field-folder' . $suffix, 'name' => 'folder', 'source' => 'folder', 'params' => ['own' => 1], 'placeholder' => 'No folder', 'title' => 'Your folders', 'search' => 'Search your folders…']) ?>
    </div>
    <button type="submit" class="btn btn-primary btn-touch" id="template-use-btn-<?= e($id) ?>"><i class="feather-copy me-1"></i>Make my board</button>
</form>
