<?php
/** Add: a person or an agent (the `member` picker; an agent is chipped) or a department (the `department` picker), and the level (edit for a person or an agent, view for a department). Data: b, levels */
$id = (string) $b['board_id'];
?>
<div class="card mb-0" id="share-add"><div class="card-header"><h5 class="card-title mb-0">Add</h5></div><div class="card-body">
    <form method="post" action="/boards/share-set.php" id="share-add-member-form" class="row g-2 align-items-start mb-3" hx-post="/boards/share-set.php" hx-target="#page-content" hx-swap="innerHTML">
        <?= csrf_field() ?><input type="hidden" name="board" value="<?= e($id) ?>">
        <div class="col-12 col-md-7"><?= picker_field(['id' => 'share-add-member', 'name' => 'member', 'source' => 'member', 'placeholder' => 'A person or an agent', 'title' => 'Share with', 'required' => true]) ?></div>
        <div class="col-8 col-md-3"><select name="level" id="share-add-level" class="form-select btn-touch" aria-label="Their level"><?php foreach ($levels as $k => $l): ?><option value="<?= e($k) ?>" <?= $k === 'edit' ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-4 col-md-2"><button type="submit" class="btn btn-primary btn-touch w-100" id="share-add-member-btn">Share</button></div>
    </form>
    <form method="post" action="/boards/share-set.php" id="share-add-department-form" class="row g-2 align-items-start" hx-post="/boards/share-set.php" hx-target="#page-content" hx-swap="innerHTML">
        <?= csrf_field() ?><input type="hidden" name="board" value="<?= e($id) ?>">
        <div class="col-12 col-md-7"><?= picker_field(['id' => 'share-add-department', 'name' => 'department', 'source' => 'department', 'placeholder' => 'A department', 'title' => 'Share with a department', 'required' => true]) ?></div>
        <div class="col-8 col-md-3"><select name="level" id="share-add-department-level" class="form-select btn-touch" aria-label="The department's level"><?php foreach ($levels as $k => $l): ?><option value="<?= e($k) ?>" <?= $k === 'view' ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-4 col-md-2"><button type="submit" class="btn btn-primary btn-touch w-100" id="share-add-department-btn">Share</button></div>
    </form>
</div></div>
