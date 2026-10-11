<?php
declare(strict_types=1);
/**
 * Action `version_restore` (log version.restore — right boards.edit, level full; the kernel pauses an agent's call: other): **board**, **version** (its number). The board as it is
 * now is saved first (before_restore), then ONE batch turns it into the version through bd_commit_ops — every open canvas receives it like any other batch. The client batch id comes from
 * the request id, so the kernel's retry of one call answers the first commit. record_id = the batch's seq (none when the board already matches).
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/history/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/history/write.php';
bd_handler_begin();
$pdo = db();
require_right('boards.edit');
$b = bd_board_or_404($pdo, (string) (req_val('board') ?? ''), 'full');
$id = (string) $b['board_id'];
$errors = [];
$no = null;
if ((string) (req_val('version') ?? '') === '') {
    $errors['version'] = 'Say which version to restore.';
} else {
    $no = bd_ref($pdo, 'version', null, "SELECT 1 FROM mcp_board_versions WHERE board_id = CAST('$id' AS uuid) AND version_no = :id", 'version', $errors, false);
}
if ($errors !== []) {
    bd_refuse_fields($errors);
}
$r = [];
bd_guard($pdo, static function () use ($pdo, $id, $no, &$r): void {
    $pdo->beginTransaction();
    $r = version_restore($pdo, $id, (int) current_member_id(), (int) $no, restore_batch_id());
    log_activity($pdo, 'version.restore', 'board', $id, ['board_id' => $id, 'after' => ['version_no' => $no, 'seq' => $r['seq'] ?? null, 'noop' => !empty($r['noop'])]]);
    $pdo->commit();
});
$land = bd_land(return_path('/boards/' . $id . '/history'), !empty($r['noop']) ? 'nothing' : 'restored');
if (!empty($r['noop'])) {
    bd_done('Nothing to restore — the board already matches version ' . $no, null, $land, 'versionChanged', ['board_id' => $id, 'noop' => true, 'version' => $no]);
}
$n = (int) ($r['changes'] ?? 0);
bd_done('Restored version ' . $no . ' — ' . $n . ' change' . ($n === 1 ? '' : 's') . ' in one batch; the board as it was is version ' . ($r['saved_as'] ?? '?'),
    (int) ($r['seq'] ?? 0), $land, 'versionChanged', ['board_id' => $id, 'noop' => false, 'version' => $no, 'seq' => (int) ($r['seq'] ?? 0), 'changes' => $n, 'saved_as' => $r['saved_as'] ?? null]);
