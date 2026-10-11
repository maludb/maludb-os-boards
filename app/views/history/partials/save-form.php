<?php /** Save this moment: a name up to 120 characters. Data: board */ ?>
<form method="post" action="/boards/version-save.php" hx-post="/boards/version-save.php" hx-target="#page-content" hx-swap="innerHTML" class="card mb-2" id="version-form"><div class="card-body py-2">
    <?= csrf_field() ?><input type="hidden" name="board" value="<?= e($board) ?>">
    <label class="form-label fs-12 text-muted mb-1" for="version-form-field-label">Save this moment</label>
    <div class="d-flex gap-2">
        <input type="text" class="form-control" name="label" id="version-form-field-label" maxlength="120" required placeholder="A name, e.g. Before the workshop">
        <button type="submit" class="btn btn-primary btn-touch text-nowrap" id="version-form-save">Save</button>
    </div>
</div></form>
