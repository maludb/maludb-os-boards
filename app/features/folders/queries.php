<?php
declare(strict_types=1);

/**
 * Folders — the reads (slice 1): mine and the team folders (mcp_folders — an external sees only their own), a folder's boards through
 * mcp_folder_entries ⋈ mcp_boards, so a folder lists only the boards its viewer may open and its count says the same. Folders grant nothing.
 */

/** My folders and the team folders (or only those I may change, $ownOnly), by name. */
function find_folders(PDO $pdo, ?string $q, bool $ownOnly): array
{
    $where = ['true'];
    $args = [];
    if ($q !== null && $q !== '') {
        $where[] = 'f.name ILIKE :q';
        $args['q'] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
    }
    if ($ownOnly) {
        $where[] = '(f.owner_member_id = app_current_member_id() OR bd_is_boards_admin(app_current_member_id()))';
    }
    $st = $pdo->prepare('SELECT f.folder_id, f.name, f.owner_member_id, o.display_name AS owner_name, f.is_team, f.board_count, f.updated_at,
                                (f.owner_member_id = app_current_member_id()) AS mine
                           FROM mcp_folders f LEFT JOIN members o ON o.id = f.owner_member_id
                          WHERE ' . implode(' AND ', $where) . ' ORDER BY (f.owner_member_id = app_current_member_id()) DESC, f.name COLLATE "C", f.folder_id');
    $st->execute($args);
    return $st->fetchAll();
}

/**
 * One folder I may see, with may_change (its owner, or a Boards admin); null otherwise. A Boards admin also reaches a person's own folder
 * (the manifest's "own or boards.admin") although mcp_folders lists it only to its owner — the count still only the boards the admin may open.
 */
function find_folder(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT f.folder_id, f.name, f.owner_member_id, o.display_name AS owner_name, f.is_team, f.board_count, f.created_at, f.updated_at,
                                (f.owner_member_id = app_current_member_id() OR bd_is_boards_admin(app_current_member_id())) AS may_change
                           FROM mcp_folders f LEFT JOIN members o ON o.id = f.owner_member_id WHERE f.folder_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    if ($r === false && (bool) one_value($pdo, 'SELECT bd_is_boards_admin(app_current_member_id())')) {
        $st = $pdo->prepare('SELECT f.id AS folder_id, f.name, f.owner_member_id, o.display_name AS owner_name, f.is_team,
                                    (SELECT count(*) FROM board_folder_entries e JOIN bd_my_board_levels() v ON v.board_id = e.board_id
                                       JOIN boards b ON b.id = e.board_id AND b.deleted_at IS NULL WHERE e.folder_id = f.id) AS board_count,
                                    f.created_at, f.updated_at, true AS may_change
                               FROM board_folders f LEFT JOIN members o ON o.id = f.owner_member_id WHERE f.id = :id');
        $st->execute(['id' => $id]);
        $r = $st->fetch();
    }
    return $r === false ? null : $r;
}

/** A folder's boards as the list's cards (the ones I may open), one page. The caller has found the folder with find_folder(). */
function folder_boards(PDO $pdo, int $folderId, int $page): array
{
    return find_boards($pdo, ['in_folder' => $folderId], $page);
}

/** The `folder` picker: mine and the team folders by name; `own` = 1 narrows to those I may change (a new board's folder, add to folder). */
function folders_for_pick(PDO $pdo, string $q, array $params, int $page): array
{
    $where = '(:q = \'\' OR f.name ILIKE :like)';
    if ((int) ($params['own'] ?? 0) === 1) {
        $where .= ' AND (f.owner_member_id = app_current_member_id() OR bd_is_boards_admin(app_current_member_id()))';
    }
    $args = ['q' => $q, 'like' => picker_like($q)];
    $r = picker_page($pdo, "SELECT count(*) FROM mcp_folders f WHERE $where",
        "SELECT f.folder_id, f.name, f.is_team, f.board_count FROM mcp_folders f WHERE $where ORDER BY f.name COLLATE \"C\", f.folder_id", $args, $page);
    $r['rows'] = array_map(static fn (array $f): array => ['id' => (int) $f['folder_id'], 'label' => (string) $f['name'],
        'detail' => (int) $f['board_count'] . ' board' . ((int) $f['board_count'] === 1 ? '' : 's'), 'badge' => $f['is_team'] ? 'Team' : null, 'color' => 'info'], $r['rows']);
    return $r;
}

function present_folder(array $f): array
{
    return ['folder_id' => (int) $f['folder_id'], 'name' => $f['name'], 'is_team' => (bool) $f['is_team'], 'owner_member_id' => (int) $f['owner_member_id'],
            'owner_name' => $f['owner_name'] ?? null, 'board_count' => (int) $f['board_count'], 'url' => '/folders/' . (int) $f['folder_id']];
}
