<?php
declare(strict_types=1);

/** The JSON shapes of boards (slice 1) — whitelists, never a raw row; a board's URL with every shape. */

function present_board_row(array $b): array
{
    return ['board_id' => (string) $b['board_id'], 'title' => $b['title'], 'owner' => ['member_id' => $b['owner_member_id'] !== null ? (int) $b['owner_member_id'] : null, 'display_name' => $b['owner_name']],
            'my_level' => $b['my_level'], 'is_locked' => (bool) $b['is_locked'], 'item_count' => (int) $b['item_count'], 'open_threads' => (int) $b['open_threads'],
            'is_favorite' => (bool) $b['is_favorite'], 'last_activity_at' => json_ts($b['last_activity_at']), 'url' => '/boards/' . $b['board_id'],
            'thumbnail_url' => !empty($b['thumbnail_id']) ? '/boards/' . $b['board_id'] . '/thumbnail?v=' . (int) $b['thumbnail_id'] : null];
}

function present_board(array $b): array
{
    return present_board_row($b) + ['description' => $b['description'], 'background' => $b['background'], 'start_view' => $b['start_view'],
        'everyone_level' => $b['everyone_level'], 'folders' => array_map(static fn (array $f): array => ['folder_id' => (int) $f['folder_id'], 'name' => $f['name'],
        'is_team' => (bool) $f['is_team'], 'mine' => (bool) $f['mine']], $b['folders'] ?? []), 'created_at' => json_ts($b['created_at'])];
}

function present_trash_row(array $t): array
{
    return ['board_id' => (string) $t['board_id'], 'title' => $t['title'], 'owner_name' => $t['owner_name'], 'item_count' => (int) $t['item_count'],
            'deleted_at' => json_ts($t['deleted_at']), 'deleted_by' => $t['deleted_by_name'], 'purge_after' => json_ts($t['purge_after'])];
}
