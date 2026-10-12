<?php
/**
 * Slice 9's helpers (docs/build-specs/agents-search.md "Proof"), on slice 8's (and through it 7's … Phase 2's): the fake kernel's chat state and its log, one pass of the real worker
 * (dispatches, search_catch_up) at a fake clock, a dispatch row, the admin pages and search as JSON or HTML, the arrangement action. `php tests/phase3/slice9/seed.php` makes the
 * browser's boards as JSON. The fixture's names are "SMOKE Priya" (26, a Member), "SMOKE Bea" (28, a Member), "SMOKE Facilitator" (42, an agent) …; Marco (27) is a Boards admin.
 */
require dirname(__DIR__) . '/slice8/lib.php';

const FACILITATOR = 42;
const EXPERT = 40;

function chat_log_file(): string { return need('FAKE_KERNEL_STATE') . '.chat'; }
/** The fake kernel's chat log, one decoded line each: POSTs have `agent`, polls have `run`. */
function chat_lines(): array
{
    return array_values(array_filter(array_map(static fn (string $l): array => json_decode($l, true) ?: [], is_file(chat_log_file()) ? file(chat_log_file(), FILE_IGNORE_NEW_LINES) : [])));
}
function chat_posts(): array { return array_values(array_filter(chat_lines(), static fn (array $l): bool => array_key_exists('agent', $l))); }
function chat_polls(): array { return array_values(array_filter(chat_lines(), static fn (array $l): bool => array_key_exists('run', $l))); }
function chat_reset(): void { @unlink(chat_log_file()); }
/** Replace the fake kernel's chat state: the POST answer ($chat, $status) and the polls ($runs: run id => body; $runStatus: run id => http status). */
function kernel_chat(?array $chat, ?int $status = null, array $runs = [], array $runStatus = [], ?string $message = null): void
{
    kernel_state(function (array $s) use ($chat, $status, $runs, $runStatus, $message): array {
        unset($s['chat'], $s['chat_status'], $s['chat_message'], $s['runs'], $s['run_status'], $s['run_message']);
        if ($chat !== null) { $s['chat'] = $chat; }
        if ($status !== null) { $s['chat_status'] = $status; }
        if ($message !== null) { $s['chat_message'] = $message; }
        if ($runs !== []) { $s['runs'] = $runs; }
        if ($runStatus !== []) { $s['run_status'] = $runStatus; }
        return $s;
    });
}
/** A finished turn's body. */
function finished(string $reply, int $run = 1, array $more = []): array { return ['run_id' => $run, 'status' => 'succeeded', 'finished' => true, 'reply' => $reply] + $more; }
/** An ISO clock $seconds after the real now. */
function clock(int $seconds): string { return (new DateTimeImmutable('now'))->modify(($seconds >= 0 ? '+' : '') . $seconds . ' seconds')->format(DATE_ATOM); }
function dispatches_pass_cli(?string $now = null): array { return worker_pass('dispatches', $now); }
function dispatch_row(int $id): array { return q('SELECT * FROM agent_dispatches WHERE id = :i', ['i' => $id])[0] ?? []; }
/** The newest dispatch of a comment. */
function dispatch_of(string $comment): array { return q('SELECT * FROM agent_dispatches WHERE record_uuid = CAST(:c AS uuid) ORDER BY id DESC LIMIT 1', ['c' => $comment])[0] ?? []; }
function comment_plain(string $id): string { return (string) one('SELECT plain_text FROM comments WHERE id = CAST(:i AS uuid)', ['i' => $id]); }
/** The live replies of a thread, oldest first. */
function replies_of(string $root): array { return q('SELECT * FROM comments WHERE parent_comment_id = CAST(:r AS uuid) AND deleted_at IS NULL ORDER BY created_at, id', ['r' => $root]); }
function search_json(string $jar, string $query): array { return page($jar, '/search?' . $query, ['headers' => ['Accept: application/json']]); }
function search_rows(string $jar, string $query): array { return body_json(search_json($jar, $query))['data']['rows'] ?? []; }
function arrange_as(string $jar, string $board, array $items, string $layout, array $more = [], array $headers = ['Accept: application/json']): array
{
    return post_as($jar, '/items/arrange.php', ['board' => $board, 'items' => $items, 'layout' => $layout] + $more, $headers);
}
function retry_as(string $jar, $dispatch, array $headers = ['Accept: application/json']): array { return post_as($jar, '/admin/dispatch-retry.php', ['dispatch' => $dispatch], $headers); }
/** A board of Priya's shared with the Facilitator at edit and Bea at edit, with $n stickies "Note 1" … in a row. [board, [item ids]] */
function board_with_notes(string $jar, string $title, int $n = 6, array $fills = []): array
{
    $b = make_board($jar, $title);
    $ops = [];
    $ids = [];
    for ($i = 1; $i <= $n; $i++) {
        $op = item_op(['text' => "Note $i", 'x' => ($i - 1) * 300, 'y' => 0, 'z' => 'a' . $i, 'style' => ['fillColor' => $fills[$i - 1] ?? 'light_yellow']]);
        $ops[] = $op;
        $ids[] = $op['item']['id'];
    }
    ops_post($jar, $b, $ops);
    return [$b, $ids];
}
function share_agents(string $board): void
{
    share_as(26, $board, 'agent', FACILITATOR, 'edit');
    share_as(26, $board, 'member', 28, 'edit');
}
/** An item's [x, y, parent] now. */
function place_of(string $id): array { $r = item_row($id); return [(float) $r['x'], (float) $r['y'], $r['parent_id']]; }
/** The kernel's run facts for an agent's run (so its batches carry the run's request id and a retried call commits once). */
function run_facts(int $run, int $agent): void
{
    kernel_state(function (array $s) use ($run, $agent): array { $s['facts'][(string) $run] = ['valid' => true, 'is_agent' => true, 'member_id' => $agent, 'run_id' => $run, 'request_id' => 'r-' . $run,
        'endpoints' => [['id' => 1, 'application' => APP]]]; return $s; });
}
