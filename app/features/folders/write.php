<?php
declare(strict_types=1);

/**
 * Folders — the writes (slice 1, divergence 1): plain statements under the ownership rule — a folder's owner or a Boards admin changes it —
 * except adding a board, which is bd_folder_add() (the board must be one the caller may open). A team folder is made only by a holder of
 * boards.create (the handler checks). Folders grant nothing: deleting one leaves its boards where they are.
 */

/** May the member change this folder (its owner, or a Boards admin)? */
function folder_may_change(PDO $pdo, int $folderId, int $memberId): bool
{
    return (bool) one_value($pdo, 'SELECT EXISTS (SELECT 1 FROM board_folders WHERE id = :f AND (owner_member_id = :m OR bd_is_boards_admin(:m)))', ['f' => $folderId, 'm' => $memberId]);
}

/** Make a folder ($id null) or rename it / make it a team folder. Answers its id. The caller has checked folder_may_change() for an update. */
function folder_save(PDO $pdo, ?int $id, int $by, string $name, bool $team): int
{
    if ($id === null) {
        return (int) one_value($pdo, 'INSERT INTO board_folders (name, owner_member_id, is_team) VALUES (:n, :m, :t) RETURNING id', ['n' => trim($name), 'm' => $by, 't' => $team ? 't' : 'f']);
    }
    $pdo->prepare('UPDATE board_folders SET name = :n, is_team = :t WHERE id = :id')->execute(['n' => trim($name), 't' => $team ? 't' : 'f', 'id' => $id]);
    return $id;
}

/** Delete a folder (its entries go; the boards stay). The caller has checked folder_may_change(). */
function folder_delete(PDO $pdo, int $id, int $by): void
{
    $pdo->prepare('DELETE FROM board_folders WHERE id = :id')->execute(['id' => $id]);
}

/** File a board in a folder (bd_folder_add: the folder the caller's or a Boards admin's, the board one they may open). */
function folder_add(PDO $pdo, int $folderId, string $boardId, int $by): void
{
    $pdo->prepare('SELECT bd_folder_add(:m, :f, CAST(:b AS uuid))')->execute(['m' => $by, 'f' => $folderId, 'b' => $boardId]);
}

/** Take a board out of a folder. Answers whether it was in it. The caller has checked folder_may_change(). */
function folder_remove(PDO $pdo, int $folderId, string $boardId, int $by): bool
{
    $st = $pdo->prepare('DELETE FROM board_folder_entries WHERE folder_id = :f AND board_id = CAST(:b AS uuid)');
    $st->execute(['f' => $folderId, 'b' => $boardId]);
    return $st->rowCount() > 0;
}
