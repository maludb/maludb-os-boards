<?php
declare(strict_types=1);

/**
 * The board's wall in PHP (docs/build-specs/walls-and-sharing.md "PHP helpers"; built by Phase 2, used by every slice): the only PHP that
 * touches a level, and it decides nothing. The database decides — bd_require_level() (db/011) answers P0002 "There is no such board."
 * (a board one cannot see does not exist to them: 404) or 42501 with its own sentence (403), and bd_guard() turns either into the answer.
 * Never compute a level here, never read board_permissions to decide, never special-case an admin or an agent: an agent's action token
 * is its member, and its level is the same function's answer.
 */

const BD_LEVELS = ['view' => 1, 'comment' => 2, 'edit' => 3, 'full' => 4];

/** The board row for the caller (mcp_boards, with my_level) at least at $need ('view'|'comment'|'edit'|'full'), or a 404/403 answer. */
function bd_board_or_404(PDO $pdo, ?string $board, string $need = 'view'): array
{
    if ($board === null || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', strtolower($board))) {
        refuse(404, 'There is no such board.');
    }
    if (!isset(BD_LEVELS[$need])) {
        throw new InvalidArgumentException('bd_board_or_404: no level ' . $need);
    }
    $board = strtolower($board);
    bd_guard($pdo, static fn () => one_value($pdo, 'SELECT bd_require_level(CAST(:b AS uuid), app_current_member_id(), :need)', ['b' => $board, 'need' => $need]));
    $st = $pdo->prepare('SELECT * FROM mcp_boards WHERE board_id = CAST(:b AS uuid)');
    $st->execute(['b' => $board]);
    $row = $st->fetch();
    if ($row === false) {
        refuse(404, 'There is no such board.');           // the wall admitted it, the view did not: never show half a board
    }
    return $row;
}

/** The caller's level on a board (bd_my_level), for showing and hiding controls — never as the gate. '' when none. */
function bd_level(PDO $pdo, string $board): string
{
    return (string) (one_value($pdo, 'SELECT bd_my_level(CAST(:b AS uuid))', ['b' => strtolower($board)]) ?? '');
}

/** Rank comparison of two level names, for the templates' show and hide only. */
function bd_level_at_least(string $have, string $need): bool
{
    return (BD_LEVELS[$have] ?? 0) >= (BD_LEVELS[$need] ?? PHP_INT_MAX);
}
