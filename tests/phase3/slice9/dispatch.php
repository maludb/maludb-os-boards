<?php
/**
 * Slice 9 — the dispatch loop (agents-search.md "Proof" 1–5): a mention of the Facilitator becomes ONE chat turn of that agent as the asker, answered as the agent's reply comment; a run still going shows
 * "{Agent} is working…" and is polled; an approval pause is said in the placeholder and polled until a person decides; a refusal is shown in the kernel's words; a 5xx is backed off 2, 4, 8, 16 minutes and
 * told once; Retry puts a failed one back. Run through tests/phase3/slice9/run.sh.
 */
require __DIR__ . '/lib.php';
[$jp] = sign_on(26);
[$jb] = sign_on(28);
[$jm] = sign_on(27);
$since = last_activity_id();
[$b, $ids] = board_with_notes($jp, 'SMOKE Dispatch board', 4);
share_agents($b);
$agentName = 'SMOKE Facilitator';

echo "1. A mention is ONE chat turn as the agent, the asker acting\n";
chat_reset();
kernel_chat(finished('Moved the notes into a grid. @SMOKE Bea take a look.', 5, ['request_id' => 'req-5', 'cost' => 0.0123, 'currency' => 'USD']));
$d0 = (int) one('SELECT COALESCE(max(id), 0) FROM agent_dispatches');
$n0 = (int) one('SELECT COALESCE(max(id), 0) FROM notifications');
$r = thread_on($jp, $b, '@SMOKE Facilitator please tidy these', $ids[0], ['context' => [$ids[0], $ids[1]]]);
$root = (string) body_json($r)['record_id'];
$d = dispatch_of($root);
ok($r['code'] === 200 && $d !== [] && $d['status'] === 'sent' && $d['run_id'] === null, 'the comment made one dispatch, sent, no run yet');
$w = dispatches_pass_cli();
ok($w['rc'] === 0 && $w['errors'] === [] && !isset($w['ran']['dispatches']['stub']) && $w['ran']['dispatches']['called'] === 1 && $w['ran']['dispatches']['answered'] === 1, 'one pass called the kernel once and it was answered (' . json_encode($w['ran']['dispatches'] ?? null) . ')');
$posts = chat_posts();
ok(count($posts) === 1 && (int) $posts[0]['agent'] === FACILITATOR && (int) $posts[0]['acting'] === 26 && str_contains((string) $posts[0]['utterance'], 'please tidy these'), 'the fake saw ONE post: agent 42, acting 26, the comment\'s words');
ok($posts[0]['conversation_id'] === 'boards:thread:' . $root, 'the conversation is the thread: boards:thread:{root}');
$ctx = (string) $posts[0]['context'];
ok(str_contains($ctx, 'You are ' . $agentName . ', a member of Boards.') && str_contains($ctx, 'SMOKE Priya mentioned you in a comment on the board "SMOKE Dispatch board" (board ' . $b . ')'), 'the context names the agent, the asker, the board and its id');
ok(str_contains($ctx, 'pinned to the item ' . $ids[0] . ' ("Note 1")'), 'it says where the comment is pinned: the item and its words');
ok(str_contains($ctx, $ids[0] . ' (sticky_note): Note 1; ' . $ids[1] . ' (sticky_note): Note 2'), 'it lists the items selected when it was written, in order');
ok(str_contains($ctx, 'The thread so far:') && str_contains($ctx, 'board_overview') && str_contains($ctx, 'Never mention an agent.'), 'and the thread, the manners (read first, answer once, never mention an agent)');
$d = dispatch_of($root);
ok($d['status'] === 'answered' && (int) $d['run_id'] === 5 && $d['request_id'] === 'req-5' && $d['reply_comment_id'] !== null && $d['pending_comment_id'] === null && $d['answered_at'] !== null, 'the dispatch is answered with the run, the request and the reply comment');
$rep = replies_of($root);
ok(count($rep) === 1 && (int) $rep[0]['author_member_id'] === FACILITATOR && $rep[0]['id'] === $d['reply_comment_id'] && $rep[0]['plain_text'] === 'Moved the notes into a grid. @SMOKE Bea take a look.' && (int) $rep[0]['agent_run_id'] === 5,
    'the reply is ONE comment in the thread, by the agent, with the run');
$runs = json_decode($rep[0]['body'], true);
ok(array_map(static fn ($x) => $x['type'], $runs) === ['text', 'mention', 'text'] && $runs[1]['mention'] == ['type' => 'member', 'id' => 28, 'name' => 'SMOKE Bea'], 'its @SMOKE Bea is a mention run');
$asked = q("SELECT * FROM notifications WHERE id > :n AND member_id = 26 AND kind = 'agent_replied'", ['n' => $n0]);
ok(count($asked) === 1 && (string) $asked[0]['record_uuid'] === $rep[0]['id'] && (int) $asked[0]['actor_member_id'] === FACILITATOR && str_contains($asked[0]['title'], $agentName . ' replied on SMOKE Dispatch board'), 'the asker has one agent_replied notice naming the reply');
$men = q("SELECT * FROM notifications WHERE id > :n AND member_id = 28 AND kind = 'mention' AND record_uuid = CAST(:c AS uuid)", ['n' => $n0, 'c' => $rep[0]['id']]);
ok(count($men) === 1 && str_contains($men[0]['title'], $agentName . ' mentioned you'), 'Bea, named in the reply, has one mention notice');
ok((int) one("SELECT count(*) FROM comment_mentions WHERE comment_id = CAST(:c AS uuid) AND kind = 'member' AND principal_id = 28", ['c' => $rep[0]['id']]) === 1, 'and the mention is recorded');
ok((int) one('SELECT count(*) FROM agent_dispatches WHERE id > :d', ['d' => $d0]) === 1, 'the reply dispatched nobody (one dispatch row in all)');
chat_reset();
$w = dispatches_pass_cli();
ok($w['ran']['dispatches']['called'] === 0 && chat_lines() === [] && count(replies_of($root)) === 1, 'a second pass does nothing: no call, no second reply');
$disp = activity('agent.dispatch', $since);
$reply = activity('agent.reply', $since);
$ad = json_decode((string) $disp[0]['after'], true);
$ar = json_decode((string) $reply[0]['after'], true);
ok(count($disp) === 1 && (int) $disp[0]['actor_member_id'] === 26 && (string) $disp[0]['board_id'] === $b && $disp[0]['entity_type'] === 'comment' && $ad['agent_member_id'] === FACILITATOR, 'agent.dispatch: the asker the actor, the board, the comment');
ok(count($reply) === 1 && (int) $reply[0]['actor_member_id'] === FACILITATOR && (int) $reply[0]['agent_run_id'] === 5 && $ar['run_id'] === 5 && $ar['request_id'] === 'req-5' && $ar['reply_length'] === 52 && $ar['currency'] === 'USD',
    'agent.reply: the agent the actor, the run, the request id, the length, the cost');
ok(!str_contains((string) $disp[0]['after'] . (string) $reply[0]['after'], 'tidy') && !str_contains((string) $reply[0]['after'], 'Moved'), 'no word of the comment or the reply is in a log row');

echo "2. A run still going: the placeholder, the poll, one comment\n";
chat_reset();
kernel_chat(['run_id' => 77, 'finished' => false]);
$r = thread_on($jp, $b, '@SMOKE Facilitator take your time', $ids[1]);
$root2 = (string) body_json($r)['record_id'];
$w = dispatches_pass_cli();
$d = dispatch_of($root2);
ok($w['ran']['dispatches']['running'] === 1 && $d['status'] === 'sent' && (int) $d['run_id'] === 77 && $d['pending_comment_id'] !== null, 'the turn is running: run 77 kept and a placeholder made');
$ph = replies_of($root2);
ok(count($ph) === 1 && $ph[0]['id'] === $d['pending_comment_id'] && (int) $ph[0]['author_member_id'] === FACILITATOR && $ph[0]['plain_text'] === $agentName . ' is working…' && (int) $ph[0]['agent_run_id'] === 77, 'the thread shows "' . $agentName . ' is working…" as the agent');
chat_reset();
$w = dispatches_pass_cli(clock(5));
ok($w['ran']['dispatches']['polled'] === 0 && chat_lines() === [], 'a pass inside the 20 seconds asks nothing');
$w = dispatches_pass_cli(clock(30));
$polls = chat_polls();
ok($w['ran']['dispatches']['polled'] === 1 && $w['ran']['dispatches']['running'] === 1 && count($polls) === 1 && (int) $polls[0]['run'] === 77 && (int) $polls[0]['acting'] === 26, 'the next pass polls ?run=77 as the asker; the kernel says not yet');
kernel_chat(null, null, ['77' => finished('All tidied. See the grid.', 77)]);
chat_reset();
$w = dispatches_pass_cli(clock(60));
$d = dispatch_of($root2);
$rep = replies_of($root2);
ok($w['ran']['dispatches']['answered'] === 1 && $d['status'] === 'answered' && count($rep) === 1 && $rep[0]['id'] === $ph[0]['id'] && $rep[0]['plain_text'] === 'All tidied. See the grid.' && $d['reply_comment_id'] === $ph[0]['id'],
    'when the run has finished the placeholder BECOMES the reply: one comment, not two');
ok(chat_posts() === [] && (int) one("SELECT count(*) FROM notifications WHERE kind = 'agent_replied' AND record_uuid = CAST(:c AS uuid)", ['c' => $rep[0]['id']]) === 1, 'it was not sent again, and the asker was told');
// older than ten minutes
kernel_chat(['run_id' => 78, 'finished' => false]);
$r = thread_on($jp, $b, '@SMOKE Facilitator never finishes', $ids[2]);
$root3 = (string) body_json($r)['record_id'];
dispatches_pass_cli();
$d = dispatch_of($root3);
ok((int) $d['run_id'] === 78 && $d['status'] === 'sent', 'a second long run, run 78');
kernel_chat(['status' => 'succeeded', 'run_id' => 79, 'finished' => true, 'reply' => 'Fine, second time.']);
$w = dispatches_pass_cli(clock(700));
$d = dispatch_of($root3);
ok($w['ran']['dispatches']['failed'] === 1 && $d['status'] === 'sent' && $d['run_id'] === null && $d['detail'] === 'The run did not finish.' && (int) $d['attempts'] === 2, 'a run older than ten minutes failed: "The run did not finish.", back to sent with a pause');
$f = activity('agent.fail', $since);
ok(count($f) >= 1 && json_decode((string) end($f)['after'], true)['detail'] === 'The run did not finish.' && (int) end($f)['actor_member_id'] === FACILITATOR, 'agent.fail is logged as the agent');
$w = dispatches_pass_cli(clock(700 + 180));
$d = dispatch_of($root3);
ok($w['ran']['dispatches']['answered'] === 1 && $d['status'] === 'answered' && count(replies_of($root3)) === 1 && comment_plain($d['reply_comment_id']) === 'Fine, second time.', 'the retry after the pause is answered, in the same placeholder');

echo "3. An approval pause\n";
chat_reset();
kernel_chat(['status' => 'pending_approval', 'approval_request_id' => 5, 'run_id' => 91, 'finished' => false]);
$r = thread_on($jp, $b, '@SMOKE Facilitator delete the old notes', $ids[3]);
$root4 = (string) body_json($r)['record_id'];
$w = dispatches_pass_cli();
$d = dispatch_of($root4);
$ph = replies_of($root4);
ok($w['ran']['dispatches']['awaiting'] === 1 && $d['status'] === 'awaiting_approval' && (int) $d['run_id'] === 91 && $d['detail'] === 'Waiting for a person\'s approval (request 5)', 'pending_approval: awaiting approval, the run kept, the request named');
ok(count($ph) === 1 && $ph[0]['plain_text'] === $agentName . ' is waiting for a person\'s approval…', 'the placeholder says so');
chat_reset();
$w = dispatches_pass_cli(clock(30));
ok($w['ran']['dispatches']['polled'] === 0 && chat_lines() === [], 'it is polled once a minute, not sooner');
kernel_chat(null, null, ['91' => ['run_id' => 91, 'status' => 'declined', 'finished' => false, 'error' => ['message' => 'Edward declined it.']]]);
$w = dispatches_pass_cli(clock(90));
$d = dispatch_of($root4);
$ph = replies_of($root4);
ok($w['ran']['dispatches']['refused'] === 1 && $d['status'] === 'refused' && $d['detail'] === 'Edward declined it.' && count($ph) === 1 && $ph[0]['plain_text'] === $agentName . ' could not answer: Edward declined it.' && $d['reply_comment_id'] === $ph[0]['id'],
    'a declined approval is refused in the kernel\'s words, which stay in the thread as the agent\'s reply');
// approved: the run finishes
kernel_chat(['status' => 'pending_approval', 'approval_request_id' => 6, 'run_id' => 92, 'finished' => false]);
$r = thread_on($jp, $b, '@SMOKE Facilitator now with permission', $ids[3]);
$root5 = (string) body_json($r)['record_id'];
dispatches_pass_cli();
kernel_chat(null, null, ['92' => finished('Done, with approval.', 92)]);
$w = dispatches_pass_cli(clock(120));
$d = dispatch_of($root5);
ok($w['ran']['dispatches']['answered'] === 1 && $d['status'] === 'answered' && count(replies_of($root5)) === 1 && comment_plain($d['reply_comment_id']) === 'Done, with approval.', 'once approved and finished the placeholder becomes the reply');
// the kernel forgets the run
kernel_chat(['status' => 'pending_approval', 'approval_request_id' => 7, 'run_id' => 93, 'finished' => false]);
$r = thread_on($jp, $b, '@SMOKE Facilitator a lost run', $ids[3]);
$root6 = (string) body_json($r)['record_id'];
dispatches_pass_cli();
kernel_chat(null, null, [], ['93' => 404]);
$w = dispatches_pass_cli(clock(120));
$d = dispatch_of($root6);
ok($w['ran']['dispatches']['refused'] === 1 && $d['status'] === 'refused' && $d['detail'] === 'No such run.', 'a run the kernel no longer knows (404) is refused with its sentence');

echo "4. Refusals and failures\n";
chat_reset();
kernel_chat(null, 403, [], [], 'This person may not use the Facilitator.');
$r = thread_on($jp, $b, '@SMOKE Facilitator refuse me', $ids[0]);
$root7 = (string) body_json($r)['record_id'];
$w = dispatches_pass_cli();
$d = dispatch_of($root7);
$rep = replies_of($root7);
ok($w['ran']['dispatches']['refused'] === 1 && $d['status'] === 'refused' && $d['detail'] === 'This person may not use the Facilitator.' && count($rep) === 1 && $rep[0]['plain_text'] === $agentName . ' could not answer: This person may not use the Facilitator.',
    '403: refused with the kernel\'s sentence, shown in the thread as the agent\'s reply');
chat_reset();
$w = dispatches_pass_cli(clock(3600));
ok($w['ran']['dispatches']['called'] === 0 && chat_lines() === [], 'a refusal is not retried');
// 409 busy: retried by the backoff
kernel_chat(null, 409);
$r = thread_on($jp, $b, '@SMOKE Facilitator busy now', $ids[0]);
$root8 = (string) body_json($r)['record_id'];
$w = dispatches_pass_cli();
$d = dispatch_of($root8);
ok($w['ran']['dispatches']['failed'] === 1 && $d['status'] === 'sent' && (int) $d['attempts'] === 2 && $d['detail'] === 'The expert is busy with another turn.', '409: not a refusal — failed once, back to sent, to be retried');
kernel_chat(finished('Free now.', 12));
$w = dispatches_pass_cli(clock(180));
ok($w['ran']['dispatches']['answered'] === 1 && dispatch_of($root8)['status'] === 'answered', 'and the backoff retries it: answered');
// 500 five times
chat_reset();
kernel_chat(null, 500);
$n1 = (int) one('SELECT COALESCE(max(id), 0) FROM notifications');
$r = thread_on($jp, $b, '@SMOKE Facilitator fail for good', $ids[0]);
$root9 = (string) body_json($r)['record_id'];
$offsets = [0, 180, 480, 1020, 2040];                   // the pauses are 2, 4, 8 and 16 minutes after each failure
$states = [];
foreach ($offsets as $i => $off) {
    $w = dispatches_pass_cli($off === 0 ? null : clock($off));
    $d = dispatch_of($root9);
    $states[] = [$d['status'], (int) $d['attempts'], $w['ran']['dispatches']['called']];
}
ok($states === [['sent', 2, 1], ['sent', 3, 1], ['sent', 4, 1], ['sent', 5, 1], ['failed', 6, 1]], '500 five times (the clock moved 3, 8, 17 and 34 minutes): sent four times over, then failed for good: ' . json_encode($states));
ok(count(chat_posts()) === 5, 'five calls were made in all');
$w = dispatches_pass_cli(clock(7200));
ok($w['ran']['dispatches']['called'] === 0 && count(chat_posts()) === 5, 'a failed dispatch is not sent again');
$told = q("SELECT * FROM notifications WHERE id > :n AND member_id = 26 AND kind = 'agent_replied' AND title = :t", ['n' => $n1, 't' => $agentName . ' could not answer']);
ok(count($told) === 1 && (string) $told[0]['record_uuid'] === $root9 && $told[0]['body'] === 'The kernel answered 500.', 'the asker was told ONCE, with the detail');
ok(replies_of($root9) === [], 'a call that never started a run left no placeholder');
// a placeholder is removed when it fails for good
kernel_chat(['run_id' => 94, 'finished' => false]);
$r = thread_on($jp, $b, '@SMOKE Facilitator lose the placeholder', $ids[0]);
$root10 = (string) body_json($r)['record_id'];
dispatches_pass_cli();
$d = dispatch_of($root10);
$ph = $d['pending_comment_id'];
pg("UPDATE agent_dispatches SET attempts = 5 WHERE id = " . (int) $d['id']);
kernel_chat(null, null, [], ['94' => 404]);
$w = dispatches_pass_cli(clock(60));
$d = dispatch_of($root10);
ok($d['status'] === 'failed' && $d['pending_comment_id'] === null && one('SELECT deleted_at FROM comments WHERE id = CAST(:c AS uuid)', ['c' => $ph]) !== null && replies_of($root10) === [], 'the fifth failure deletes the placeholder, so the thread is clean');
ok((int) one("SELECT count(*) FROM search_index WHERE entity_uuid = CAST(:c AS uuid)", ['c' => $ph]) === 0, 'and it leaves the search index');

echo "5. Retry from the admin page\n";
chat_reset();
kernel_chat(finished('Back again.', 33));
$fid = (int) $d['id'];
$r = retry_as($jm, $fid);
$j = body_json($r);
ok($r['code'] === 200 && $j['ok'] === true && $j['record_id'] === $fid && $j['did'] === 'Put the dispatch back in the queue' && $j['location'] === '/admin/dispatches?notice=retried', 'Marco (a Boards admin) retries: ok, the dispatch the record, lands on the page');
$d = dispatch_row($fid);
ok($d['status'] === 'sent' && $d['run_id'] === null && (int) $d['attempts'] === 6, 'it is back to sent, with no run and the attempts kept');
$w = dispatches_pass_cli(clock(7300));
ok($w['ran']['dispatches']['called'] === 1 && dispatch_row($fid)['status'] === 'answered', 'the next pass sends it and it is answered');
$rr = activity('dispatch.retry', $since);
ok(count($rr) === 1 && (int) $rr[0]['actor_member_id'] === 27 && (string) $rr[0]['board_id'] === $b && json_decode((string) $rr[0]['after'], true)['dispatch_id'] === $fid, 'dispatch.retry is logged with the actor, the board and the dispatch');
$r = retry_as($jp, $fid);
ok($r['code'] === 403 && str_contains(msg($r), 'agent'), 'a Member\'s retry is 403 (' . msg($r) . ')');
$r = retry_as($jm, $fid);
ok($r['code'] === 422 && msg($r) === 'Only a failed dispatch or one waiting for approval can be retried.', 'an answered dispatch cannot be retried: 422 in words');
$r = retry_as($jm, 999999);
ok($r['code'] === 404, 'a dispatch that is not there: 404');
$r = retry_as($jm, 'x');
ok($r['code'] === 422 && isset(body_json($r)['error']['fields']['dispatch']), 'a dispatch that is not a number: 422 with the field');
$ag = req('POST', '/admin/dispatch-retry.php', ['headers' => as_agent(run_token(FACILITATOR, 9501)), 'form' => ['dispatch' => $fid]]);
ok($ag['code'] === 403 && msg($ag) === 'This is for people; an agent uses the tools.', 'an agent\'s retry is refused: this is for people');
$pt = req('POST', '/admin/dispatch-retry.php', ['headers' => as_agent(person_token(27)), 'form' => ['dispatch' => 999999]]);
ok($pt['code'] === 404, 'a person\'s action token (the command bar) reaches the handler');
// a waiting one is polled at once
kernel_chat(['status' => 'pending_approval', 'approval_request_id' => 8, 'run_id' => 95, 'finished' => false]);
$r = thread_on($jp, $b, '@SMOKE Facilitator wait for it', $ids[0]);
$root11 = (string) body_json($r)['record_id'];
dispatches_pass_cli();
$d = dispatch_of($root11);
$r = retry_as($jm, (int) $d['id']);
ok($r['code'] === 200 && body_json($r)['did'] === 'Asked the kernel about the dispatch again' && dispatch_row((int) $d['id'])['status'] === 'awaiting_approval', 'Retry on one waiting for approval polls it at once and keeps it waiting');
kernel_chat(null, null, ['95' => finished('Approved and done.', 95)]);
$w = dispatches_pass_cli(clock(7400));
ok($w['ran']['dispatches']['answered'] === 1 && dispatch_row((int) $d['id'])['status'] === 'answered', 'which the next pass finishes');
finish();
