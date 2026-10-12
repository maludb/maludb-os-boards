<?php
declare(strict_types=1);

/**
 * Home — the reads (slice 10, home-admin.md "Query functions"): the start page's sections, each from a walled view (mcp_boards, mcp_folders,
 * mcp_notifications), so a board one cannot open is never a row here and an external sees only what names them. At most HOME_SECTION_MAX cards each.
 */
const HOME_SECTION_MAX = 8;

/** The columns a board card reads (find_boards()'s, so slice 1's card partial draws them all). */
const HOME_BOARD_COLUMNS = 'b.board_id, b.title, b.description, b.owner_member_id, b.owner_name, b.my_level, b.is_locked, b.item_count, b.open_threads, b.last_activity_at,
                            b.is_favorite, b.background, b.updated_at, (SELECT bb.thumbnail_attachment_id FROM boards bb WHERE bb.id = b.board_id) AS thumbnail_id';

/** Up to eight boards I can open (never a template) where $where holds, in $order. */
function home_boards(PDO $pdo, string $where, string $order): array
{
    return $pdo->query('SELECT ' . HOME_BOARD_COLUMNS . " FROM mcp_boards b WHERE NOT b.is_template AND $where ORDER BY $order, b.board_id LIMIT " . HOME_SECTION_MAX)->fetchAll();
}

/**
 * The home page's lists for one member: recent (the newest visits, boards I still open), favourites, shared (boards I do not own, by activity), team_folders (a folder
 * card each), mentions_waiting (unread `mention` notices) and first_run (no board to see at all). The views judge the member; $member only names them.
 */
function home_sections(PDO $pdo, int $member): array
{
    $mentions = (int) one_value($pdo, "SELECT count(*) FROM mcp_notifications WHERE kind = 'mention' AND read_at IS NULL AND member_id = :m", ['m' => $member]);
    $recent = home_boards($pdo, 'b.my_last_visit IS NOT NULL', 'b.my_last_visit DESC');
    $mine = (int) one_value($pdo, 'SELECT count(*) FROM mcp_boards b WHERE NOT b.is_template');
    $folders = $pdo->query('SELECT f.folder_id, f.name, f.owner_member_id, o.display_name AS owner_name, f.is_team, f.board_count
                              FROM mcp_folders f LEFT JOIN members o ON o.id = f.owner_member_id WHERE f.is_team
                             ORDER BY f.name COLLATE "C", f.folder_id LIMIT ' . HOME_SECTION_MAX)->fetchAll();
    return [
        'recent'           => $recent,
        'favourites'       => home_boards($pdo, 'b.is_favorite', 'b.last_activity_at DESC'),
        'shared'           => home_boards($pdo, 'b.owner_member_id IS DISTINCT FROM app_current_member_id()', 'b.last_activity_at DESC'),
        'team_folders'     => $folders,
        'mentions_waiting' => $mentions,
        'first_run'        => $mine === 0,
    ];
}

/** The six shipped templates a first run offers (find_templates()'s rows, the shipped scope). */
function home_first_run_templates(PDO $pdo): array
{
    require_once dirname(__DIR__) . '/templates/queries.php';
    return find_templates($pdo, ['scope' => 'shipped'], 1)['rows'];
}
