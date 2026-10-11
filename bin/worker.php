<?php
declare(strict_types=1);

/**
 * Boards' worker — one pass a minute from boards-worker.timer (design §6.5, §14). It never writes a board's items: every item
 * change is a PHP handler's committed batch (design R3), and the live relay (live/relay.py) only reads. The worker keeps the
 * application's own housekeeping — interval versions of boards that changed, the notification outbox, dispatches to agents (a
 * mention = one chat turn), the search index catch-up, image thumbnails for level of detail, the trash at trash_days, op compaction
 * past op_retention_days, key-usage pruning. Idempotent; advisory-locked so two runs never overlap; each pass its own try (one
 * failing never stops the next); the report is printed as one JSON line, logged once as `worker.pass` (counts and sentences, never a
 * sticky's text, a token or a key) and kept as a worker_passes row when the table exists (db/006+, Spaces' shape).
 *   php bin/worker.php [--passes=a,b] [--limit=200]
 * With --passes the named passes run whether or not they are due; without it every pass runs. BD_WORKER_NOW (ISO 8601) replaces the clock
 * a pass selects by; honoured only when APP_ENV is not prod. Exits 1 when a pass failed.
 *
 * PHASE 0: every pass but `key_usage_prune` is a STUB that changes nothing and answers 0 — the slice whose data it touches fills it in
 * (design §10): `thumbnails` slice 5's (REAL since slice 5 — with the purge of uploads never committed and of items deleted past
 * op_retention_days, their files unlinked inside the store only); `outbox` slice 6's; `versions`, `compaction` slice 7's; `dispatches`,
 * `search_catch_up` slice 9's; `trash` slice 10's (beside its trash_purge — Phase 1's reconciliation 7). Each stub names its slice. REAL from the kit: `key_usage_prune` (bd_prune_key_usage, db/005).
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only.\n"); exit(1); }
require_once dirname(__DIR__) . '/app/bootstrap.php';

/** The passes, in the order they run. A new pass is a name here and a function worker_pass_<name>(PDO, int $limit, DateTimeImmutable $now): array. */
const WORKER_PASSES = ['versions', 'outbox', 'dispatches', 'search_catch_up', 'thumbnails', 'trash', 'compaction', 'key_usage_prune'];

$only = [];
$limit = 200;
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--passes=')) {
        $only = array_values(array_filter(explode(',', substr($a, 9))));
        if (array_diff($only, WORKER_PASSES) !== []) {
            fwrite(STDERR, 'Unknown pass: ' . implode(', ', array_diff($only, WORKER_PASSES)) . '. Passes: ' . implode(', ', WORKER_PASSES) . ".\n");
            exit(2);
        }
    } elseif (str_starts_with($a, '--limit=')) {
        $limit = max(1, min(1000, (int) substr($a, 8)));
    } else {
        fwrite(STDERR, "Usage: php bin/worker.php [--passes=a,b] [--limit=N]\n");
        exit(2);
    }
}
$now = new DateTimeImmutable();
if (($fake = getenv('BD_WORKER_NOW')) !== false && $fake !== '' && app_is_prod() === false) {
    $now = new DateTimeImmutable($fake);
}
$pdo = db();
if (!(bool) $pdo->query("SELECT pg_try_advisory_lock(hashtext('boards_worker'))")->fetchColumn()) {
    echo json_encode(['skipped' => 'another pass holds the lock']) . "\n";
    exit(0);
}
$GLOBALS['__public_door'] = 'cron';                 // every row a pass writes is source cron, actor none
$started = microtime(true);
$report = ['at' => $now->format(DATE_ATOM), 'ran' => [], 'errors' => []];
foreach ($only === [] ? WORKER_PASSES : $only as $pass) {
    try {
        $report['ran'][$pass] = ('worker_pass_' . $pass)($pdo, $limit, $now);
    } catch (Throwable $e) {
        error_log('worker ' . $pass . ': ' . $e->getMessage());
        $report['errors'][] = $pass . ': ' . mb_substr($e->getMessage(), 0, 200);
    }
}
$report['seconds'] = round(microtime(true) - $started, 2);
log_activity($pdo, 'worker.pass', null, null, ['source' => 'cron', 'actor_member_id' => null, 'after' => $report]);
try {
    if ($pdo->query("SELECT to_regclass('public.worker_passes') IS NOT NULL")->fetchColumn()) {
        $pdo->prepare('INSERT INTO worker_passes (started_at, finished_at, steps, error) VALUES (:s, now(), :steps, :err)')
            ->execute(['s' => date(DATE_ATOM, (int) $started), 'steps' => json_encode($report['ran'], JSON_UNESCAPED_SLASHES),
                       'err' => $report['errors'] === [] ? null : implode('; ', $report['errors'])]);
    }
} catch (Throwable $e) {
    error_log('worker_passes: ' . $e->getMessage());
}
echo json_encode($report, JSON_UNESCAPED_SLASHES) . "\n";
$pdo->query("SELECT pg_advisory_unlock(hashtext('boards_worker'))");
exit($report['errors'] === [] ? 0 : 1);

// ---- the passes (stubs until their slice) ------------------------------------------------------------------------------------------

/** SLICE 7 — a board that changed since its last interval version, at most hourly while active: one `interval` version (board_versions); interval versions past interval_version_days thinned to one a day, those past daily_version_days deleted; named versions never. */
function worker_pass_versions(PDO $pdo, int $limit, DateTimeImmutable $now): array { return ['saved' => 0, 'thinned' => 0, 'stub' => 'slice 7']; }
/** SLICE 6 — notification_outbox: queued email (MaluMail) and text (K6) rows sent, failed ones retried with backoff; the digest at its hour. */
function worker_pass_outbox(PDO $pdo, int $limit, DateTimeImmutable $now): array { return ['sent' => 0, 'stub' => 'slice 6']; }
/** SLICE 9 — agent_dispatches: a mention of an agent becomes ONE chat turn of that agent through the kernel (Spaces' route); a long run polled, a refusal shown in the kernel's words, a 5xx backed off. */
function worker_pass_dispatches(PDO $pdo, int $limit, DateTimeImmutable $now): array { return ['sent' => 0, 'stub' => 'slice 9']; }
/** SLICE 9 — search_index catch-up: every board, item and comment whose index row is older than its change. */
function worker_pass_search_catch_up(PDO $pdo, int $limit, DateTimeImmutable $now): array { return ['indexed' => 0, 'stub' => 'slice 9']; }
/**
 * SLICE 5 (pen-files.md) — the files' housekeeping: an item attachment never thumbnailed (an upload makes its own at once; this catches one
 * that arrived another way) gets its three sizes (GD) or a PDF's first page (pdftoppm), or {"none": why}; an upload never committed as an item
 * goes after 24 hours with its files (`file.purge` a board); items deleted longer than op_retention_days go through bd_purge_deleted_items(),
 * which answers their files' paths — unlinked only where they resolve inside the store.
 */
function worker_pass_thumbnails(PDO $pdo, int $limit, DateTimeImmutable $now): array
{
    require_once dirname(__DIR__) . '/app/features/files/write.php';
    $st = $pdo->prepare("SELECT id, storage_path, mime_type FROM attachments
                          WHERE record_type = 'item' AND thumbnails = '{}'::jsonb AND storage_path <> '' AND mime_type = ANY (CAST(:m AS text[]))
                          ORDER BY id LIMIT " . max(1, $limit));
    $st->execute(['m' => '{' . implode(',', array_merge(RASTER_TYPES, ['application/pdf'])) . '}']);
    $made = 0;
    foreach ($st->fetchAll() as $a) {
        $abs = file_abs($pdo, (string) $a['storage_path']);
        $t = $abs === null ? ['width' => null, 'height' => null, 'thumbnails' => [], 'none' => 'the original is missing'] : make_thumbnails($abs, (string) $a['mime_type']);
        if (record_thumbnails($pdo, (int) $a['id'], $t) !== []) {
            $made++;
        }
    }
    $byBoard = [];
    $orphans = purge_orphans($pdo, $now, $byBoard);
    foreach ($byBoard as $board => $n) {
        log_activity($pdo, 'file.purge', 'board', (string) $board, ['source' => 'cron', 'actor_member_id' => null, 'board_id' => (string) $board,
            'after' => ['orphans' => $n]]);
    }
    $gone = purge_deleted_items($pdo);
    return ['made' => $made, 'orphans' => $orphans, 'purged_items' => $gone['purged_items'], 'files_removed' => $gone['files_removed']];
}
/** SLICE 10 — boards in the trash longer than trash_days: purged with their items, ops, versions, comments and files. */
function worker_pass_trash(PDO $pdo, int $limit, DateTimeImmutable $now): array { return ['purged' => 0, 'stub' => 'slice 10']; }
/** SLICE 7 — board_ops older than op_retention_days: a `compaction` version at the cut, then the rows go (design §6.3, D6). */
function worker_pass_compaction(PDO $pdo, int $limit, DateTimeImmutable $now): array { return ['compacted' => 0, 'stub' => 'slice 7']; }
/** bd_prune_key_usage() (db/005): minute buckets older than a day, day buckets older than 35 days. Real from the kit. */
function worker_pass_key_usage_prune(PDO $pdo, int $limit, DateTimeImmutable $now): array
{
    return ['pruned' => (int) one_value($pdo, 'SELECT bd_prune_key_usage()')];
}
