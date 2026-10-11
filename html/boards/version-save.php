<?php
declare(strict_types=1);
/**
 * Action `version_save` (log version.save — right boards.edit, level edit): **board**, **label** (a name for this moment, 1–120 characters). Saves the board as it is
 * now as a named version (bd_save_version, reason manual). record_id = the version number.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/history/write.php';
bd_handler_begin();
$pdo = db();
require_right('boards.edit');
$b = bd_board_or_404($pdo, (string) (req_val('board') ?? ''), 'edit');
$id = (string) $b['board_id'];
$errors = [];
$label = bd_text('label', null, 120, 'The name', $errors, true);
if (!req_has('label') || $label === null) {
    $errors['label'] ??= 'Give this moment a name.';
}
if ($errors !== []) {
    bd_refuse_fields($errors);
}
$no = 0;
bd_guard($pdo, static function () use ($pdo, $id, $label, &$no): void {
    $pdo->beginTransaction();
    $no = version_save($pdo, $id, (int) current_member_id(), (string) $label);
    log_activity($pdo, 'version.save', 'board', $id, ['board_id' => $id, 'after' => ['version_no' => $no, 'label' => $label]]);
    $pdo->commit();
});
bd_done('Saved version ' . $no . ' — ' . $label, $no, bd_land(return_path('/boards/' . $id . '/history?version=' . $no), 'saved'), 'versionChanged', ['board_id' => $id, 'version' => $no]);
