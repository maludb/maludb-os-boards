<?php
declare(strict_types=1);

/**
 * Sharing — the reads (slice 1; walls-and-sharing.md "The share page"): who can open a board and at what level. mcp_board_permissions
 * answers only for a board the caller may open; the owner comes from mcp_boards. Each person's or agent's row says whether they can open
 * Boards at all yet (`needs_grant`: no Boards grant — the super-admin's to give in the OS) and whether a role caps them (a Viewer: at most
 * comment). Nothing here decides a level.
 */

/** The share page's rows: ['owner' => {member_id, display_name}, 'everyone_level' => …, 'rows' => [{principal_kind, principal_id, principal_name, level, needs_grant, is_external, is_viewer, granted_by, created_at}]]. */
function board_permissions(PDO $pdo, string $boardId): array
{
    $owner = $pdo->prepare('SELECT b.owner_member_id, b.owner_name FROM mcp_boards b WHERE b.board_id = CAST(:b AS uuid)');
    $owner->execute(['b' => $boardId]);
    $o = $owner->fetch() ?: ['owner_member_id' => null, 'owner_name' => null];
    $st = $pdo->prepare("SELECT p.principal_kind, p.principal_id, p.principal_name, p.level, p.granted_by, p.created_at,
                                CASE WHEN p.principal_kind IN ('member', 'agent')
                                     THEN NOT EXISTS (SELECT 1 FROM members m WHERE m.id = p.principal_id AND m.status = 'active' AND m.capability IS NOT NULL) ELSE false END AS needs_grant,
                                COALESCE(m.is_external, false) AS is_external,
                                (p.principal_kind = 'member' AND bd_level_cap(p.principal_id) = 'comment') AS is_viewer
                           FROM mcp_board_permissions p
                           LEFT JOIN members m ON p.principal_kind IN ('member', 'agent') AND m.id = p.principal_id
                          WHERE p.board_id = CAST(:b AS uuid) AND p.principal_kind <> 'everyone'
                          ORDER BY CASE p.principal_kind WHEN 'member' THEN 1 WHEN 'agent' THEN 2 ELSE 3 END, p.principal_name, p.principal_id");
    $st->execute(['b' => $boardId]);
    $everyone = one_value($pdo, "SELECT level FROM mcp_board_permissions WHERE board_id = CAST(:b AS uuid) AND principal_kind = 'everyone'", ['b' => $boardId]);
    return ['owner' => ['member_id' => $o['owner_member_id'] !== null ? (int) $o['owner_member_id'] : null, 'display_name' => $o['owner_name']],
            'everyone_level' => (string) ($everyone ?? 'none'), 'rows' => $st->fetchAll()];
}

/** A member id's kind for a share row: 'agent' for an agent, 'member' for a person, null when the mirror knows no such member. */
function share_principal_kind(PDO $pdo, int $memberId): ?string
{
    $k = one_value($pdo, 'SELECT member_kind FROM members WHERE id = :m', ['m' => $memberId]);
    return $k === null ? null : ($k === 'agent' ? 'agent' : 'member');
}

/** A share row's JSON (board_access's row shape). */
function present_permission(array $p): array
{
    return ['principal_kind' => $p['principal_kind'], 'principal_id' => $p['principal_id'] !== null ? (int) $p['principal_id'] : null, 'principal_name' => $p['principal_name'],
            'level' => $p['level'], 'needs_grant' => (bool) $p['needs_grant'], 'is_external' => (bool) $p['is_external'], 'is_viewer' => (bool) $p['is_viewer'],
            'granted_by' => $p['granted_by'] !== null ? (int) $p['granted_by'] : null, 'created_at' => json_ts($p['created_at'])];
}

/**
 * The principal a share action names (board_share, board_unshare): exactly one of `member` (a person or an agent — the mirror says which,
 * and the row is written under that kind) or `department`. Answers [kind, id, name] or refuses on the field.
 */
function share_principal_from_request(PDO $pdo): array
{
    $member = (string) (req_val('member') ?? '');
    $department = (string) (req_val('department') ?? '');
    if (($member === '') === ($department === '')) {
        bd_refuse_fields(['member' => 'Name one person or agent, or one department.']);
    }
    if ($member !== '') {
        if (!ctype_digit($member) || ($kind = share_principal_kind($pdo, (int) $member)) === null) {
            bd_refuse_fields(['member' => 'There is no such person or agent.']);
        }
        return [$kind, (int) $member, (string) one_value($pdo, 'SELECT display_name FROM members WHERE id = :m', ['m' => (int) $member])];
    }
    if (!ctype_digit($department) || ($name = one_value($pdo, 'SELECT name FROM mcp_departments WHERE department_id = :d AND archived_at IS NULL', ['d' => (int) $department])) === null) {
        bd_refuse_fields(['department' => 'There is no such department.']);
    }
    return ['department', (int) $department, (string) $name];
}

/** A principal's current level on the board ('none' when it has no row). */
function share_level_of(PDO $pdo, string $boardId, string $kind, ?int $id): string
{
    return (string) (one_value($pdo, 'SELECT level FROM board_permissions WHERE board_id = CAST(:b AS uuid) AND principal_kind = :k AND principal_id IS NOT DISTINCT FROM CAST(:i AS bigint)',
        ['b' => $boardId, 'k' => $kind, 'i' => $id]) ?? 'none');
}
