<?php
declare(strict_types=1);

/**
 * Boards — the writes (slice 1). Each is the database's own function (db/011): the wall, the cap, the owner's rule and the trash's rule are
 * its, and its refusals arrive as sentences through bd_guard(). PHP decides nothing a function keeps. Favourites are a plain statement under
 * the gate (divergence 1).
 */

/** A new board (bd_board_create: boards.create, the default everyone level, the folder when given). Answers its uuid. */
function board_create(PDO $pdo, int $by, string $title, ?string $description, ?int $folderId): string
{
    return (string) one_value($pdo, 'SELECT bd_board_create(:m, :t, :d, CAST(:f AS bigint))', ['m' => $by, 't' => $title, 'd' => $description, 'f' => $folderId]);
}

/** Title, description, background, start view — only the keys sent (bd_board_update: edit). */
function board_update(PDO $pdo, string $boardId, int $by, array $fields): void
{
    $pdo->prepare('SELECT bd_board_update(CAST(:b AS uuid), :m, CAST(:f AS jsonb))')
        ->execute(['b' => $boardId, 'm' => $by, 'f' => json_encode((object) $fields, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
}

function board_lock(PDO $pdo, string $boardId, int $by, bool $locked): void
{
    $pdo->prepare('SELECT bd_board_lock(CAST(:b AS uuid), :m, :l)')->execute(['b' => $boardId, 'm' => $by, 'l' => $locked ? 't' : 'f']);
}

function board_delete(PDO $pdo, string $boardId, int $by): void
{
    $pdo->prepare('SELECT bd_board_delete(CAST(:b AS uuid), :m)')->execute(['b' => $boardId, 'm' => $by]);
}

function board_restore(PDO $pdo, string $boardId, int $by): void
{
    $pdo->prepare('SELECT bd_board_restore(CAST(:b AS uuid), :m)')->execute(['b' => $boardId, 'm' => $by]);
}

/** Star or unstar a board for me (the handler has gated `view` for a star; an unstar is my own row). Answers whether anything changed. */
function board_favorite(PDO $pdo, string $boardId, int $memberId, bool $on): bool
{
    $st = $on
        ? $pdo->prepare('INSERT INTO board_favorites (member_id, board_id) VALUES (:m, CAST(:b AS uuid)) ON CONFLICT DO NOTHING')
        : $pdo->prepare('DELETE FROM board_favorites WHERE member_id = :m AND board_id = CAST(:b AS uuid)');
    $st->execute(['m' => $memberId, 'b' => $boardId]);
    return $st->rowCount() > 0;
}
