<?php
declare(strict_types=1);

/**
 * Sharing — the writes (slice 1): the database's functions (db/011) — the owner's level, the owner's own row refused, a principal of the
 * right kind (the trigger), the board-wide level never `full`, a transfer only to someone who may make boards. A person shared by name is
 * told (Phase 1's reconciliation 8): one `share` notice through bd_notify(), which also queues their email by their preferences — never a
 * department, an agent, or an unshare.
 */

/** Share a board with a person, an agent or a department at a level ('none' removes the row). Answers {level, needs_grant, notified}. */
function board_share(PDO $pdo, string $boardId, int $by, string $kind, int $principalId, string $level): array
{
    $r = json_decode((string) one_value($pdo, 'SELECT bd_board_share(CAST(:b AS uuid), :m, :k, :p, :l)', ['b' => $boardId, 'm' => $by, 'k' => $kind, 'p' => $principalId, 'l' => $level]), true) ?: [];
    $r['notified'] = null;
    if ($kind === 'member' && $level !== 'none') {
        $sharer = (string) (one_value($pdo, 'SELECT display_name FROM members WHERE id = :m', ['m' => $by]) ?? 'Someone');
        $title = (string) (one_value($pdo, 'SELECT title FROM boards WHERE id = CAST(:b AS uuid)', ['b' => $boardId]) ?? 'a board');
        $words = ['view' => 'to view', 'comment' => 'to comment on', 'edit' => 'to edit', 'full' => 'with full rights'][$level] ?? '';
        $r['notified'] = one_value($pdo, "SELECT bd_notify(:p, 'share', :t, :body, CAST(:b AS uuid), 'board', CAST(:b AS uuid), :m)",
            ['p' => $principalId, 't' => $sharer . ' shared ' . $title . ' with you', 'body' => 'You can open it ' . $words . '.', 'b' => $boardId, 'm' => $by]);
    }
    return $r;
}

/** The board-wide level for everyone in the business (none, view, comment, edit). */
function board_access_set(PDO $pdo, string $boardId, int $by, string $level): void
{
    $pdo->prepare('SELECT bd_board_access_set(CAST(:b AS uuid), :m, :l)')->execute(['b' => $boardId, 'm' => $by, 'l' => $level]);
}

/** Hand the board to a person who may make boards; the previous owner keeps edit. */
function board_transfer(PDO $pdo, string $boardId, int $by, int $newOwner): void
{
    $pdo->prepare('SELECT bd_board_transfer(CAST(:b AS uuid), :m, :n)')->execute(['b' => $boardId, 'm' => $by, 'n' => $newOwner]);
}
