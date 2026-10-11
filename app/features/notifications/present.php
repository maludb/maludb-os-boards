<?php
declare(strict_types=1);

/** The JSON shapes of notifications and preferences (slice 6) — whitelists. */

/** The icon a kind wears. */
function notice_icon(string $kind): string
{
    return ['mention' => 'feather-at-sign', 'reply' => 'feather-corner-down-right', 'comment' => 'feather-message-circle', 'share' => 'feather-share-2',
            'agent_replied' => 'feather-cpu', 'digest' => 'feather-mail'][$kind] ?? 'feather-bell';
}

function present_notification(array $n): array
{
    return ['notification_id' => (int) $n['notification_id'], 'kind' => $n['kind'], 'title' => $n['title'], 'body' => $n['body'], 'board_id' => $n['board_id'] === null ? null : (string) $n['board_id'],
            'board_title' => $n['board_title'] ?? null, 'url' => notification_url($n), 'actor' => $n['actor_name'] ?? null, 'read' => $n['read_at'] !== null, 'created_at' => json_ts($n['created_at'])];
}

function present_prefs(array $p): array
{
    return ['email_enabled' => $p['email_enabled'], 'text_enabled' => $p['text_enabled'], 'digest' => $p['digest'], 'kinds' => array_values($p['kinds']), 'text_kinds' => array_values($p['text_kinds'])];
}
