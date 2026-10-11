<?php
declare(strict_types=1);

/** The JSON shapes of comments (slice 6) — whitelists, never a raw row. */

function present_comment(array $c): array
{
    return ['comment_id' => (string) $c['comment_id'], 'thread_id' => (string) ($c['thread_id'] ?? $c['comment_id']), 'item_id' => ($c['item_id'] ?? null) !== null ? (string) $c['item_id'] : null,
            'author' => ['member_id' => $c['author_member_id'] === null ? null : (int) $c['author_member_id'], 'name' => $c['author_name'], 'kind' => $c['author_kind']],
            'text' => (string) $c['plain_text'], 'body' => $c['body'], 'created_at' => json_ts($c['created_at']), 'edited_at' => json_ts($c['edited_at'] ?? null),
            'resolved' => ($c['resolved_at'] ?? null) !== null];
}

function present_thread_row(array $r): array
{
    return ['comment_id' => (string) $r['comment_id'], 'where' => comment_where_words($r), 'item_id' => $r['item_id'] === null ? null : (string) $r['item_id'],
            'author' => ['member_id' => $r['author_member_id'] === null ? null : (int) $r['author_member_id'], 'name' => $r['author_name'], 'kind' => $r['author_kind']],
            'text' => (string) $r['plain_text'], 'replies' => (int) $r['replies'], 'created_at' => json_ts($r['created_at']), 'resolved' => $r['resolved_at'] !== null,
            'resolved_by' => $r['resolved_by_name'] ?? null, 'url' => '/boards/' . $r['board_id'] . '?comment=' . $r['comment_id']];
}

function present_thread(array $t): array
{
    return ['root' => present_comment($t['root']) + ['where' => comment_where_words($t['root']), 'resolved_by' => $t['root']['resolved_by_name'] ?? null],
            'replies' => array_map('present_comment', $t['replies'])];
}
