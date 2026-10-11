<?php
declare(strict_types=1);

/**
 * History — the writes (slice 7): a named version and a restore, each ONE database function (the database is the referee: who may save, who may
 * restore, what a restore changes, the version numbers) — and the worker's two passes. PHP never computes a diff.
 */

/** Save the board as it is now under a name (bd_save_version, reason manual; level edit). The version number. */
function version_save(PDO $pdo, string $board, int $by, string $label): int
{
    $st = $pdo->prepare("SELECT bd_save_version(CAST(:b AS uuid), :m, 'manual', :l)");
    $st->execute(['b' => $board, 'm' => $by, 'l' => $label]);
    return (int) $st->fetchColumn();
}

/**
 * Restore a version (bd_restore_version; level full): the board as it is is saved first, then ONE batch turns it into the version.
 * ['seq' => the batch, 'restored_version' => N, 'changes' => ops in the batch, 'saved_as' => the before_restore version] or ['noop' => true].
 */
function version_restore(PDO $pdo, string $board, int $by, int $no, ?string $clientBatchId): array
{
    $st = $pdo->prepare('SELECT bd_restore_version(CAST(:b AS uuid), :m, :n, CAST(:c AS uuid))');
    $st->execute(['b' => $board, 'm' => $by, 'n' => $no, 'c' => $clientBatchId]);
    $r = json_decode((string) $st->fetchColumn(), true) ?: [];
    $saved = one_value($pdo, "SELECT max(version_no) FROM board_versions WHERE board_id = CAST(:b AS uuid) AND reason = 'before_restore'", ['b' => $board]);
    $r['saved_as'] = $saved === null ? null : (int) $saved;
    if (isset($r['seq'])) {
        $r['changes'] = (int) one_value($pdo, 'SELECT jsonb_array_length(ops) FROM board_ops WHERE seq = :s', ['s' => (int) $r['seq']]);
    }
    return $r;
}

/** A uuid made from the request id: the kernel's retry of one call carries the same X-Request-Id and so answers the first commit. */
function restore_batch_id(): string
{
    $h = md5('restore:' . request_id());
    return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20, 12);
}

/** The worker's versions pass: interval versions of boards that changed (at most hourly), then the thinning. */
function versions_pass(PDO $pdo, DateTimeImmutable $now): array
{
    return ['saved' => (int) one_value($pdo, 'SELECT bd_interval_versions()'), 'thinned' => (int) one_value($pdo, 'SELECT bd_prune_versions()')];
}

/**
 * The worker's compaction pass: ops older than op_retention_days go (a board whose newest version predates the cut first gets a compaction version),
 * once a night — only when the business's local hour is 03 and no worker_passes row of today carries `compaction`.
 */
function compaction_pass(PDO $pdo, DateTimeImmutable $now): array
{
    $zone = (string) one_value($pdo, 'SELECT timezone FROM bd_settings WHERE id = 1');
    try {
        $tz = new DateTimeZone($zone !== '' ? $zone : 'UTC');
    } catch (Exception) {
        $tz = new DateTimeZone('UTC');
    }
    $local = $now->setTimezone($tz);
    if ($local->format('H') !== '03') {
        return ['compacted' => 0, 'skipped' => 'not the hour'];
    }
    $day = $local->format('Y-m-d');
    $done = $pdo->prepare("SELECT 1 FROM worker_passes WHERE steps #>> '{compaction,on}' = :d AND NOT jsonb_exists(steps->'compaction', 'skipped') LIMIT 1");
    $done->execute(['d' => $day]);
    if ($done->fetchColumn() !== false) {
        return ['compacted' => 0, 'skipped' => 'already done today'];
    }
    return ['compacted' => (int) one_value($pdo, 'SELECT bd_compact_ops()'), 'on' => $day];
}
