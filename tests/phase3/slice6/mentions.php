<?php
/**
 * Slice 6 — mentions (comments-notifications.md "Proof" 2, 3): "@SMOKE Bea" (who can see the board) is a mention notice and a queued email; "@SMOKE Lee" (who cannot) is
 * `not_shared` and nothing queued; "@Sales" tells each member of Sales who can see it; an agent shared at edit is ONE dispatch (conversation boards:thread:{root});
 * an agent not shared is `needs_share`, the rail's prompt, Share as the owner posts board_share and a second comment now dispatches; two members named alike stay text and
 * `unresolved_mentions` names them. Run through tests/phase3/slice6/run.sh.
 */
require __DIR__ . '/lib.php';
$seed = json_decode((string) file_get_contents(need('BD_S6_SEED')), true);
$b = $seed['main'];
[$jp] = sign_on(26);
[$jb] = sign_on(28);
[$jd] = sign_on(30);
[$jm] = sign_on(27);
$n0 = (int) one('SELECT COALESCE(max(id), 0) FROM notifications');
$o0 = (int) one('SELECT COALESCE(max(id), 0) FROM notification_outbox');
$d0 = (int) one('SELECT COALESCE(max(id), 0) FROM agent_dispatches');
$since = last_activity_id();

echo "2. A person who can see the board is told; one who cannot is not\n";
$r = thread_on($jp, $b, 'Please look at this, @SMOKE Bea, and at @SMOKE Lee too.', $seed['sticky']);
$j = body_json($r);
$root = (string) $j['record_id'];
ok($r['code'] === 200 && ($j['dispatched'] ?? null) === [] && ($j['needs_share'] ?? null) === [] && ($j['not_shared'] ?? null) === ['SMOKE Lee'], 'the answer: nobody dispatched, no share to ask for, "SMOKE Lee" not told');
$n = q("SELECT * FROM notifications WHERE id > :n AND kind = 'mention' AND member_id = 28", ['n' => $n0]);
ok(count($n) === 1 && $n[0]['record_uuid'] === $root && (string) $n[0]['board_id'] === $b && str_contains($n[0]['title'], 'mentioned you') && (int) $n[0]['actor_member_id'] === 26, 'Bea has one mention notice, naming the comment, the board and the writer');
ok(q("SELECT count(*) AS c FROM notifications WHERE id > :n AND member_id = 31", ['n' => $n0])[0]['c'] === 0, 'Lee, who cannot open the board, was told nothing');
$ob = q("SELECT * FROM notification_outbox WHERE id > :o AND member_id = 28 AND channel = 'email'", ['o' => $o0]);
ok(count($ob) === 1 && $ob[0]['status'] === 'queued' && $ob[0]['to_email'] === 'bea@example.invalid' && $ob[0]['kind'] === 'mention', 'Bea\'s email is queued (by her default preferences)');
ok(q("SELECT count(*) AS c FROM notification_outbox WHERE id > :o AND member_id = 31", ['o' => $o0])[0]['c'] === 0, 'nothing is queued for Lee');
ok(q("SELECT count(*) AS c FROM notification_outbox WHERE id > :o AND channel = 'text'", ['o' => $o0])[0]['c'] === 0, 'no text is queued (nobody opted in)');
ok(q("SELECT count(*) AS c FROM notifications WHERE id > :n AND member_id = 26", ['n' => $n0])[0]['c'] === 0, 'the writer is never told of her own comment');
$mr = q('SELECT kind, principal_id FROM comment_mentions WHERE comment_id = CAST(:c AS uuid) ORDER BY principal_id', ['c' => $root]);
ok(count($mr) === 2 && $mr[0] === ['kind' => 'member', 'principal_id' => 28] && $mr[1] === ['kind' => 'member', 'principal_id' => 31], 'both mentions are recorded (a mention is what was said; who was told is the wall\'s)');
$body = json_decode(comment_row($root)['body'], true);
ok(array_values(array_map(static fn ($r) => $r['type'], $body)) === ['text', 'mention', 'text', 'mention', 'text'] && $body[1]['mention'] == ['type' => 'member', 'id' => 28, 'name' => 'SMOKE Bea'] && $body[1]['plain_text'] === '@SMOKE Bea',
    'the text is stored as runs: text, mention (member 28), text, mention (member 31), text');
$card = page($jb, "/comments/thread.php?id=$root");
ok(str_contains($card['body'], 'comment-mention') && str_contains($card['body'], '@SMOKE Bea') && str_contains($card['body'], 'data-mention="member"'), 'the thread shows a mention as a chip');
$ns = body_json(comment_as($jp, 'create.php', ['board' => $b, 'text' => 'Ping @SMOKE Lee', 'x' => 1, 'y' => 1]));
ok(($ns['not_shared'] ?? null) === ['SMOKE Lee'], 'the not_shared answer names the person');
$th = comment_as($jp, 'create.php', ['board' => $b, 'text' => 'Ping @SMOKE Lee', 'x' => 2, 'y' => 2, 'dummy' => 1]);
$html = req('POST', '/comments/create.php', ['jar' => $jp, 'headers' => ['HX-Request: true', 'HX-Target: comment-rail-thread', 'X-CSRF-Token: ' . page_csrf($jp)], 'form' => ['board' => $b, 'text' => 'Ping @SMOKE Lee', 'x' => 3, 'y' => 3]]);
ok($html['code'] === 200 && str_contains($html['body'], 'SMOKE Lee was not told — they cannot open this board.') && str_contains($html['body'], 'class="comment-thread"') && preg_match('/^HX-Trigger: commentChanged/mi', $html['headers']) === 1,
    'over HTMX the rail gets the new thread with the line "SMOKE Lee was not told — they cannot open this board." and the commentChanged trigger');

echo "   a department\n";
$n1 = (int) one('SELECT COALESCE(max(id), 0) FROM notifications');
$r = thread_on($jb, $b, 'Sales, take a look: @Sales', $seed['shape']);
$root2 = (string) body_json($r)['record_id'];
$who = array_column(q("SELECT member_id FROM notifications WHERE id > :n AND kind = 'mention' AND record_uuid = CAST(:c AS uuid) ORDER BY member_id", ['n' => $n1, 'c' => $root2]), 'member_id');
ok($who === [26, 30], '@Sales tells each member of Sales who can see the board (Priya, Dana) — not Lee (cannot open it), not the writer');
$t = notices_of(30, 'mention')[0];
ok(str_contains($t['title'], 'mentioned your department'), 'the department notice says so: "' . $t['title'] . '"');
ok(q('SELECT count(*) AS c FROM comment_mentions WHERE comment_id = CAST(:c AS uuid) AND kind = \'department\' AND principal_id = 8', ['c' => $root2])[0]['c'] === 1, 'the department mention is recorded');
$n2 = (int) one('SELECT COALESCE(max(id), 0) FROM notifications');
thread_on($jb, $b, '@SMOKE Dana and @Sales', $seed['shape']);
ok(count(array_filter(q('SELECT member_id FROM notifications WHERE id > :n', ['n' => $n2]), static fn ($r) => $r['member_id'] === 30)) === 1, 'a person named and also in the department is told once');

echo "   a reply tells the thread's people\n";
$n3 = (int) one('SELECT COALESCE(max(id), 0) FROM notifications');
$r = comment_as($jb, 'reply.php', ['comment' => $root, 'text' => 'Seen it.']);
$rp = notices_of(26, 'reply');
ok($r['code'] === 200 && $rp !== [] && (int) $rp[0]['id'] > $n3 && str_contains($rp[0]['title'], 'replied'), 'Bea\'s reply tells the thread\'s other author (Priya): a reply notice');
ok(notices_of(28, 'reply') === [] || (int) notices_of(28, 'reply')[0]['id'] <= $n3, 'not Bea herself');
$n4 = (int) one('SELECT COALESCE(max(id), 0) FROM notifications');
thread_on($jb, $b, 'A fresh thread.', $seed['sticky']);
$nc = notices_of(26, 'comment');
ok($nc !== [] && (int) $nc[0]['id'] > $n4 && str_contains($nc[0]['title'], 'commented'), 'a new thread on a board tells its owner: a comment notice');

echo "   agents: a mention never grants\n";
$d1 = (int) one('SELECT COALESCE(max(id), 0) FROM agent_dispatches');
$r = thread_on($jp, $b, 'Expert, what do you think? @SMOKE Expert', $seed['sticky']);
$j = body_json($r);
$re = (string) $j['record_id'];
ok($r['code'] === 200 && ($j['dispatched'] ?? null) === [] && ($j['needs_share'] ?? null) === [['member_id' => 40, 'name' => 'SMOKE Expert']], 'an agent who cannot see the board: needs_share (the agent and its name), nothing dispatched');
ok((int) one('SELECT count(*) FROM agent_dispatches WHERE id > :d', ['d' => $d1]) === 0 && level_of($b, 40) === 'none', 'no dispatch row, and the mention gave the agent no access');
$pg = req('POST', '/comments/create.php', ['jar' => $jp, 'headers' => ['HX-Request: true', 'HX-Target: comment-rail-thread', 'X-CSRF-Token: ' . page_csrf($jp)], 'form' => ['board' => $b, 'text' => 'Expert again @SMOKE Expert', 'x' => 9, 'y' => 9]]);
ok(str_contains($pg['body'], 'id="needs-share-40"') && str_contains($pg['body'], 'SMOKE Expert cannot see this board. Share it at edit?') && str_contains($pg['body'], 'id="needs-share-40-share"') && str_contains($pg['body'], 'id="needs-share-40-skip"'),
    'the rail\'s prompt: "SMOKE Expert cannot see this board. Share it at edit?" with Share and Not now (the owner is full)');
$pb = req('POST', '/comments/create.php', ['jar' => $jb, 'headers' => ['HX-Request: true', 'HX-Target: comment-rail-thread', 'X-CSRF-Token: ' . page_csrf($jb)], 'form' => ['board' => $b, 'text' => 'Expert once more @SMOKE Expert', 'x' => 8, 'y' => 8]]);
ok(str_contains($pb['body'], 'Ask an owner to share it with SMOKE Expert') && !str_contains($pb['body'], 'id="needs-share-40-share"'), 'a writer who is not an owner reads "Ask an owner to share it with SMOKE Expert" and has no Share button');
// Share as the owner, as the prompt's button posts it (HTMX, the prompt as its target)
$sh = req('POST', '/boards/share-set.php', ['jar' => $jp, 'headers' => ['HX-Request: true', 'HX-Target: needs-share-40', 'X-CSRF-Token: ' . page_csrf($jp)], 'form' => ['board' => $b, 'member' => 40, 'level' => 'edit']]);
ok($sh['code'] === 200 && str_contains($sh['body'], 'id="needs-share-40"') && str_contains($sh['body'], 'can see this board now') && level_of($b, 40) === 'edit', 'Share (board_share member=40 level=edit) answers the prompt itself, and the agent is now at edit');
$d2 = (int) one('SELECT COALESCE(max(id), 0) FROM agent_dispatches');
$r = thread_on($jp, $b, 'Now, @SMOKE Expert?', $seed['sticky']);
$j = body_json($r);
$rx = (string) $j['record_id'];
$dp = q('SELECT * FROM agent_dispatches WHERE id > :d ORDER BY id', ['d' => $d2]);
ok($r['code'] === 200 && ($j['dispatched'] ?? null) === [40] && count($dp) === 1, 'a second comment now dispatches: one agent_dispatches row');
ok($dp[0]['kind'] === 'mention' && $dp[0]['via'] === 'chat' && (int) $dp[0]['agent_member_id'] === 40 && (int) $dp[0]['acting_member_id'] === 26 && $dp[0]['record_uuid'] === $rx
    && $dp[0]['conversation_id'] === 'boards:thread:' . $rx && $dp[0]['status'] === 'sent' && (string) $dp[0]['board_id'] === $b, 'the dispatch: mention, chat, the agent, acting as the writer, conversation_id boards:thread:{root}');
$r = comment_as($jb, 'reply.php', ['comment' => $rx, 'text' => 'Hey @SMOKE Expert, in the thread.']);
$dp2 = q('SELECT * FROM agent_dispatches WHERE id > :d ORDER BY id', ['d' => $d2]);
ok($r['code'] === 200 && count($dp2) === 2 && $dp2[1]['conversation_id'] === 'boards:thread:' . $rx, 'a mention in a reply is one more dispatch in the same conversation (boards:thread:{root})');
ok(notices_of(40) === [], 'an agent gets no bell notice (the dispatch is its notice)');
// an agent's own comment dispatches nobody
$d3 = (int) one('SELECT COALESCE(max(id), 0) FROM agent_dispatches');
$a = agent_act(40, 7001, '/comments/create.php', ['board' => $b, 'text' => 'I am here. @SMOKE Priya, @SMOKE Facilitator', 'item' => $seed['sticky']]);
$aj = body_json($a);
ok($a['code'] === 200 && ($aj['dispatched'] ?? null) === [] && ($aj['needs_share'] ?? null) === [] && (int) one('SELECT count(*) FROM agent_dispatches WHERE id > :d', ['d' => $d3]) === 0, 'an agent\'s comment dispatches nobody (no agent storms) and asks no one to share');
ok((int) comment_row((string) $aj['record_id'])['author_member_id'] === 40 && comment_row((string) $aj['record_id'])['agent_run_id'] === 7001, 'it is authored by the agent and carries the run');
$th = page($jp, '/comments/thread.php?id=' . $aj['record_id']);
ok(str_contains($th['body'], 'badge bg-soft-info text-info ms-1">agent<'), 'an agent author wears the agent chip');
ok(notices_of(26, 'mention')[0]['record_uuid'] === $aj['record_id'], 'the agent\'s mention of a person who can see the board tells them');

echo "3. A name two members share stays text\n";
pg("UPDATE members SET display_name = 'SMOKE Dana' WHERE id = 31");
$n5 = (int) one('SELECT COALESCE(max(id), 0) FROM notifications');
$r = thread_on($jp, $b, 'Which of you? @SMOKE Dana', $seed['sticky']);
$j = body_json($r);
$rd = (string) $j['record_id'];
ok($r['code'] === 200 && ($j['unresolved_mentions'] ?? null) === ['SMOKE Dana'], 'two members named "SMOKE Dana": the answer lists the name in unresolved_mentions');
$body = json_decode(comment_row($rd)['body'], true);
ok(count($body) === 1 && $body[0]['type'] === 'text' && str_contains($body[0]['plain_text'], '@SMOKE Dana'), 'the name stays plain text — never guessed');
ok((int) one("SELECT count(*) FROM notifications WHERE id > :n AND kind = 'mention'", ['n' => $n5]) === 0 && (int) one('SELECT count(*) FROM comment_mentions WHERE comment_id = CAST(:c AS uuid)', ['c' => $rd]) === 0, 'nobody is mentioned or told as a mention');
$pb = req('POST', '/comments/create.php', ['jar' => $jp, 'headers' => ['HX-Request: true', 'HX-Target: comment-rail-thread', 'X-CSRF-Token: ' . page_csrf($jp)], 'form' => ['board' => $b, 'text' => 'Again @SMOKE Dana', 'x' => 1, 'y' => 1]]);
ok(str_contains($pb['body'], 'id="comment-unresolved"') && str_contains($pb['body'], '“SMOKE Dana”'), 'the rail says the name is shared and nobody was told');
pg("UPDATE members SET display_name = 'SMOKE Lee' WHERE id = 31");
$r = thread_on($jp, $b, 'Now @SMOKE Dana and @smoke dana.', $seed['sticky']);
$body = json_decode(comment_row((string) body_json($r)['record_id'])['body'], true);
ok(count(array_filter($body, static fn ($x) => $x['type'] === 'mention')) === 2 && $body[1]['mention']['id'] === 30, 'once the names differ again the mention resolves — and it is case-insensitive');
$r = thread_on($jp, $b, 'Beatrice mail bea@example.invalid and @SMOKE Beatrice', $seed['sticky']);
$body = json_decode(comment_row((string) body_json($r)['record_id'])['body'], true);
ok(count($body) === 1, 'an address and a name that is not one are plain text');
$r = thread_on($jp, $b, '@SMOKE Beatrix', $seed['sticky']);
ok(count(json_decode(comment_row((string) body_json($r)['record_id'])['body'], true)) === 1, '"@SMOKE Beatrix" is not "@SMOKE Bea" followed by "trix": a name ends at a word boundary');
$r = thread_on($jp, $b, '@SMOKE Bea, thanks', $seed['sticky']);
$body = json_decode(comment_row((string) body_json($r)['record_id'])['body'], true);
ok($body[0]['type'] === 'mention' && $body[1]['plain_text'] === ', thanks', 'a mention at the start, followed by punctuation, resolves');

echo "   the trail\n";
$cr = activity('comment.create', $since);
$withAgents = array_values(array_filter($cr, static fn ($x) => (json_decode((string) $x['after'], true)['needs_share'] ?? []) === [40]));
ok(count($withAgents) >= 2, 'comment.create logs needs_share (agent ids) and dispatched (agent ids)');
$disp = array_values(array_filter($cr, static fn ($x) => (json_decode((string) $x['after'], true)['dispatched'] ?? []) === [40]));
ok(count($disp) === 1 && json_decode((string) $disp[0]['after'], true)['mentions']['agent'] === 1, '…and mentions by kind');
finish();
