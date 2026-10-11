<?php /** A folder (screen `folder-view`): its boards as the list's cards (only those I may open); Rename and Delete (✔ "The boards stay where they are") for its owner or a Boards admin. Data: f, cardsHtml, notice */
$fid = (int) $f['folder_id'];
$actions = '';
if ($f['may_change']) {
    $actions = hx_link('/folders/' . $fid . '/edit', '<i class="feather-edit-2 me-1"></i>Rename', 'btn btn-light btn-touch', 'id="folder-rename-btn"')
        . '<form method="post" action="/folders/delete.php" class="d-inline ms-1" hx-post="/folders/delete.php" hx-target="#page-content" hx-swap="innerHTML" hx-confirm="Delete the folder ' . e($f['name']) . '? The boards stay where they are.">'
        . csrf_field() . '<input type="hidden" name="folder" value="' . $fid . '"><button type="submit" class="btn btn-outline-danger btn-touch" id="folder-delete-btn"><i class="feather-trash-2 me-1"></i>Delete</button></form>';
}
?>
<?= view('shared/page-header.php', ['id' => 'folder-view', 'title' => (string) $f['name'], 'crumbs' => [['Folders', '/folders'], [(string) $f['name'], null]], 'back' => back_link(), 'action' => $actions]) ?>
<div class="main-content" id="folder-view-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="d-flex flex-wrap gap-1 align-items-center mb-2">
        <?php if ($f['is_team']): ?><span class="badge text-bg-info" id="folder-view-team">Team</span><?php endif; ?>
        <span class="fs-12 text-muted"><?= (int) $f['owner_member_id'] === (int) current_member_id() ? 'Your folder' : e((string) $f['owner_name']) . '\'s folder' ?> — a folder shares nothing: you see the boards in it you can open.</span>
    </div>
    <?= $cardsHtml ?>
</div>
