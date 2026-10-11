<?php
declare(strict_types=1);
/**
 * GET /boards/{board}/rail?panel=item|frames|outline|comments|history|badge[&ids=a,b,…] — the canvas's right rail, ONE partial door (canvas-core.md
 * "The right rail"; decision 1). Level view. item: the selection's Properties (colour, text size, alignment, lock, front/back, delete, and
 * for one item its type, size and "Set as the start view") — every control fires `boards:command` for the island, which makes it ONE
 * batch (never a form post); outline: the board as a list; comments and history: their slices' placeholders; badge: my level's badge.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/canvas/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/canvas/present.php';
require_login();
$pdo = db();
$gate = bd_board_or_404($pdo, isset($_GET['board']) ? (string) $_GET['board'] : null, 'view');
session_write_close();
$id = (string) $gate['board_id'];
$panel = (string) ($_GET['panel'] ?? 'item');
$level = (string) $gate['my_level'];
$may = bd_level_at_least($level, 'edit') && (!$gate['is_locked'] || bd_level_at_least($level, 'full'));
switch ($panel) {
    case 'item':
        $ids = array_values(array_filter(explode(',', (string) ($_GET['ids'] ?? ''))));
        $rows = rail_selection($pdo, $id, $ids);
        echo view('boards/partials/rail-properties.php', ['board' => $id, 'rows' => $rows, 'sum' => rail_summary($rows), 'may' => $may,
            'mayStart' => bd_level_at_least($level, 'edit')]);
        break;
    case 'frames':
        require_once dirname(__DIR__, 2) . '/app/features/items/frames.php';
        echo view('boards/partials/rail-frames.php', ['board' => $id, 'frames' => frames_in_order($pdo, $id)]);
        break;
    case 'outline':
        echo view('boards/partials/rail-outline.php', ['board' => $id, 'html' => board_outline_html($pdo, $id) ?? '']);
        break;
    case 'comments':
        echo view('boards/partials/rail-pending.php', ['board' => $id, 'panel' => 'comments', 'slice' => 6, 'what' => 'Comments: threads on items and on the canvas, @mentions, resolve.']);
        break;
    case 'history':
        echo view('boards/partials/rail-pending.php', ['board' => $id, 'panel' => 'history', 'slice' => 7, 'what' => 'History: every change, versions, restore.']);
        break;
    case 'badge':
        echo view('shared/level-badge.php', ['level' => $level, 'owner' => (int) ($gate['owner_member_id'] ?? 0) === (int) current_member_id()]);
        break;
    default:
        refuse(404, 'There is no such panel.');
}
