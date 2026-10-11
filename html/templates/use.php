<?php
declare(strict_types=1);
/**
 * Action `template_use` (log template.use — right boards.create, level view on the template): **template**, title (≤ 200; else the template's), folder (one of mine). A new private board owned by the
 * caller, made from the template (bd_board_duplicate): every item with new ids, a frame's children and a connector's ends mapped. record_id = the new board; lands on it.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/templates/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/templates/write.php';
bd_handler_begin();
$pdo = db();
require_right('boards.create');
$gate = bd_board_or_404($pdo, (string) (req_val('template') ?? req_val('board') ?? ''), 'view');
$tpl = find_template($pdo, (string) $gate['board_id']);
if ($tpl === null) {
    refuse(404, 'There is no such template.');
}
$id = (string) $tpl['board_id'];
$errors = [];
$title = bd_text('title', null, 200, 'The title', $errors);
$folder = bd_ref($pdo, 'folder', null, 'SELECT 1 FROM mcp_folders WHERE folder_id = :id', 'folder', $errors);
if ($errors !== []) {
    bd_refuse_fields($errors);
}
$new = bd_guard($pdo, static function () use ($pdo, $id, $title, $folder): string {
    $pdo->beginTransaction();
    $new = template_use($pdo, $id, (int) current_member_id(), $title, $folder);
    log_activity($pdo, 'template.use', 'board', $new, ['board_id' => $new, 'after' => ['source_board' => $id, 'title' => one_value($pdo, 'SELECT title FROM boards WHERE id = CAST(:b AS uuid)', ['b' => $new]),
        'scope' => null, 'category' => null, 'folder_id' => $folder]]);
    $pdo->commit();
    return $new;
});
bd_done('Made a board from ' . $tpl['title'], $new, bd_land('/boards/' . $new), 'boardChanged', ['board_id' => $new, 'source_board' => $id]);
