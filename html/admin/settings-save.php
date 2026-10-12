<?php
declare(strict_types=1);
/**
 * Action `settings_save` (log settings.save — settings.manage; an agent's call pauses for approval, `other`): any of business_name (≤ 120), default_everyone_level (none | view |
 * comment | edit), item_soft_budget and item_hard_cap (100–100,000, the soft below the hard), batch_max_ops (1–5,000), max_upload_mb (1–1,024), op_retention_days (7–3,650),
 * interval_version_days (1–3,650, at most the daily days), daily_version_days (1–36,500), trash_days (1–3,650), live_enabled (yes | no). A field left out stays as it was. PHP checks
 * with the CHECKs' own numbers; a CHECK the database still refuses (23514) is shown on the field its constraint names.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/admin/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/admin/write.php';
bd_handler_begin();
require_right('settings.manage');
$pdo = db();
$cur = settings_read($pdo);
$errors = [];
$new = [];
foreach (SETTINGS_FORM as $name => $def) {
    $new[$name] = match ($def[0]) {
        'text'  => bd_text($name, $cur[$name], $def[1], $def[2], $errors),
        'level' => bd_choice($name, $cur[$name], array_keys(SETTINGS_LEVELS), 'The everyone level', $errors, false),
        'int'   => bd_int($name, (int) $cur[$name], $def[1], $def[2], $def[3], $errors),
        'bool'  => bd_yes($name, (bool) $cur[$name]),
    };
}
if (!isset($errors['item_soft_budget']) && !isset($errors['item_hard_cap']) && $new['item_soft_budget'] >= $new['item_hard_cap']) {
    $errors['item_soft_budget'] = 'The soft budget must be below the hard cap.';
}
if (!isset($errors['interval_version_days']) && !isset($errors['daily_version_days']) && $new['interval_version_days'] > $new['daily_version_days']) {
    $errors['interval_version_days'] = 'Autosaves are thinned before the daily ones end.';
}
if ($errors !== []) {
    bd_refuse_fields($errors);
}
$changed = [];
foreach ($new as $k => $v) {
    if ($v !== $cur[$k]) {
        $changed[$k] = $v;
    }
}
$diff = bd_guard($pdo, static function () use ($pdo, $changed): array {
    $pdo->beginTransaction();
    try {
        $diff = settings_save($pdo, $changed);
    } catch (PDOException $e) {
        if ((string) $e->getCode() !== '23514') {
            throw $e;
        }
        $pdo->rollBack();
        $field = preg_match('/bd_settings_(\w+?)_check/', $e->getMessage(), $m) && isset(SETTINGS_FORM[$m[1]]) ? $m[1]
            : (str_contains($e->getMessage(), 'item_soft_budget') || isset($changed['item_soft_budget']) || isset($changed['item_hard_cap']) ? 'item_soft_budget' : 'interval_version_days');
        bd_refuse_fields([$field => ['item_soft_budget' => 'The soft budget must be below the hard cap.', 'interval_version_days' => 'Autosaves are thinned before the daily ones end.'][$field]
            ?? 'That setting is out of range.']);
    }
    log_activity($pdo, 'settings.save', 'settings', 1, ['before' => $diff['before'], 'after' => $diff['after']]);
    $pdo->commit();
    return $diff;
});
bd_done($changed === [] ? 'Nothing changed' : 'Saved the settings: ' . implode(', ', array_map(static fn (string $k): string => str_replace('_', ' ', $k), array_keys($changed))), 1,
    bd_land('/admin/settings', 'saved'), 'settingsChanged', ['changed' => array_keys($diff['after'])]);
