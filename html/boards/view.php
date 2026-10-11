<?php
declare(strict_types=1);
/**
 * /boards/{board} — screen `board-view` (canvas.md "Screens"; canvas-core.md "The island and the page"): the canvas. The board's gate at
 * `view` (below → 404), my recents, ONE `board.open` per render (never per poll), then the nxl page with the island's mount — the TypeScript
 * + Konva bundle in html/assets/boards/ reads the mount and does the rest. ?x=&y=&zoom= opens there; ?item= selects and flies to an item;
 * ?comment= (slice 6) opens a thread. An HTMX navigation is sent to a full page load: the island needs its own document. JSON: present_canvas().
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/canvas/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/canvas/present.php';
require_once dirname(__DIR__, 2) . '/app/features/canvas/write.php';
require_login();
$pdo = db();
$gate = bd_board_or_404($pdo, isset($_GET['board']) ? (string) $_GET['board'] : null, 'view');
$id = (string) $gate['board_id'];
if (is_htmx_request() && !is_htmx_boosted()) {
    header('HX-Redirect: ' . here_url());
    exit;
}
$b = board_for_canvas($pdo, $id);
if ($b === null) {
    refuse(404, 'There is no such board.');
}
if (wants_json()) {
    respond_screen(['board' => present_canvas($b)]);
}
$me = current_member();
record_open($pdo, $id, (int) $me['id']);
log_activity($pdo, 'board.open', 'board', $id, ['board_id' => $id, 'screen' => 'board-view',
    'after' => (object) array_filter(['view' => canvas_query_view(), 'item' => $_GET['item'] ?? null], static fn ($v) => $v !== null)]);
$level = (string) $b['my_level'];
render_screen((string) $b['title'], view('boards/canvas.php', ['b' => $b, 'level' => $level, 'mount' => canvas_mount($b, $level, $me),
    'bundle' => canvas_bundle(), 'back' => safe_local_path($_GET['back'] ?? null)]),
    ['activeNav' => 'boards', 'screen' => 'board-view', 'entity' => 'board', 'recordId' => $id, 'canvas' => true]);
