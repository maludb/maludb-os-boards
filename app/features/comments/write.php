<?php
declare(strict_types=1);

/**
 * Comments — the writes (slice 6): the database's own functions (db/011). Who may comment, who is told, which agent is dispatched and which one
 * needs the board shared are THEIRS; PHP carries the runs in and the answer out. A comment's text is never in a log payload.
 */

/** A new thread, pinned to an item (with an optional offset from its top-right corner) or a point. Answers bd_comment_create()'s JSON verbatim: id, root, dispatched, needs_share, not_shared. */
function comment_create(PDO $pdo, string $board, int $by, array $runs, ?string $item, ?float $x, ?float $y, array $context, ?int $runId): array
{
    return json_decode((string) one_value($pdo, 'SELECT bd_comment_create(CAST(:b AS uuid), :m, CAST(:body AS jsonb), CAST(:i AS uuid), CAST(:x AS float8), CAST(:y AS float8), NULL, CAST(:ctx AS uuid[]), CAST(:run AS bigint))',
        ['b' => $board, 'm' => $by, 'body' => json_encode($runs, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'i' => $item, 'x' => $x, 'y' => $y,
         'ctx' => '{' . implode(',', $context) . '}', 'run' => $runId]), true) ?: [];
}

/** A reply: on the root (a reply to a reply hangs on it); the board is the root's. */
function comment_reply(PDO $pdo, string $root, int $by, array $runs, ?int $runId): array
{
    $board = (string) one_value($pdo, 'SELECT board_id FROM comments WHERE id = CAST(:c AS uuid) AND deleted_at IS NULL', ['c' => $root]);
    if ($board === '') {
        throw new DomainException('There is no such thread.');
    }
    return json_decode((string) one_value($pdo, 'SELECT bd_comment_create(CAST(:b AS uuid), :m, CAST(:body AS jsonb), NULL, NULL, NULL, CAST(:p AS uuid), \'{}\', CAST(:run AS bigint))',
        ['b' => $board, 'm' => $by, 'body' => json_encode($runs, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'p' => $root, 'run' => $runId]), true) ?: [];
}

function comment_set_resolved(PDO $pdo, string $id, int $by, bool $on): void
{
    $pdo->prepare('SELECT bd_comment_resolve(CAST(:c AS uuid), :m, :r)')->execute(['c' => $id, 'm' => $by, 'r' => $on ? 't' : 'f']);
}

function comment_edit(PDO $pdo, string $id, int $by, array $runs): void
{
    $pdo->prepare('SELECT bd_comment_edit(CAST(:c AS uuid), :m, CAST(:body AS jsonb))')
        ->execute(['c' => $id, 'm' => $by, 'body' => json_encode($runs, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
}

function comment_delete(PDO $pdo, string $id, int $by): void
{
    $pdo->prepare('SELECT bd_comment_delete(CAST(:c AS uuid), :m)')->execute(['c' => $id, 'm' => $by]);
}

/**
 * The text field of a comment handler: required, up to COMMENT_MAX_CHARS, turned into runs. Adds to $errors; answers ['runs' => …, 'unresolved' => …] or null.
 * (A reply of nothing is refused in the person's words, never by the schema's length check.)
 */
function comment_text_field(PDO $pdo, array &$errors): ?array
{
    $text = bd_text('text', null, COMMENT_MAX_CHARS, 'The comment', $errors, true);
    if ($text === null) {
        if (!isset($errors['text'])) {
            $errors['text'] = 'The comment is required.';
        }
        return null;
    }
    $r = comment_text_to_rich($pdo, $text);
    return $r['runs'] === [] ? null : $r;
}

/** Does this HTMX request want the thread's partial back (its target is a thread's wrapper)? */
function comment_wants_partial(): bool
{
    $t = (string) ($_SERVER['HTTP_HX_TARGET'] ?? '');
    return is_htmx_request() && !is_htmx_boosted() && (str_starts_with($t, 'comment-thread-') || $t === 'comment-rail-thread');
}

/** Answer a comment handler over HTMX with the thread's partial again (outerHTML of its wrapper), or end with bd_done() for a browser and JSON. */
function comment_finish(PDO $pdo, string $thread, string $did, string $recordId, string $board, array $extra = [], array $notes = []): never
{
    $extra += ['board_id' => $board, 'thread_id' => $thread];
    if (comment_wants_partial()) {
        emit_action_status(true, ['did' => $did, 'record_id' => $recordId, 'refresh' => 'commentChanged'] + $extra);
        hx_trigger('commentChanged');
        $t = find_thread($pdo, $thread);
        echo $t === null
            ? view('comments/partials/gone.php', ['thread' => $thread, 'board' => $board])
            : view('comments/partials/thread.php', ['t' => $t, 'tz' => member_timezone(), 'notes' => $notes, 'board' => $board, 'level' => bd_level($pdo, $board)]);
        exit;
    }
    bd_done($did, $recordId, bd_land(return_path('/boards/' . $board . '/comments'), null, 'comment-card-' . $thread), 'commentChanged', $extra);
}
