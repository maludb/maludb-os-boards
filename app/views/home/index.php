<?php
/**
 * Home (screen `home`): New board and New from template (not for a Viewer), the mentions line, then the sections — Recent, Favourites, Shared with me, Team folders — or the first run.
 * Data: s (home_sections), templates, may_create, tz
 */
?>
<?= view('shared/page-header.php', ['id' => 'home', 'title' => 'Home', 'crumbs' => [['Home', null]]]) ?>
<div class="main-content" id="home-content">
    <?php if ($may_create && !$s['first_run']): ?>
        <div class="d-flex flex-wrap gap-2 mb-3" id="home-actions">
            <?= hx_link('/boards/new', '<i class="feather-plus me-1"></i>New board', 'btn btn-primary btn-touch', 'id="home-new-board"') ?>
            <?= hx_link('/templates', '<i class="feather-copy me-1"></i>New from template', 'btn btn-light btn-touch', 'id="home-new-from-template"') ?>
        </div>
    <?php endif; ?>
    <?php if ($s['mentions_waiting'] > 0): $n = (int) $s['mentions_waiting']; ?>
        <div class="alert alert-info d-flex align-items-center gap-2" id="home-mentions"><i class="feather-at-sign"></i>
            <span><?= hx_link('/notifications', $n . ' mention' . ($n === 1 ? '' : 's') . ' waiting', 'alert-link', 'id="home-mentions-link"') ?></span></div>
    <?php endif; ?>
    <?php if ($s['first_run']): ?>
        <?= view('home/partials/first-run.php', ['templates' => $templates, 'may_create' => $may_create]) ?>
    <?php endif; ?>
    <?php if (!$s['first_run'] || $s['team_folders'] !== []): ?>
        <?php if (!$s['first_run']): ?>
            <?= view('home/partials/section.php', ['name' => 'recent', 'title' => 'Recent', 'icon' => 'feather-clock', 'rows' => $s['recent'], 'see_all' => '/boards', 'kind' => 'board',
                'empty' => 'Boards you open show up here.', 'tz' => $tz]) ?>
            <?= view('home/partials/section.php', ['name' => 'favourites', 'title' => 'Favourites', 'icon' => 'feather-star', 'rows' => $s['favourites'], 'see_all' => '/boards?favorites=1', 'kind' => 'board',
                'empty' => 'Star a board and it stays here.', 'tz' => $tz]) ?>
            <?= view('home/partials/section.php', ['name' => 'shared', 'title' => 'Shared with me', 'icon' => 'feather-share-2', 'rows' => $s['shared'], 'see_all' => '/boards?shared=1', 'kind' => 'board',
                'empty' => 'Boards others share with you show up here.', 'tz' => $tz]) ?>
        <?php endif; ?>
        <?= view('home/partials/section.php', ['name' => 'team-folders', 'title' => 'Team folders', 'icon' => 'feather-folder', 'rows' => $s['team_folders'], 'see_all' => '/folders', 'kind' => 'folder',
            'empty' => 'Team folders gather the business\'s boards.', 'tz' => $tz]) ?>
    <?php endif; ?>
</div>
