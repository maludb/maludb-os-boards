<?php
declare(strict_types=1);

/**
 * Templates — the reads (slice 8, templates-export.md "Query functions"): the gallery and one template through mcp_boards (the boards the caller may open,
 * `is_template` true), so a personal template is a row only for its maker and an external sees none of the shipped or the business's. Nothing here decides who
 * may open one; the view does. The SVG download of a board goes through the database's one renderer (bd_region_svg, db/013) — there is no second one.
 */
require_once dirname(__DIR__) . '/boards/queries.php';        // BOARD_PAGE, board_uuid()
require_once dirname(__DIR__) . '/history/queries.php';       // outline_html()

/** The categories, in the words of a person (boards.template_category). */
const TEMPLATE_CATEGORIES = ['retrospective' => 'Retrospective', 'brainstorm' => 'Brainstorm', 'strategy' => 'Strategy', 'planning' => 'Planning', 'diagram' => 'Diagram',
                             'workshop' => 'Workshop', 'other' => 'Other'];
/** The scopes: the value of ?scope=, its chip's words and the badge colour. */
const TEMPLATE_SCOPES = ['shipped' => ['Shipped', 'primary'], 'business' => ["The business's", 'info'], 'personal' => ['Mine', 'secondary']];
/** The feather icon of a category's tile (the gallery's picture until a template has a thumbnail). */
const TEMPLATE_ICONS = ['retrospective' => 'rotate-ccw', 'brainstorm' => 'zap', 'strategy' => 'target', 'planning' => 'calendar', 'diagram' => 'git-branch', 'workshop' => 'users', 'other' => 'layout'];
/** The tile's colour behind the icon, per category (Bootstrap soft backgrounds). */
const TEMPLATE_TILES = ['retrospective' => 'success', 'brainstorm' => 'warning', 'strategy' => 'danger', 'planning' => 'primary', 'diagram' => 'info', 'workshop' => 'secondary', 'other' => 'dark'];
/** The renderer's cap (db/013) on the items one SVG holds. */
const EXPORT_SVG_LIMIT = 400;

/** The gallery's filters from the request: category (one of the seven), scope (shipped | business | personal; "mine" means personal). */
function templates_filters_from_request(): array
{
    $f = [];
    $c = request_string('category');
    if (isset(TEMPLATE_CATEGORIES[$c])) {
        $f['category'] = $c;
    }
    $s = request_string('scope');
    $s = $s === 'mine' ? 'personal' : $s;
    if (isset(TEMPLATE_SCOPES[$s])) {
        $f['scope'] = $s;
    }
    return $f;
}

/**
 * The templates I can open, one page of 24, the shipped first and then by title: $f = category, scope. Another person's personal template is not
 * in the gallery even for a Boards admin (who can open it by its address): the gallery is for choosing one to use. ['rows' => …, 'total' => n].
 */
function find_templates(PDO $pdo, array $f, int $page): array
{
    $where = ['b.is_template', "(b.template_scope <> 'personal' OR b.owner_member_id = app_current_member_id())"];
    $args = [];
    if (isset($f['category'])) {
        $where[] = 'b.template_category = :category';
        $args['category'] = (string) $f['category'];
    }
    if (isset($f['scope'])) {
        $where[] = 'b.template_scope = :scope';
        $args['scope'] = (string) $f['scope'];
    }
    $w = implode(' AND ', $where);
    $total = (int) one_value($pdo, "SELECT count(*) FROM mcp_boards b WHERE $w", $args);
    $st = $pdo->prepare("SELECT b.board_id, b.title, b.description, b.owner_member_id, b.owner_name, b.my_level, b.template_scope, b.template_category, b.item_count,
                                b.last_activity_at, (SELECT bb.thumbnail_attachment_id FROM boards bb WHERE bb.id = b.board_id) AS thumbnail_id
                           FROM mcp_boards b WHERE $w ORDER BY (b.template_scope = 'shipped') DESC, b.title COLLATE \"C\", b.board_id
                          LIMIT " . BOARD_PAGE . ' OFFSET ' . ((max(1, $page) - 1) * BOARD_PAGE));
    $st->execute($args);
    return ['rows' => $st->fetchAll(), 'total' => $total];
}

/** One template I can open (the view's row), or null — a board that is not a template is not found here. */
function find_template(PDO $pdo, string $board): ?array
{
    $row = one_row($pdo, 'SELECT b.board_id, b.title, b.description, b.owner_member_id, b.owner_name, b.my_level, b.template_scope, b.template_category, b.item_count,
                                 b.last_activity_at, b.created_at, (SELECT bb.thumbnail_attachment_id FROM boards bb WHERE bb.id = b.board_id) AS thumbnail_id
                            FROM mcp_boards b WHERE b.board_id = CAST(:b AS uuid) AND b.is_template', ['b' => $board]);
    return $row;
}

/**
 * The board — or a frame of it — as the SVG dialect, from the database's one renderer. ['svg', 'items', 'total', 'truncated'] or null when the board or
 * the frame is not there for the caller. The whole board is the region over its extent padded by 50 (an empty board: an empty box, so an empty <svg>);
 * `truncated` is true past 400 items — the door then refuses rather than hand over part of a board as the whole.
 */
function board_export_svg(PDO $pdo, string $board, ?string $frame): ?array
{
    if ($frame !== null) {
        $r = one_value($pdo, 'SELECT bd_region_svg(CAST(:b AS uuid), NULL, NULL, NULL, NULL, ' . EXPORT_SVG_LIMIT . ', CAST(:f AS uuid))', ['b' => $board, 'f' => $frame]);
    } else {
        $ov = one_value($pdo, 'SELECT bd_board_overview(CAST(:b AS uuid))', ['b' => $board]);
        if ($ov === null) {
            return null;
        }
        $ext = json_decode((string) $ov, true)['extent'] ?? null;
        [$x1, $y1, $x2, $y2] = is_array($ext) && count($ext) === 4 ? [$ext[0] - 50, $ext[1] - 50, $ext[2] + 50, $ext[3] + 50] : [0, 0, 0, 0];
        $r = one_value($pdo, 'SELECT bd_region_svg(CAST(:b AS uuid), :x1, :y1, :x2, :y2, ' . EXPORT_SVG_LIMIT . ', NULL)', ['b' => $board, 'x1' => $x1, 'y1' => $y1, 'x2' => $x2, 'y2' => $y2]);
    }
    if ($r === null) {
        return null;
    }
    $j = json_decode((string) $r, true);
    return ['svg' => (string) $j['svg'], 'items' => (int) $j['items'], 'total' => (int) $j['total'], 'truncated' => (bool) $j['truncated']];
}

/** A frame's title, for a file's name; null when it is not a live frame of that board the caller may see. */
function frame_title(PDO $pdo, string $board, string $frame): ?string
{
    $t = one_value($pdo, "SELECT COALESCE(NULLIF(i.data->>'title', ''), 'Untitled frame') FROM board_items i
                           JOIN mcp_boards b ON b.board_id = i.board_id WHERE i.id = CAST(:f AS uuid) AND i.board_id = CAST(:b AS uuid) AND i.type = 'frame' AND i.deleted_at IS NULL",
        ['f' => $frame, 'b' => $board]);
    return $t === null ? null : (string) $t;
}
