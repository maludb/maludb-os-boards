<?php
/**
 * Helpers for slice 2's proofs (docs/build-specs/canvas.md "Proof") — slice 1's (and through it Phase 2's: the requests, the hand-off, the
 * scratch database as the application and as postgres) plus the canvas's own: a batch posted as the island posts it, the state and the
 * catch-up, an item to create, an agent's item action. Run them through tests/phase3/slice2/run.sh (scratch bd_dev2). The fixture is
 * bin/dev_directory.json's (26 Priya a Member, 27 Marco a Boards admin, 28 Bea a Member by capability, 30 Dana a Viewer, 40 the Expert and
 * 42 the Facilitator — agents, Members; 41 the Watcher — no grant).
 */
require dirname(__DIR__) . '/slice1/lib.php';

function uuid4(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}
/** A page's CSRF token for a jar (from the canvas's own mount when given a board, else any page). */
function csrf_for(string $jar): string { static $c = []; return $c[$jar] ??= page_csrf($jar); }
/** POST a batch as the island does: JSON body, X-CSRF-Token, Accept JSON. */
function ops_post(string $jar, string $board, array $ops, ?string $batch = null, ?string $client = 'proof-tab', array $extraHeaders = []): array
{
    $body = ['ops' => $ops] + ($batch !== null ? ['client_batch_id' => $batch] : []) + ($client !== null ? ['client_id' => $client] : []);
    return req('POST', "/boards/$board/ops", ['jar' => $jar, 'json' => $body,
        'headers' => array_merge(['Accept: application/json', 'X-CSRF-Token: ' . csrf_for($jar)], $extraHeaders)]);
}
function state_of(string $jar, string $board): array { return req('GET', "/boards/$board/state", ['jar' => $jar, 'headers' => ['Accept: application/json']]); }
function ops_since(string $jar, string $board, int $after): array { return req('GET', "/boards/$board/ops?after=$after", ['jar' => $jar, 'headers' => ['Accept: application/json']]); }
/** A create op for an item (a sticky by default). */
function item_op(array $over = []): array
{
    $text = $over['text'] ?? null;
    unset($over['text']);
    $item = $over + ['id' => uuid4(), 'type' => 'sticky_note', 'parent_id' => null, 'x' => 0, 'y' => 0, 'width' => 200, 'height' => 200, 'rotation' => 0,
                     'z' => 'a0', 'style' => ['fillColor' => 'light_yellow'], 'data' => [], 'locked' => false];
    if ($text !== null) {
        $item['data']['content'] = [['type' => 'text', 'text' => ['content' => $text, 'link' => null], 'plain_text' => $text]];
    }
    return ['op' => 'create', 'item' => $item];
}
/** An item action as an agent (a run token relayed by the kernel's actions server) or as a person's session (JSON). */
function agent_act(int $agent, int $run, string $file, array $form): array
{
    return req('POST', $file, ['headers' => as_agent(run_token($agent, $run)), 'form' => $form]);
}
/** Share a board as its owner (postgres acting as the owner — the schema's own function). */
function share_as(int $owner, string $board, string $kind, int $principal, string $level): void
{
    pg("SELECT set_config('app.member_id', '$owner', false) \\g /dev/null\nSELECT bd_board_share('$board', $owner, '$kind', $principal, '$level') \\g /dev/null");
}
function item_row(string $id): array { return q('SELECT * FROM board_items WHERE id = CAST(:i AS uuid)', ['i' => $id])[0] ?? []; }
function plain(string $id): string { return (string) one('SELECT plain_text FROM board_items WHERE id = CAST(:i AS uuid)', ['i' => $id]); }
/** A PHP list as a PostgreSQL array literal (the kit's, for the proofs' own queries). */
function pg_array_literal(array $values): string
{
    return '{' . implode(',', array_map(static fn ($v): string => is_int($v) ? (string) $v : '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $v) . '"', $values)) . '}';
}
