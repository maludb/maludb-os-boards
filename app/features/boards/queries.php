<?php
declare(strict_types=1);

/**
 * Boards — the reads (slice 1, boards-folders.md "Query functions"): the list as cards, one board for its settings, the trash, the `board`
 * picker. Every read goes through a walled view — mcp_boards (the boards the caller may open, with my_level), mcp_trash, mcp_folder_entries —
 * so a board one cannot open is never a row here; nothing re-implements the wall. Templates are not boards here (slice 8's gallery).
 */
const BOARD_PAGE = 24;

/** The board backgrounds: the patterns, and the eight colours of the theme a board may take (the island draws them, slice 2). */
const BOARD_PATTERNS = ['dots' => 'Dots', 'grid' => 'Grid', 'none' => 'Plain'];
const BOARD_COLORS = ['#ffffff' => 'White', '#fafaf7' => 'Paper', '#f1f3f5' => 'Grey', '#e7f5ff' => 'Blue', '#ebfbee' => 'Green', '#fff9db' => 'Yellow',
                      '#fff0f6' => 'Pink', '#212529' => 'Night'];

/** A board's uuid in canonical form, or null when the value is not one. */
function board_uuid(mixed $v): ?string
{
    $v = is_string($v) ? strtolower(trim($v)) : '';
    return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $v) ? $v : null;
}

/** The list's filters from the request: q, folder (an id), owner ('me' or an id), shared (yes), favorites (yes), updated (days: 7, 30, 90). */
function boards_filters_from_request(): array
{
    $f = [];
    $q = mb_substr(trim(request_string('q')), 0, 120);
    if ($q !== '') {
        $f['q'] = $q;
    }
    if (($folder = request_integer('folder')) !== null && $folder > 0) {
        $f['folder'] = $folder;
    }
    $owner = request_string('owner');
    if ($owner === 'me') {
        $f['owner'] = 'me';
    } elseif (ctype_digit($owner) && (int) $owner > 0) {
        $f['owner'] = (int) $owner;
    }
    foreach (['shared', 'favorites'] as $k) {
        if (in_array(request_string($k), ['1', 'yes', 'true', 'on'], true)) {
            $f[$k] = true;
        }
    }
    if (in_array($u = request_integer('updated'), [7, 30, 90], true)) {
        $f['updated'] = $u;
    }
    return $f;
}

/** The boards I can open, one page of 24, newest activity first: ['rows' => …, 'total' => n]. */
function find_boards(PDO $pdo, array $filters, int $page): array
{
    $where = ['NOT b.is_template'];
    $args = [];
    if (isset($filters['q'])) {
        $where[] = '(b.title ILIKE :q OR b.owner_name ILIKE :q)';
        $args['q'] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $filters['q']) . '%';
    }
    if (isset($filters['folder'])) {
        $where[] = 'b.board_id IN (SELECT e.board_id FROM mcp_folder_entries e WHERE e.folder_id = :folder)';
        $args['folder'] = (int) $filters['folder'];
    }
    if (isset($filters['in_folder'])) {           // a folder's own page, after find_folder() admitted the caller (a Boards admin reaches any folder)
        $where[] = 'b.board_id IN (SELECT e.board_id FROM board_folder_entries e WHERE e.folder_id = :in_folder)';
        $args['in_folder'] = (int) $filters['in_folder'];
    }
    if (($filters['owner'] ?? null) === 'me') {
        $where[] = 'b.owner_member_id = app_current_member_id()';
    } elseif (isset($filters['owner'])) {
        $where[] = 'b.owner_member_id = :owner';
        $args['owner'] = (int) $filters['owner'];
    }
    if (!empty($filters['shared'])) {
        $where[] = 'b.owner_member_id IS DISTINCT FROM app_current_member_id()';
    }
    if (!empty($filters['favorites'])) {
        $where[] = 'b.is_favorite';
    }
    if (isset($filters['updated'])) {
        $where[] = 'b.last_activity_at > now() - make_interval(days => :updated)';
        $args['updated'] = (int) $filters['updated'];
    }
    $w = implode(' AND ', $where);
    $total = (int) one_value($pdo, "SELECT count(*) FROM mcp_boards b WHERE $w", $args);
    $page = max(1, $page);
    $st = $pdo->prepare("SELECT b.board_id, b.title, b.description, b.owner_member_id, b.owner_name, b.my_level, b.is_locked, b.item_count,
                                b.open_threads, b.last_activity_at, b.is_favorite, b.background, b.updated_at
                           FROM mcp_boards b WHERE $w ORDER BY b.last_activity_at DESC, b.board_id
                          LIMIT " . BOARD_PAGE . ' OFFSET ' . (($page - 1) * BOARD_PAGE));
    $st->execute($args);
    return ['rows' => $st->fetchAll(), 'total' => $total];
}

/** One board I can open (mcp_boards) with the folders I see holding it and the board-wide level for everyone; null when I cannot open it. */
function find_board(PDO $pdo, string $boardId): ?array
{
    $st = $pdo->prepare('SELECT * FROM mcp_boards WHERE board_id = CAST(:b AS uuid)');
    $st->execute(['b' => $boardId]);
    $b = $st->fetch();
    if ($b === false) {
        return null;
    }
    $st = $pdo->prepare('SELECT f.folder_id, f.name, f.is_team, (f.owner_member_id = app_current_member_id()) AS mine
                           FROM mcp_folder_entries e JOIN mcp_folders f ON f.folder_id = e.folder_id
                          WHERE e.board_id = CAST(:b AS uuid) ORDER BY f.name COLLATE "C", f.folder_id');
    $st->execute(['b' => $boardId]);
    $b['folders'] = $st->fetchAll();
    $b['everyone_level'] = (string) (one_value($pdo, "SELECT level FROM mcp_board_permissions WHERE board_id = CAST(:b AS uuid) AND principal_kind = 'everyone'", ['b' => $boardId]) ?? 'none');
    $b['background'] = is_array($b['background']) ? $b['background'] : (json_decode((string) $b['background'], true) ?: []);
    $b['start_view'] = $b['start_view'] === null ? null : (is_array($b['start_view']) ? $b['start_view'] : json_decode((string) $b['start_view'], true));
    return $b;
}

/** My trash (a Boards admin: every deleted board), newest first: ['rows' => …, 'total' => n] — the purge date from trash_days. */
function find_trash(PDO $pdo, int $page): array
{
    $total = (int) one_value($pdo, 'SELECT count(*) FROM mcp_trash');
    $st = $pdo->prepare('SELECT t.board_id, t.title, t.owner_member_id, o.display_name AS owner_name, t.item_count, t.deleted_at, t.deleted_by,
                                d.display_name AS deleted_by_name, t.purge_after
                           FROM mcp_trash t LEFT JOIN members o ON o.id = t.owner_member_id LEFT JOIN members d ON d.id = t.deleted_by
                          ORDER BY t.deleted_at DESC, t.board_id LIMIT ' . BOARD_PAGE . ' OFFSET ' . ((max(1, $page) - 1) * BOARD_PAGE));
    $st->execute();
    return ['rows' => $st->fetchAll(), 'total' => $total];
}

/** The `board` picker: boards I can open by title; `min_level` (view | edit | full) narrows to those I hold at least at that level. */
function boards_for_pick(PDO $pdo, string $q, array $params, int $page): array
{
    $where = 'NOT is_template AND (:q = \'\' OR title ILIKE :like OR owner_name ILIKE :like)';
    $args = ['q' => $q, 'like' => picker_like($q)];
    if (in_array($params['min_level'] ?? null, ['view', 'edit', 'full'], true)) {
        $where .= ' AND bd_level_rank(my_level) >= bd_level_rank(:min)';
        $args['min'] = $params['min_level'];
    }
    $r = picker_page($pdo, "SELECT count(*) FROM mcp_boards WHERE $where",
        "SELECT board_id, title, owner_name, my_level FROM mcp_boards WHERE $where ORDER BY last_activity_at DESC, board_id", $args, $page);
    $r['rows'] = array_map(static fn (array $b): array => ['id' => (string) $b['board_id'], 'label' => (string) $b['title'], 'detail' => (string) ($b['owner_name'] ?? ''),
        'badge' => level_badge((string) $b['my_level'])[1] ?? null, 'color' => 'secondary'], $r['rows']);
    return $r;
}

/** "3 minutes ago", "yesterday", "Jan 4" — a relative time for a card. */
function ago_words(?string $ts): ?string
{
    if ($ts === null || $ts === '') {
        return null;
    }
    $t = strtotime($ts);
    $d = time() - $t;
    return match (true) {
        $d < 60 => 'just now',
        $d < 3600 => (int) floor($d / 60) . ' min ago',
        $d < 86400 => (int) floor($d / 3600) . ' h ago',
        $d < 172800 => 'yesterday',
        $d < 30 * 86400 => (int) floor($d / 86400) . ' days ago',
        default => date('M j, Y', $t),
    };
}
