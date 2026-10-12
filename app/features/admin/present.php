<?php
declare(strict_types=1);

/** The JSON shapes of the admin pages (slice 10) — whitelists, never a raw row. */

function present_usage_summary(array $u, array $budget): array
{
    return $u + ['soft_budget' => $budget[0], 'hard_cap' => $budget[1]];
}

function present_usage_row(array $r, array $budget): array
{
    return ['board_id' => (string) $r['board_id'], 'title' => $r['title'], 'owner' => ['member_id' => $r['owner_member_id'] !== null ? (int) $r['owner_member_id'] : null, 'display_name' => $r['owner_name'],
            'active' => (bool) $r['owner_active']], 'item_count' => (int) $r['item_count'], 'over_soft' => (bool) $r['over_soft'],
            'status' => budget_status((int) $r['item_count'], $budget[0], $budget[1]), 'idle_days' => (int) $r['idle_days'], 'open_threads' => (int) $r['open_threads'],
            'last_activity_at' => json_ts($r['last_activity_at']), 'url' => '/boards/' . $r['board_id'], 'transfer_url' => '/boards/' . $r['board_id'] . '/share#share-owner'];
}

function present_trash_admin_row(array $t): array
{
    return ['board_id' => (string) $t['board_id'], 'title' => $t['title'], 'owner_name' => $t['owner_name'], 'item_count' => (int) $t['item_count'], 'deleted_at' => json_ts($t['deleted_at']),
            'deleted_by' => $t['deleted_by_name'], 'purge_after' => json_ts($t['purge_after'])];
}

function present_active_board(array $b): array
{
    return ['board_id' => (string) $b['board_id'], 'title' => $b['title'], 'batches' => (int) $b['batches'], 'members' => (int) $b['members'], 'last_at' => json_ts($b['last_at']), 'url' => '/boards/' . $b['board_id']];
}

/** The settings as the form edits them and the rest as read-only "set at install". */
function present_settings(array $s): array
{
    $edit = [];
    foreach (array_keys(SETTINGS_FORM) as $k) {
        $edit[$k] = $s[$k];
    }
    $fixed = [];
    foreach (['drawing_max_points', 'room_max_sockets', 'live_check_seconds', 'poll_seconds', 'key_rate_per_minute', 'key_rate_per_day', 'key_rotation_overlap_hours', 'timezone'] as $k) {
        $fixed[$k] = $s[$k];
    }
    return ['settings' => $edit, 'set_at_install' => $fixed, 'levels' => SETTINGS_LEVELS, 'updated_at' => json_ts($s['updated_at'])];
}
