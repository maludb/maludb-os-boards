<?php
declare(strict_types=1);
/**
 * GET /comments/thread.php?id=[&edit=] — one thread as the partial the comments page opens inline and the canvas's rail shows (comments/partials/thread.php):
 * `id` is any comment in it; `edit` a comment of mine to show as a form. No id: the rail's hint. The board's wall answers (a board one cannot open is a 404).
 * JSON: the thread. Not logged (a read of a part of the page).
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/comments/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/comments/present.php';
require_login();
$pdo = db();
$id = (string) ($_GET['id'] ?? '');
if ($id === '') {
    if (wants_json()) {
        json_error('invalid', 'Say which thread.', 422);
    }
    echo '<div class="comment-rail-hint text-muted fs-12 mb-3" id="comment-rail-hint">Click a pin on the board, or Open a thread from the list.</div>';
    exit;
}
$t = find_thread($pdo, $id);
if ($t === null) {
    refuse(404, 'There is no such thread.');
}
$board = (string) $t['root']['board_id'];
$level = bd_board_or_404($pdo, $board, 'view')['my_level'];
if (wants_json()) {
    respond_screen(present_thread($t));
}
$edit = isset($_GET['edit']) && board_uuid($_GET['edit']) !== null ? strtolower((string) $_GET['edit']) : null;
header('Vary: HX-Request');
echo view('comments/partials/thread.php', ['t' => $t, 'tz' => member_timezone(), 'board' => $board, 'level' => (string) $level, 'edit' => $edit, 'notes' => []]);
