<?php
/**
 * Slice 9 — the admin pages (agents-search.md "Proof" 9): /admin/agents and /admin/dispatches render for agents.manage and are 403 for a Member; the cards say who the agents are, which boards they can
 * open, what they did this week; the dispatches page filters, pages and offers Retry; the wall holds for a board the admin cannot open. Run through tests/phase3/slice9/run.sh.
 */
require __DIR__ . '/lib.php';
[$jp] = sign_on(26);
[$jm] = sign_on(27);
[$jb] = sign_on(28);
$since = last_activity_id();

echo "9. The admin pages\n";
[$b, $ids] = board_with_notes($jp, 'SMOKE Admin board', 2);
share_agents($b);
chat_reset();
kernel_chat(finished('Admin test reply.', 61));
$root = (string) body_json(thread_on($jp, $b, '@SMOKE Facilitator hello', $ids[0]))['record_id'];
dispatches_pass_cli();
$private = make_board($jp, 'SMOKE Admin private');
share_as(26, $private, 'agent', EXPERT, 'edit');
foreach (['/admin/agents', '/admin/dispatches'] as $path) {
    $r = page($jb, $path);
    ok($r['code'] === 403, "$path: 403 for a Member");
    $r = page($jb, $path, ['headers' => ['Accept: application/json']]);
    ok($r['code'] === 403 && (body_json($r)['error']['code'] ?? '') === 'forbidden', "$path (JSON): 403 forbidden");
    $r = req('GET', $path, ['headers' => ['Accept: application/json']]);
    ok($r['code'] === 401, "$path (anonymous JSON): 401");
}
$r = page($jm, '/admin/agents');
ok($r['code'] === 200 && str_contains($r['body'], 'id="agent-card-42"') && str_contains($r['body'], 'id="agent-card-40"') && str_contains($r['body'], 'SMOKE Facilitator') && !str_contains($r['body'], 'id="nav-stub"'), 'Marco (agents.manage) sees the agents page: a card per agent, no placeholder');
ok(!str_contains($r['body'], 'id="agent-card-41"'), 'an agent not admitted to Boards (the Watcher) has no card');
ok(str_contains($r['body'], 'id="admin-agents-os"') && str_contains($r['body'], 'kept in the OS'), 'a note says hiring, grants, models and duties are the kernel\'s, with a link to the OS');
$j = body_json(page($jm, '/admin/agents', ['headers' => ['Accept: application/json']]))['data']['agents'];
$f = array_values(array_filter($j, static fn ($a) => $a['agent_id'] === FACILITATOR))[0];
$want = (int) one("SELECT count(*) FROM boards b WHERE b.deleted_at IS NULL AND NOT b.is_template AND bd_board_level(b.id, 42) <> 'none'");
ok($f['name'] === 'SMOKE Facilitator' && $f['boards_visible'] === $want && $want >= 2 && $f['dispatches']['answered'] >= 1 && $f['dispatches']['failed'] >= 0 && is_array($f['roles']) && array_keys($f) === ['agent_id', 'name', 'job_title', 'roles', 'boards_visible', 'shared_by_name', 'week', 'dispatches'],
    'agents (JSON): a whitelist — name, roles, the boards it can open (' . $f['boards_visible'] . '), dispatches answered / failed / waiting');
$byName = array_column($f['shared_by_name'], 'level', 'title');
ok(($byName['SMOKE Admin board'] ?? null) === 'edit' && count($f['shared_by_name']) <= 10 && $f['shared_by_name'][0]['url'] === '/boards/' . $f['shared_by_name'][0]['board_id'], 'the boards shared with it by name are links (at most ten)');
ok($f['week'] === ['batches' => $f['week']['batches'], 'comments' => $f['week']['comments']] && $f['week']['comments'] >= 1, 'this week\'s batches and comments are counted (the reply is a comment: ' . json_encode($f['week']) . ')');
$ex = array_values(array_filter($j, static fn ($a) => $a['agent_id'] === EXPERT))[0];
ok($ex['boards_visible'] >= 1 && $ex['dispatches'] === ['answered' => 0, 'failed' => 0, 'waiting' => 0], 'the Expert: its boards, no dispatches');
// an agent's batch is counted
share_as(26, $b, 'agent', FACILITATOR, 'edit');
$a = agent_act(FACILITATOR, 9701, '/items/arrange.php', ['board' => $b, 'items' => $ids, 'layout' => 'row']);
$j2 = body_json(page($jm, '/admin/agents', ['headers' => ['Accept: application/json']]))['data']['agents'];
$f2 = array_values(array_filter($j2, static fn ($x) => $x['agent_id'] === FACILITATOR))[0];
ok($a['code'] === 200 && $f2['week']['batches'] === $f['week']['batches'] + 1, 'an agent\'s batch on a board is counted in its week');
// dispatches
$r = page($jm, '/admin/dispatches');
$d = dispatch_of($root);
ok($r['code'] === 200 && str_contains($r['body'], 'id="dispatch-row-' . $d['id'] . '"') && str_contains($r['body'], 'Answered') && str_contains($r['body'], 'id="dispatch-status-' . $d['id'] . '"') && !str_contains($r['body'], 'id="dispatch-retry-' . $d['id'] . '"'),
    'the dispatches page lists it: Answered, no Retry on an answered one');
ok(str_contains($r['body'], 'hello') && str_contains($r['body'], 'id="dispatch-comment-' . $d['id'] . '"') && str_contains($r['body'], '?comment=' . $root), 'the comment\'s first 80 characters link to the board at the comment');
$jd = body_json(page($jm, '/admin/dispatches', ['headers' => ['Accept: application/json']]))['data'];
$row = array_values(array_filter($jd['rows'], static fn ($x) => $x['dispatch_id'] === (int) $d['id']))[0];
ok(array_keys($jd) === ['filters', 'page', 'page_size', 'total', 'statuses', 'rows'] && $jd['page_size'] === 50 && $row['status'] === 'answered' && $row['status_words'] === 'Answered' && $row['agent']['name'] === 'SMOKE Facilitator' && $row['asked_by']['name'] === 'SMOKE Priya'
    && $row['board']['title'] === 'SMOKE Admin board' && $row['reply_excerpt'] === 'Admin test reply.' && $row['may_retry'] === false && $row['url'] === '/boards/' . $b . '?comment=' . $root, 'dispatches (JSON): a whitelist row with agent, asker, board, comment link, reply excerpt, may_retry');
// failed + running + waiting rows and the filters
kernel_chat(null, 500);
$rf = (string) body_json(thread_on($jp, $b, '@SMOKE Facilitator will fail', $ids[0]))['record_id'];
foreach ([0, 180, 480, 1020, 2040] as $off) { dispatches_pass_cli($off === 0 ? null : clock($off)); }
$df = dispatch_of($rf);
kernel_chat(['run_id' => 88, 'finished' => false]);
$rr = (string) body_json(thread_on($jp, $b, '@SMOKE Facilitator long one', $ids[0]))['record_id'];
dispatches_pass_cli(clock(3000));
$dr = dispatch_of($rr);
$all = body_json(page($jm, '/admin/dispatches', ['headers' => ['Accept: application/json']]))['data'];
$byId = array_column($all['rows'], null, 'dispatch_id');
ok($byId[(int) $df['id']]['status'] === 'failed' && $byId[(int) $df['id']]['may_retry'] === true && $byId[(int) $df['id']]['detail'] === 'The kernel answered 500.' && $byId[(int) $dr['id']]['status_words'] === 'Working', 'a failed one may be retried (its detail shown); a sent one with a run reads "Working"');
$fl = body_json(page($jm, '/admin/dispatches?status=failed', ['headers' => ['Accept: application/json']]))['data'];
ok($fl['filters'] == ['status' => 'failed'] && array_unique(array_column($fl['rows'], 'status')) === ['failed'] && in_array((int) $df['id'], array_column($fl['rows'], 'dispatch_id'), true) && $fl['total'] === count($fl['rows']) && $fl['total'] < $all['total'], 'status=failed shows only the failed');
$ag = body_json(page($jm, '/admin/dispatches?agent=42', ['headers' => ['Accept: application/json']]))['data'];
$ag2 = body_json(page($jm, '/admin/dispatches?agent=40', ['headers' => ['Accept: application/json']]))['data'];
ok($ag['total'] === $all['total'] && $ag2['total'] === 0, 'agent= filters by agent (42: all of them, 40: none)');
$h = page($jm, '/admin/dispatches?status=failed');
ok(str_contains($h['body'], 'id="dispatch-retry-' . $df['id'] . '"') && str_contains($h['body'], 'action="/admin/dispatch-retry.php"') && str_contains($h['body'], 'id="dispatch-filter-status-failed"') && str_contains($h['body'], 'id="dispatch-filter-agent"'), 'Retry is on the failed one; the filters have their ids');
$bad = body_json(page($jm, '/admin/dispatches?status=bogus&agent=x', ['headers' => ['Accept: application/json']]))['data'];
ok($bad['filters'] == [] && $bad['total'] === $all['total'], 'an unknown status and an agent that is not a number are ignored');
// paging: 50
pg("INSERT INTO agent_dispatches (record_type, record_uuid, agent_member_id, kind, via, acting_member_id, board_id, conversation_id, status)
    SELECT 'comment', '$root', 42, 'mention', 'chat', 26, '$b', 'boards:thread:x', 'answered' FROM generate_series(1, 55)");
$p1 = body_json(page($jm, '/admin/dispatches', ['headers' => ['Accept: application/json']]))['data'];
$p2 = body_json(page($jm, '/admin/dispatches?page=2', ['headers' => ['Accept: application/json']]))['data'];
ok(count($p1['rows']) === 50 && count($p2['rows']) >= 5 && $p1['total'] === $p2['total'], 'dispatches are paged 50');
// the wall: a board the admin cannot open shows neither title nor words
pg("UPDATE boards SET deleted_at = now() WHERE id = '$b'");
$w = body_json(page($jm, '/admin/dispatches?status=failed', ['headers' => ['Accept: application/json']]))['data'];
$wr = array_values(array_filter($w['rows'], static fn ($x) => $x['dispatch_id'] === (int) $df['id']))[0] ?? null;
ok($wr !== null && $wr['board'] === null && $wr['comment_excerpt'] === null && $wr['url'] === null, 'a dispatch on a board that cannot be opened (here, in the trash) shows no title, no words, no link');
$h = page($jm, '/admin/dispatches?status=failed');
ok(str_contains($h['body'], 'a board you cannot open') && !str_contains($h['body'], 'will fail') && str_contains($h['body'], 'id="dispatch-row-' . $df['id'] . '"'), 'and the page says so, without the comment\'s words');
pg("UPDATE boards SET deleted_at = NULL WHERE id = '$b'");
$r = retry_as($jm, (int) $df['id']);
$h = page($jm, '/admin/dispatches?notice=retried');
ok($r['code'] === 200 && str_contains($h['body'], 'id="notice-banner"') && str_contains($h['body'], 'Back in the queue'), 'after Retry the page says it is back in the queue');
$sv = q("SELECT screen FROM activity_log WHERE action = 'screen.view' AND id > :s AND screen IN ('admin-agents', 'admin-dispatches')", ['s' => $since]);
ok(count($sv) >= 2, 'screen.view is logged on both pages');
finish();

