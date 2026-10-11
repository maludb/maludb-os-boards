<?php
declare(strict_types=1);

/** The JSON shapes of history (slice 7) — whitelists, never a raw row. */

function present_change(array $r, string $board): array
{
    $fly = change_fly_to((array) $r['summary']);
    return ['seq' => (int) $r['seq'], 'who' => ['member_id' => $r['member_id'] === null ? null : (int) $r['member_id'], 'name' => $r['member_name'], 'kind' => $r['member_kind']],
            'source' => (string) $r['source'], 'what' => change_words((array) $r['summary']), 'items' => (int) ($r['summary']['items'] ?? 0),
            'at' => json_ts($r['at']), 'show' => $fly === null ? null : '/boards/' . $board . '?x=' . $fly['x'] . '&y=' . $fly['y'] . '&zoom=1',
            'undoes' => array_map('intval', (array) $r['undoes_seq'])];
}

function present_version(array $v): array
{
    return ['version_no' => (int) $v['version_no'], 'label' => $v['label'], 'reason' => (string) $v['reason'], 'reason_words' => VERSION_REASONS[(string) $v['reason']] ?? (string) $v['reason'],
            'named' => (string) $v['reason'] === 'manual' && $v['label'] !== null, 'items' => (int) $v['item_count'],
            'saved_by' => ['member_id' => $v['saved_by'] === null ? null : (int) $v['saved_by'], 'name' => $v['saved_by_name'] ?? null], 'at' => json_ts($v['created_at'])];
}

function present_visit(array $t): array
{
    return ['member_id' => (int) $t['member_id'], 'name' => (string) $t['name'], 'kind' => (string) $t['kind'], 'opens' => (int) $t['opens'], 'edits' => (int) $t['edits'], 'last_at' => json_ts($t['last_at'])];
}
