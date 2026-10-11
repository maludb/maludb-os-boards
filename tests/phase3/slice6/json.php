<?php
/**
 * Slice 6 — JSON mode on every handler and screen (comments-notifications.md "Proof" 9): the kernel's actions server and the command bar speak JSON; every comment, notification and
 * preference handler answers {ok, did, record_id, location …} or the kit's error {error: {code, message}}, as an agent's run token and as a person's action token; the three screens
 * and the pins answer a whitelist; an anonymous caller is 401; the log vocabulary of the slice. Run through tests/phase3/slice6/run.sh.
 */
require __DIR__ . '/lib.php';
$seed = json_decode((string) file_get_contents(need('BD_S6_SEED')), true);
$b = $seed['main'];
[$jp] = sign_on(26);
[$jb] = sign_on(28);
$since = last_activity_id();
$anon = ['Accept: application/json'];

echo "9. Every handler in JSON mode\n";
foreach (['create', 'reply', 'resolve', 'reopen', 'edit', 'delete'] as $h) {
    $r = req('POST', "/comments/$h.php", ['headers' => $anon, 'form' => ['comment' => uuid4(), 'board' => $b, 'text' => 'x']]);
    ok($r['code'] === 401 && (body_json($r)['error']['code'] ?? '') === 'unauthorized', "$h.php, anonymous: 401 unauthorized (JSON)");
}
foreach (['/notifications/read.php', '/notifications/read-all.php', '/settings/prefs.php'] as $h) {
    $r = req('POST', $h, ['headers' => $anon, 'form' => ['all' => 'yes']]);
    ok($r['code'] === 401, "$h, anonymous: 401");
}
$g = req('GET', '/comments/create.php', ['jar' => $jp, 'headers' => $anon]);
ok($g['code'] === 405 && (body_json($g)['error']['code'] ?? '') === 'method_not_allowed', 'a handler wants POST (405 method_not_allowed)');
$noCsrf = req('POST', '/comments/create.php', ['jar' => $jp, 'headers' => $anon, 'form' => ['board' => $b, 'text' => 'x', 'x' => 1, 'y' => 1]]);
ok($noCsrf['code'] === 403, 'a session POST without the CSRF token is refused (403)');

// as a person's session
$r = comment_as($jp, 'create.php', ['board' => $b, 'text' => 'JSON thread', 'item' => $seed['sticky']]);
$j = body_json($r);
ok($r['code'] === 200 && $j['ok'] === true && $j['did'] === 'Commented' && isset($j['record_id'], $j['location'], $j['board_id'], $j['thread_id'], $j['dispatched'], $j['needs_share'], $j['not_shared'], $j['unresolved_mentions'])
    && $j['refresh'] === 'commentChanged', 'comment_create answers ok, did, record_id, location, board_id, thread_id, dispatched, needs_share, not_shared, unresolved_mentions');
ok(str_starts_with($j['location'], "/boards/$b/comments"), 'its location is the comments page');
$root = (string) $j['record_id'];
foreach ([['reply.php', ['comment' => $root, 'text' => 'JSON reply'], 'Replied'], ['resolve.php', ['comment' => $root], 'Resolved the thread'], ['reopen.php', ['comment' => $root], 'Reopened the thread']] as [$f, $form, $did]) {
    $r = comment_as($jp, $f, $form);
    $j = body_json($r);
    ok($r['code'] === 200 && $j['ok'] === true && $j['did'] === $did && preg_match('/^[0-9a-f-]{36}$/', (string) $j['record_id']) === 1 && $j['thread_id'] === $root, "$f: ok, did \"$did\", record_id, thread_id");
}
$r = comment_as($jp, 'edit.php', ['comment' => $root, 'text' => 'JSON edited']);
ok($r['code'] === 200 && body_json($r)['did'] === 'Edited the comment' && body_json($r)['record_id'] === $root, 'edit.php: ok, record_id the comment');
$r = comment_as($jp, 'delete.php', ['comment' => $root]);
ok($r['code'] === 200 && body_json($r)['did'] === 'Deleted the comment', 'delete.php: ok');
$r = comment_as($jp, 'create.php', ['board' => $b, 'x' => 1, 'y' => 1]);
$j = body_json($r);
ok($r['code'] === 422 && $j['error']['code'] === 'invalid' && isset($j['error']['fields']['text']) && $j['error']['errors'][0] === 'The comment is required.', 'a refusal: 422 invalid with errors[] and fields{text}');
$r = comment_as($jp, 'create.php', ['board' => 'not-a-uuid', 'text' => 'x']);
ok($r['code'] === 404 && body_json($r)['error']['code'] === 'not_found', 'a board that is not one: 404 not_found');
$r = comment_as($jp, 'reply.php', ['text' => 'x']);
ok($r['code'] === 404 && body_json($r)['error']['message'] === 'There is no such thread.', 'a reply with no comment: 404 in words');
$r = comment_as($jp, 'resolve.php', ['comment' => 'zzz']);
ok($r['code'] === 404, 'a malformed comment id: 404');
$r = post_as($jp, '/notifications/read.php', ['notification' => 'abc']);
ok($r['code'] === 404, 'a malformed notification id: 404');

// as an agent (a run token relayed by the kernel's actions server): the same handlers, the run on the row
$a = agent_act(42, 7100, '/comments/create.php', ['board' => $b, 'text' => 'Agent pin', 'x' => 12, 'y' => 34]);
ok($a['code'] === 403 || $a['code'] === 404, 'the Facilitator, not shared on the board: it does not exist to it (' . $a['code'] . ')');
share_as(26, $b, 'agent', 42, 'comment');
$a = agent_act(42, 7100, '/comments/create.php', ['board' => $b, 'text' => 'Agent pin', 'x' => 12, 'y' => 34, 'context' => 'a,b']);
$aj = body_json($a);
ok($a['code'] === 200 && $aj['ok'] === true && comment_row((string) $aj['record_id'])['agent_run_id'] === 7100 && (int) comment_row((string) $aj['record_id'])['author_member_id'] === 42, 'an agent at the comment level comments: the run is on the row');
$ar = agent_act(42, 7101, '/comments/reply.php', ['comment' => $aj['record_id'], 'text' => 'And replies']);
ok($ar['code'] === 200 && comment_row((string) body_json($ar)['record_id'])['agent_run_id'] === 7101, 'and replies (its own run)');
$ad = agent_act(42, 7102, '/comments/delete.php', ['comment' => $aj['record_id']]);
ok($ad['code'] === 200, 'an agent deletes its own comment (the kernel pauses the call before it gets here — deletion)');
$other = body_json(thread_on($jb, $b, "Bea's", null))['record_id'];
$ad = agent_act(42, 7103, '/comments/delete.php', ['comment' => $other]);
ok($ad['code'] === 403 && str_contains(body_json($ad)['error']['message'], 'Only its author or the board'), 'an agent deleting another\'s comment at the comment level: refused in the database\'s words');
$asa = activity('comment.create', $since);
ok(count(array_filter($asa, static fn ($x) => $x['source'] === 'agent' && (int) $x['agent_run_id'] === 7100)) === 1, 'the agent\'s comment is logged with source agent and its run');
// a person's action token (the command bar)
$pt = req('POST', '/comments/create.php', ['headers' => as_agent(person_token(26)), 'form' => ['board' => $b, 'text' => 'From the command bar', 'x' => 3, 'y' => 4]]);
ok($pt['code'] === 200 && count(array_filter(activity('comment.create', $since), static fn ($x) => $x['source'] === 'assistant')) === 1, 'a person\'s action token (the command bar) comments as the assistant');
// notifications and prefs as an agent: an agent has no notices but may read its own (empty)
$ar = req('POST', '/notifications/read-all.php', ['headers' => as_agent(run_token(42, 7104)), 'form' => ['all' => 'yes']]);
ok($ar['code'] === 200 && body_json($ar)['count'] === 0, 'an agent: Mark all read is its own (0)');

echo "   the screens\n";
$s = body_json(page($jp, "/boards/$b/comments", ['headers' => $anon]));
ok(isset($s['data']['rows'], $s['data']['total'], $s['data']['page']) && !array_key_exists('body', $s['data']['rows'][0] ?? []), 'board-comments (JSON): rows, total, page — a whitelist');
$s = body_json(page($jp, '/notifications', ['headers' => $anon]));
ok(isset($s['data']['unread'], $s['data']['rows']) && ($s['data']['rows'] === [] || array_keys($s['data']['rows'][0]) === ['notification_id', 'kind', 'title', 'body', 'board_id', 'board_title', 'url', 'actor', 'read', 'created_at']), 'notifications (JSON): unread, rows with a fixed shape');
$s = body_json(page($jp, '/settings/', ['headers' => $anon]));
ok(array_keys($s['data']['prefs']) === ['email_enabled', 'text_enabled', 'digest', 'kinds', 'text_kinds'], 'settings (JSON): the prefs');
$s = page($jp, "/comments/thread.php?id=$other", ['headers' => $anon]);
ok($s['code'] === 200 && isset(body_json($s)['data']['root']['text']), 'the thread (JSON)');
ok(page($jp, "/comments/thread.php", ['headers' => $anon])['code'] === 422, 'the thread with no id (JSON): 422');

echo "   the slice's log vocabulary\n";
$all = array_unique(array_column(q("SELECT action FROM activity_log WHERE id > :s AND (action LIKE 'comment.%' OR action LIKE 'notification.%' OR action = 'settings.prefs' OR action = 'outbox.send')", ['s' => $since]), 'action'));
sort($all);
$want = ['comment.create', 'comment.delete', 'comment.edit', 'comment.reopen', 'comment.reply', 'comment.resolve', 'notification.read_all'];
ok(array_values(array_intersect($want, $all)) === $want, 'comment.create / reply / resolve / reopen / edit / delete and notification.read_all are all logged here: ' . implode(', ', $all));
$unlogged = q("SELECT count(*) AS c FROM activity_log WHERE id > :s AND action LIKE 'comment.%' AND board_id IS NULL", ['s' => $since])[0]['c'];
ok($unlogged === 0, 'every comment row carries the board');
finish();
