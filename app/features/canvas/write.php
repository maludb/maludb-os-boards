<?php
declare(strict_types=1);

/**
 * The canvas's writes (canvas.md "Query functions"; canvas-core.md "The op protocol", "The doors (PHP)"). Every item write — the island's
 * batch and an agent's item action alike — is bd_commit_ops() (db/007): THE ONE WRITE PATH. PHP builds nothing it judges: the level, the
 * lock, the cascades, last-writer-wins, the cap, the idempotent batch and the inverse are the database's.
 */

/**
 * Commit a batch: bd_commit_ops() → its answer {seq, duplicate, versions, replaced, item_count, warnings[, noop]}, plus the batch's
 * `inverse` and `summary` read back from board_ops (a duplicate's too, so a client whose first answer was lost can still undo it).
 * Runs in the caller's transaction.
 */
function commit_ops(PDO $pdo, string $boardId, int $memberId, string $source, ?string $clientId, ?string $batchId, array $ops, ?int $runId = null, ?string $requestId = null): array
{
    $ops = ops_json_objects($ops);
    $st = $pdo->prepare('SELECT bd_commit_ops(CAST(:b AS uuid), :m, :s, :c, CAST(:k AS uuid), CAST(:ops AS jsonb), :run, :rid)');
    $st->execute(['b' => $boardId, 'm' => $memberId, 's' => $source, 'c' => $clientId, 'k' => $batchId,
                  'ops' => json_encode($ops, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
                  'run' => $runId, 'rid' => $requestId]);
    $answer = json_decode((string) $st->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
    return $answer + batch_record($pdo, $boardId, $answer);
}

/**
 * style and data are JSON OBJECTS (db/007's checks): an empty PHP array encodes as `[]`, which the column refuses — a text item made with no
 * colour had `style: []`. Every create's and update's style/data that is an empty or list array becomes an object here, once, for every caller.
 */
function ops_json_objects(array $ops): array
{
    $obj = static fn ($v) => is_array($v) && ($v === [] || array_is_list($v)) ? (object) $v : $v;
    foreach ($ops as &$op) {
        if (!is_array($op)) {
            continue;
        }
        if (($op['op'] ?? '') === 'create' && is_array($op['item'] ?? null)) {
            foreach (['style', 'data'] as $k) {
                if (array_key_exists($k, $op['item'])) { $op['item'][$k] = $obj($op['item'][$k]); }
            }
        }
        if (($op['op'] ?? '') === 'update' && is_array($op['fields'] ?? null)) {
            foreach (['style', 'data'] as $k) {
                if (array_key_exists($k, $op['fields'])) { $op['fields'][$k] = $obj($op['fields'][$k]); }
            }
            if ($op['fields'] === []) { $op['fields'] = (object) []; }
        }
    }
    unset($op);
    return $ops;
}

/** The committed batch's inverse and summary (board_ops), or empty ones for a batch that changed nothing. */
function batch_record(PDO $pdo, string $boardId, array $answer): array
{
    if (!empty($answer['noop']) || empty($answer['seq'])) {
        return ['inverse' => [], 'summary' => []];
    }
    $r = one_row($pdo, 'SELECT inverse, summary FROM board_ops WHERE seq = :s AND board_id = CAST(:b AS uuid)', ['s' => (int) $answer['seq'], 'b' => $boardId]);
    return ['inverse' => json_decode((string) ($r['inverse'] ?? '[]'), true), 'summary' => json_decode((string) ($r['summary'] ?? '{}'), true)];
}

/**
 * Take back my last $count batches on this board within the hour (ops_undo; D8): not undo batches themselves, and not ones a later undo
 * of mine already reversed (canvas.md decision 1). bd_undo_ops() refuses anything not mine or older than the hour. Answers the commit's
 * answer + 'undone' => [seq, …]. Nothing to undo → DomainException (422).
 */
function undo_own(PDO $pdo, string $boardId, int $memberId, int $count, string $source, ?int $runId = null): array
{
    $st = $pdo->prepare('SELECT o.seq FROM board_ops o
                          WHERE o.board_id = CAST(:b AS uuid) AND o.member_id = :m AND o.at > now() - interval \'1 hour\' AND o.undoes_seq IS NULL
                            AND NOT EXISTS (SELECT 1 FROM board_ops u WHERE u.board_id = o.board_id AND u.member_id = :m AND o.seq = ANY (u.undoes_seq))
                          ORDER BY o.seq DESC LIMIT :n');
    $st->bindValue('b', $boardId);
    $st->bindValue('m', $memberId, PDO::PARAM_INT);
    $st->bindValue('n', $count, PDO::PARAM_INT);
    $st->execute();
    $seqs = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    if ($seqs === []) {
        throw new DomainException('Nothing of yours to undo on this board from the last hour.');
    }
    $q = $pdo->prepare('SELECT bd_undo_ops(CAST(:b AS uuid), :m, CAST(:s AS bigint[]), :src, :run, :rid)');
    $q->execute(['b' => $boardId, 'm' => $memberId, 's' => pg_array_literal($seqs), 'src' => $source, 'run' => $runId, 'rid' => agent_run_request_id() ?? request_id()]);
    $answer = json_decode((string) $q->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
    return $answer + batch_record($pdo, $boardId, $answer) + ['undone' => $seqs];
}

/** The board opened: my recents (board_recents — the home's "Recent" and the cards' order). */
function record_open(PDO $pdo, string $boardId, int $memberId): void
{
    $pdo->prepare('INSERT INTO board_recents (member_id, board_id, visited_at) VALUES (:m, CAST(:b AS uuid), now())
                   ON CONFLICT (member_id, board_id) DO UPDATE SET visited_at = now()')->execute(['m' => $memberId, 'b' => $boardId]);
}

/** The attachment store: bd_settings.storage_dir, else BD_STORAGE_DIR (relative to the application), created on first use. */
function bd_storage_dir(PDO $pdo): string
{
    $dir = (string) (one_value($pdo, 'SELECT storage_dir FROM bd_settings WHERE id = 1') ?? '');
    if ($dir === '') {
        $dir = (string) env('BD_STORAGE_DIR', 'storage');
    }
    if ($dir[0] !== '/') {
        $dir = APP_ROOT . '/' . $dir;
    }
    return rtrim($dir, '/');
}

/**
 * The cards' preview (the island's PNG, already checked by the door): a `board_thumbnail` attachment under {store}/thumbnails/{board}/,
 * the board's thumbnail_attachment_id moved to it, the previous row deleted and its file unlinked. storage_path is RELATIVE to the store
 * (slice 5's file door resolves it the same way). Answers the attachment's id. Runs in the caller's transaction.
 */
function save_thumbnail(PDO $pdo, string $boardId, int $memberId, string $pngBytes): int
{
    $store = bd_storage_dir($pdo);
    $rel = 'thumbnails/' . $boardId . '/' . bin2hex(random_bytes(8)) . '.png';
    if (!is_dir(dirname($store . '/' . $rel)) && !mkdir(dirname($store . '/' . $rel), 0770, true) && !is_dir(dirname($store . '/' . $rel))) {
        throw new RuntimeException('The thumbnail store cannot be written.');
    }
    if (file_put_contents($store . '/' . $rel, $pngBytes, LOCK_EX) !== strlen($pngBytes)) {
        throw new RuntimeException('The thumbnail could not be written.');
    }
    $size = getimagesizefromstring($pngBytes) ?: [null, null];
    $old = one_row($pdo, 'SELECT a.id, a.storage_path FROM boards b JOIN attachments a ON a.id = b.thumbnail_attachment_id WHERE b.id = CAST(:b AS uuid)', ['b' => $boardId]);
    $id = (int) one_value($pdo, "INSERT INTO attachments (record_type, record_uuid, board_id, filename, mime_type, byte_size, sha256, storage_path, uploaded_by, width, height)
                                 VALUES ('board_thumbnail', CAST(:b AS uuid), CAST(:b AS uuid), 'thumbnail.png', 'image/png', :n, :h, :p, :m, :w, :ht) RETURNING id",
        ['b' => $boardId, 'n' => strlen($pngBytes), 'h' => hash('sha256', $pngBytes), 'p' => $rel, 'm' => $memberId, 'w' => $size[0], 'ht' => $size[1]]);
    $pdo->prepare('UPDATE boards SET thumbnail_attachment_id = :a WHERE id = CAST(:b AS uuid)')->execute(['a' => $id, 'b' => $boardId]);
    if ($old !== null) {
        $pdo->prepare('DELETE FROM attachments WHERE id = :a')->execute(['a' => (int) $old['id']]);
        $oldPath = $store . '/' . ltrim((string) $old['storage_path'], '/');
        if (str_starts_with((string) $old['storage_path'], 'thumbnails/') && is_file($oldPath)) {
            @unlink($oldPath);
        }
    }
    return $id;
}

/**
 * The log event a canvas batch is named by (canvas-core.md "The canvas's log event"): only creates → item.create; only updates whose
 * fields ⊂ {x, y, parent_id, z} → item.move; only updates every one of which touches data.content → item.text; other updates →
 * item.update; only deletes → item.delete; only restores → item.restore; anything mixed → item.batch.
 */
function batch_event(array $summary, array $ops): string
{
    $kinds = array_values(array_unique(array_map(static fn ($o): string => (string) ($o['op'] ?? ''), $ops)));
    if (count($kinds) !== 1) {
        return 'item.batch';
    }
    switch ($kinds[0]) {
        case 'create': return 'item.create';
        case 'delete': return 'item.delete';
        case 'restore': return 'item.restore';
        case 'update':
            $move = true;
            $text = true;
            foreach ($ops as $o) {
                $fields = is_array($o['fields'] ?? null) ? $o['fields'] : [];
                if (array_diff(array_keys($fields), ['x', 'y', 'parent_id', 'z']) !== []) {
                    $move = false;
                }
                if (!is_array($fields['data'] ?? null) || !array_key_exists('content', $fields['data'])) {
                    $text = false;
                }
            }
            return $move ? 'item.move' : ($text ? 'item.text' : 'item.update');
    }
    return 'item.batch';
}

/**
 * The log row's `after` for a batch (the manifest's "Log payloads"): the seq, counts by verb and type, the batch's box — and for item.text
 * the item and the first 200 characters of its new text, never more.
 */
function batch_log_after(string $event, array $answer, array $ops): array
{
    $s = $answer['summary'] ?? [];
    $after = ['seq' => (int) $answer['seq'], 'counts' => $s['counts'] ?? (object) [], 'types' => $s['types'] ?? (object) [], 'box' => $s['bbox'] ?? null];
    if ($event === 'item.text') {
        $first = $ops[0];
        $after['item'] = (string) ($first['id'] ?? '');
        $plain = '';
        foreach ((array) ($first['fields']['data']['content'] ?? []) as $run) {
            $plain .= (string) ($run['plain_text'] ?? ($run['text']['content'] ?? ''));
        }
        $after['text'] = mb_substr($plain, 0, 200);
        if (count($ops) > 1) {
            $after['items'] = count($ops);
        }
    }
    return $after;
}

/** The items a committed batch created (a retried call answers the first commit's — never the ids the retry minted). */
function batch_created_ids(PDO $pdo, string $boardId, int $seq): array
{
    $ops = json_decode((string) (one_value($pdo, 'SELECT ops FROM board_ops WHERE seq = :s AND board_id = CAST(:b AS uuid)', ['s' => $seq, 'b' => $boardId]) ?? '[]'), true);
    return array_values(array_map(static fn ($o): string => (string) $o['item']['id'], array_filter((array) $ops, static fn ($o): bool => ($o['op'] ?? '') === 'create')));
}
