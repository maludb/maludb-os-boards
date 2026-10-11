<?php
declare(strict_types=1);
/**
 * Action `board_duplicate` (log board.duplicate — right boards.create, level view on the source): **board**, title (≤ 200; else "{title} (copy)"), folder (one of mine). A private copy owned by the
 * caller — every item with new ids, a frame's children and a connector's ends mapped (bd_board_duplicate). record_id = the new board; lands on it.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/templates/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/templates/write.php';
bd_handler_begin();
$pdo = db();
require_right('boards.create');
$b = bd_board_or_404($pdo, (string) (req_val('board') ?? ''), 'view');
$id = (string) $b['board_id'];
$errors = [];
$title = bd_text('title', null, 200, 'The title', $errors);
$folder = bd_ref($pdo, 'folder', null, 'SELECT 1 FROM mcp_folders WHERE folder_id = :id', 'folder', $errors);
if ($errors !== []) {
    bd_refuse_fields($errors);
}
$new = bd_guard($pdo, static function () use ($pdo, $id, $title, $folder): string {
    $pdo->beginTransaction();
    $new = board_duplicate($pdo, $id, (int) current_member_id(), $title, $folder);
    log_activity($pdo, 'board.duplicate', 'board', $new, ['board_id' => $new, 'after' => ['source_board' => $id, 'title' => one_value($pdo, 'SELECT title FROM boards WHERE id = CAST(:b AS uuid)', ['b' => $new]),
        'scope' => null, 'category' => null, 'folder_id' => $folder]]);
    $pdo->commit();
    return $new;
});
bd_done('Made a copy of ' . $b['title'], $new, bd_land('/boards/' . $new), 'boardChanged', ['board_id' => $new, 'source_board' => $id]);
