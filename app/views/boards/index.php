<?php /** Boards (screen `boards-list`): the chips, the folder, the search, then the cards. Data: filters, folder, cardsHtml, may_create, notice */ ?>
<?= view('shared/page-header.php', ['id' => 'boards', 'title' => 'Boards', 'crumbs' => [['Home', '/'], ['Boards', null]], 'back' => back_link(),
    'action' => $may_create ? hx_link('/boards/new' . (isset($filters['folder']) ? '?folder=' . (int) $filters['folder'] : ''), '<i class="feather-plus me-1"></i>New board', 'btn btn-primary btn-touch', 'id="boards-add-btn"') : '']) ?>
<div class="main-content" id="boards-list-content">
    <?= view('shared/notice.php', ['notice' => $notice, 'link' => ($_GET['notice'] ?? '') === 'deleted' ? ['/trash', 'Open the trash'] : null]) ?>
    <?= view('boards/partials/filters.php', ['filters' => $filters, 'folder' => $folder]) ?>
    <?= $cardsHtml ?>
</div>
