<?php
declare(strict_types=1);
/**
 * /boards/{board}/outline — screen `board-outline` (canvas.md "Screens"): the board as a list — frames top to bottom then left to right with
 * their items, the items on the canvas, the connections as "A → B", the open comments — the 375 px reader and the screen reader's view.
 * Every item line opens the canvas at it (?item=). "Copy as Markdown" copies bd_board_outline() verbatim (decision 6). Level view.
 * Logs screen.view with the board. JSON: {board_id, title, markdown}.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/canvas/queries.php';
require_login();
$pdo = db();
$gate = bd_board_or_404($pdo, isset($_GET['board']) ? (string) $_GET['board'] : null, 'view');
$id = (string) $gate['board_id'];
$markdown = board_outline_markdown($pdo, $id) ?? '';
log_screen_view($pdo, 'board-outline', $id);
if (wants_json()) {
    respond_screen(['board_id' => $id, 'title' => (string) $gate['title'], 'markdown' => $markdown, 'url' => '/boards/' . $id . '/outline']);
}
render_screen((string) $gate['title'] . ' · Outline', view('boards/outline.php', ['b' => $gate, 'html' => board_outline_html($pdo, $id) ?? '', 'markdown' => $markdown]),
    ['activeNav' => 'boards', 'screen' => 'board-outline', 'entity' => 'board', 'recordId' => $id]);
