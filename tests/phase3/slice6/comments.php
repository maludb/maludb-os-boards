<?php
/**
 * Slice 6 — threads (docs/build-specs/comments-notifications.md "Proof" 1, 4): a Member comments on an item and at a point; a root with neither is refused in words; a reply to
 * a reply hangs on the root; resolve and reopen by a commenter (and a Viewer at comment level); edit by the author only; delete by the author and by an owner, a Viewer's delete of
 * another's refused; the pins; the thread partial; the comments page and its filters; the log vocabulary (no text in it). Run through tests/phase3/slice6/run.sh.
 */
require __DIR__ . '/lib.php';
$seed = json_decode((string) file_get_contents(need('BD_S6_SEED')), true);
$b = $seed['main'];
[$jp] = sign_on(26);   // Priya, the owner
[$jb] = sign_on(28);   // Bea, edit
[$jd] = sign_on(30);   // Dana, a Viewer shared at comment
[$jl] = sign_on(31);   // Lee, a Viewer who cannot open the board
[$jm] = sign_on(27);   // Marco, a Boards admin (full everywhere)
[$ja] = sign_on(29);   // Ann, an external Viewer who cannot open it

echo "1. A Member comments on an item and at a point\n";
$since = last_activity_id();
$r = thread_on($jb, $b, 'This slipped by a week.', $seed['sticky'], ['x' => 4, 'y' => -3]);
$j = body_json($r);
ok($r['code'] === 200 && ($j['ok'] ?? false) && ($j['did'] ?? '') === 'Commented' && preg_match('/^[0-9a-f-]{36}$/', (string) ($j['record_id'] ?? '')) === 1, 'Bea comments on the sticky: 200, did "Commented", record_id the comment');
$t1 = (string) $j['record_id'];
$row = comment_row($t1);
ok($row['item_id'] === $seed['sticky'] && (float) $row['anchor_x'] === 4.0 && (float) $row['anchor_y'] === -3.0 && (int) $row['author_member_id'] === 28 && $row['parent_comment_id'] === null, 'it is pinned to the item with the offset from its top-right corner, authored by Bea');
ok(json_decode($row['body'], true)[0]['plain_text'] === 'This slipped by a week.' && $row['plain_text'] === 'This slipped by a week.', 'the text is stored as a run (and its plain text is generated)');
$r = thread_on($jb, $b, 'A note at a point.');
$t2 = (string) body_json($r)['record_id'];
$row2 = comment_row($t2);
ok($r['code'] === 200 && $row2['item_id'] === null && (float) $row2['anchor_x'] === 100.0 && (float) $row2['anchor_y'] === 40.0, 'a comment at a point keeps the point');
$r = comment_as($jb, 'create.php', ['board' => $b, 'text' => 'Nowhere.']);
ok($r['code'] === 422 && str_contains(msg($r), 'Pin the comment to an item or to a point'), 'a root with neither an item nor a point is refused in words: "' . msg($r) . '"');
$r = comment_as($jb, 'create.php', ['board' => $b, 'text' => 'Half.', 'x' => 5]);
ok($r['code'] === 422 && str_contains(msg($r), 'both x and y'), 'half a point is refused: "' . msg($r) . '"');
$r = comment_as($jb, 'create.php', ['board' => $b, 'text' => '   ', 'x' => 1, 'y' => 1]);
ok($r['code'] === 422 && str_contains(msg($r), 'required') || str_contains(msg($r), 'Say something'), 'an empty comment is refused in words: "' . msg($r) . '"');
$r = comment_as($jb, 'create.php', ['board' => $b, 'text' => str_repeat('x', 4001), 'x' => 1, 'y' => 1]);
ok($r['code'] === 422 && str_contains(msg($r), '4000'), 'over 4000 characters is refused');
$r = comment_as($jb, 'create.php', ['board' => $b, 'text' => 'Wrong item.', 'item' => uuid4()]);
ok($r['code'] === 422 && str_contains(msg($r), 'not an item of this board'), 'an item that is not on the board is refused: "' . msg($r) . '"');
$other = $seed['wall'];
$r = comment_as($jb, 'create.php', ['board' => $other, 'text' => 'No access.', 'x' => 1, 'y' => 1]);
ok($r['code'] === 404 && msg($r) === 'There is no such board.', 'a board Bea cannot open does not exist to her (404)');
$r = comment_as($jl, 'create.php', ['board' => $b, 'text' => 'Lee cannot.', 'x' => 1, 'y' => 1]);
ok($r['code'] === 404, 'Lee, a stranger to the board: 404');
$r = comment_as($ja, 'create.php', ['board' => $b, 'text' => 'Ann cannot.', 'x' => 1, 'y' => 1]);
ok($r['code'] === 404, 'Ann, an external with no share: 404');
$r = thread_on($jd, $b, 'A Viewer at comment level may.', $seed['shape']);
ok($r['code'] === 200, 'Dana, a Viewer shared at comment, comments (a Viewer is capped at comment, not below)');
$t3 = (string) body_json($r)['record_id'];

echo "   replies hang on the root\n";
$r = comment_as($jp, 'reply.php', ['comment' => $t1, 'text' => 'Agreed — I will chase it.']);
$j = body_json($r);
$rp1 = (string) ($j['record_id'] ?? '');
ok($r['code'] === 200 && ($j['did'] ?? '') === 'Replied' && comment_row($rp1)['parent_comment_id'] === $t1, 'Priya replies to the thread: the reply hangs on the root');
$r = comment_as($jb, 'reply.php', ['comment' => $rp1, 'text' => 'Thanks.']);
$rp2 = (string) (body_json($r)['record_id'] ?? '');
ok($r['code'] === 200 && comment_row($rp2)['parent_comment_id'] === $t1 && (string) (body_json($r)['thread_id'] ?? '') === $t1, 'a reply to a reply hangs on the root, not on the reply');
$r = comment_as($jb, 'reply.php', ['comment' => uuid4(), 'text' => 'Into the void.']);
ok($r['code'] === 404 && msg($r) === 'There is no such thread.', 'a reply to a comment that is not there: 404 "There is no such thread."');
$r = comment_as($jl, 'reply.php', ['comment' => $t1, 'text' => 'Lee, no.']);
ok($r['code'] === 404, 'Lee replying to a thread on a board he cannot open: 404');
$t = find_thread_rows($t1);
ok(count($t) === 3, 'the thread is three comments: the root and two replies');

echo "4. Resolve, reopen, edit, delete\n";
$r = comment_as($jd, 'resolve.php', ['comment' => $t1]);
ok($r['code'] === 200 && comment_row($t1)['resolved_at'] !== null && (int) comment_row($t1)['resolved_by'] === 30, 'Dana (comment level) resolves the thread');
$r = comment_as($jb, 'resolve.php', ['comment' => $rp1]);
ok($r['code'] === 404 && str_contains(msg($r), 'no such thread'), 'resolving a reply (not a thread): 404 in words');
$r = comment_as($jb, 'reopen.php', ['comment' => $t1]);
ok($r['code'] === 200 && comment_row($t1)['resolved_at'] === null, 'Bea reopens it');
$r = comment_as($jd, 'edit.php', ['comment' => $t1, 'text' => 'Dana rewrites Bea.']);
ok($r['code'] === 403 && msg($r) === 'Only its author edits a comment.', 'another person\'s edit is the database\'s sentence (403): "' . msg($r) . '"');
$r = comment_as($jp, 'edit.php', ['comment' => $t1, 'text' => 'The owner rewrites Bea.']);
ok($r['code'] === 403, 'not even an owner edits another\'s comment');
$r = comment_as($jb, 'edit.php', ['comment' => $t1, 'text' => 'This slipped by two weeks.']);
ok($r['code'] === 200 && comment_row($t1)['edited_at'] !== null && comment_row($t1)['plain_text'] === 'This slipped by two weeks.', 'the author edits: the text and edited_at change');
$r = comment_as($jd, 'delete.php', ['comment' => $t1]);
ok($r['code'] === 403 && str_contains(msg($r), 'Only its author or the board'), 'a Viewer\'s delete of another\'s is refused: "' . msg($r) . '"');
$r = comment_as($jd, 'delete.php', ['comment' => $t3]);
ok($r['code'] === 200 && comment_row($t3)['deleted_at'] !== null, 'the author deletes her own');
$r = comment_as($jp, 'delete.php', ['comment' => $rp2]);
ok($r['code'] === 200 && comment_row($rp2)['deleted_at'] !== null && comment_row($rp1)['deleted_at'] === null, 'an owner deletes another\'s reply (only that reply)');
$r = comment_as($jp, 'delete.php', ['comment' => $t2]);
ok($r['code'] === 200 && comment_row($t2)['deleted_at'] !== null, 'an owner deletes a thread');
$r = comment_as($jb, 'edit.php', ['comment' => $t3, 'text' => 'Back from the dead.']);
ok($r['code'] === 404, 'a deleted comment is not there to edit');
$r = comment_as($jm, 'delete.php', ['comment' => $rp1]);
ok($r['code'] === 200, 'a Boards admin (full on every board) removes a comment too');
$rt = find_thread_rows($t1);
ok(count($rt) === 1, 'after the deletes the thread is its root alone');
$reg = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true)['actions'] ?? [];
ok(($reg['comment_delete']['approval'] ?? null) === 'deletion' && empty($reg['comment_create']['approval']) && empty($reg['comment_reply']['approval']) && empty($reg['comment_edit']['approval']) && empty($reg['comment_resolve']['approval']),
    'an agent\'s comment_delete pauses (deletion); the other comment actions do not — the registry the kernel reads');
$r = req('POST', '/comments/delete.php', ['form' => ['comment' => $t1]]);
ok(in_array($r['code'], [302, 401, 403], true), 'an anonymous delete is refused (' . $r['code'] . ')');

echo "   the pins, the thread and the page\n";
$r = comment_as($jb, 'create.php', ['board' => $b, 'text' => 'On the shape.', 'item' => $seed['shape'], 'x' => -10, 'y' => 5]);
$t4 = (string) body_json($r)['record_id'];
$r = comment_as($jb, 'create.php', ['board' => $b, 'text' => 'Floating.', 'x' => 321.5, 'y' => -77]);
$t5 = (string) body_json($r)['record_id'];
comment_as($jp, 'resolve.php', ['comment' => $t5]);
$pins = body_json(page($jp, "/boards/$b/comments?format=pins", ['headers' => ['Accept: application/json']]))['data']['pins'] ?? [];
$byId = array_column($pins, null, 'id');
ok(isset($byId[$t1], $byId[$t4]) && !isset($byId[$t5]) && !isset($byId[$t2]), 'the pins: open threads only (the resolved one and the deleted one are not there)');
$box = q('SELECT (bbox[0])[0] AS x2, (bbox[1])[1] AS y1 FROM board_items WHERE id = CAST(:i AS uuid)', ['i' => $seed['shape']])[0];
ok(abs($byId[$t4]['x'] - ((float) $box['x2'] - 10)) < 0.02 && abs($byId[$t4]['y'] - ((float) $box['y1'] + 5)) < 0.02 && $byId[$t4]['item_id'] === $seed['shape'], 'an item\'s pin sits at the item\'s top-right corner plus the stored offset');
ok($byId[$t1]['author_name'] === 'SMOKE Bea' && $byId[$t1]['author_kind'] === 'human' && $byId[$t1]['replies'] === 0 && $byId[$t1]['resolved'] === false, 'a pin names its author, kind, replies and whether it is resolved');
$all = body_json(page($jp, "/boards/$b/comments?format=pins&resolved=1", ['headers' => ['Accept: application/json']]))['data']['pins'] ?? [];
$byAll = array_column($all, null, 'id');
ok(isset($byAll[$t5]) && $byAll[$t5]['resolved'] === true && abs($byAll[$t5]['x'] - 321.5) < 0.01 && abs($byAll[$t5]['y'] + 77) < 0.01, 'resolved=1 adds the resolved ones; a point\'s pin is the point');
ok(page($jl, "/boards/$b/comments?format=pins", ['headers' => ['Accept: application/json']])['code'] === 404, 'the pins are behind the wall (Lee: 404)');
$tj = body_json(page($jb, "/comments/thread.php?id=$t1", ['headers' => ['Accept: application/json']]));
ok(($tj['data']['root']['comment_id'] ?? '') === $t1 && ($tj['data']['root']['where'] ?? '') === "on 'Shipped late'" && count($tj['data']['replies'] ?? [1]) === 0, 'the thread (JSON): the root, where it is pinned in words, no replies left');
$th = page($jb, "/comments/thread.php?id=$t1");
ok($th['code'] === 200 && str_contains($th['body'], 'id="comment-thread-' . $t1 . '"') && str_contains($th['body'], 'id="comment-resolve-' . $t1 . '"') && str_contains($th['body'], 'id="comment-reply-' . $t1 . '"')
    && str_contains($th['body'], 'id="comment-edit-' . $t1 . '"') && !str_contains($th['body'], '<html'), 'the thread partial: wrapper, Resolve, the reply field, Edit (own); no shell');
$th = page($jd, "/comments/thread.php?id=$t1");
ok(!str_contains($th['body'], 'id="comment-edit-' . $t1 . '"') && !str_contains($th['body'], 'id="comment-delete-' . $t1 . '"'), 'a Viewer sees no Edit or Delete on another\'s comment');
$th = page($jp, "/comments/thread.php?id=$t1");
ok(str_contains($th['body'], 'id="comment-delete-' . $t1 . '"') && !str_contains($th['body'], 'id="comment-edit-' . $t1 . '"'), 'an owner sees Delete but not Edit on another\'s comment');
ok(page($jl, "/comments/thread.php?id=$t1")['code'] === 404 && page($jb, '/comments/thread.php?id=' . uuid4())['code'] === 404, 'a thread is behind the wall; one that is not there is 404');
$ed = page($jb, "/comments/thread.php?id=$t1&edit=$t1");
ok(str_contains($ed['body'], 'id="comment-edit-form-' . $t1 . '"') && str_contains($ed['body'], 'This slipped by two weeks.'), 'edit=… shows my comment as a form, with its text');
$pg = page($jb, "/boards/$b/comments");
ok($pg['code'] === 200 && str_contains($pg['body'], 'id="comment-card-' . $t1 . '"') && str_contains($pg['body'], 'id="comment-card-' . $t5 . '"') && str_contains($pg['body'], 'data-screen="board-comments"')
    && !str_contains($pg['body'], 'id="comment-card-' . $t2 . '"'), 'the comments page: a card per thread (open and resolved), not the deleted one');
ok(strpos($pg['body'], 'id="comment-card-' . $t1 . '"') < strpos($pg['body'], 'id="comment-card-' . $t5 . '"'), 'open threads come before resolved ones');
$decoded = html_entity_decode($pg['body'], ENT_QUOTES);
ok(str_contains($decoded, "on 'Shipped late'") && str_contains($decoded, 'at a point') && str_contains($decoded, 'Resolved'), 'a card says where it is pinned ("on \'Shipped late\'", "at a point") and that it is resolved');
$pg = page($jb, "/boards/$b/comments?open=0");
ok(str_contains($pg['body'], 'id="comment-card-' . $t5 . '"') && !str_contains($pg['body'], 'id="comment-card-' . $t1 . '"'), 'open=0 shows resolved threads only');
$pg = page($jb, "/boards/$b/comments?open=1");
ok(str_contains($pg['body'], 'id="comment-card-' . $t1 . '"') && !str_contains($pg['body'], 'id="comment-card-' . $t5 . '"'), 'open=1 shows open threads only');
$pg = page($jd, "/boards/$b/comments?mine=1");
ok(!str_contains($pg['body'], 'id="comment-card-' . $t1 . '"') && !str_contains($pg['body'], 'id="comment-card-' . $t4 . '"'), 'mine=1 for Dana: threads she wrote in or is mentioned in — none now');
$pg = page($jb, "/boards/$b/comments?mine=1");
ok(str_contains($pg['body'], 'id="comment-card-' . $t1 . '"'), 'mine=1 for Bea: her threads');
$pg = page($jl, "/boards/$b/comments");
ok($pg['code'] === 404 && str_contains($pg['body'], 'There is no such board.'), 'the page is behind the wall');
$pg = page($jb, "/boards/$b/comments", ['headers' => ['HX-Request: true']]);
ok(!str_contains($pg['body'], '<html') && str_contains($pg['body'], 'id="board-comments-filters"') && preg_match('/^X-Screen: board-comments\r?$/mi', $pg['headers']) === 1, 'over HTMX the page is the partial, stamped with its screen');
$rows = body_json(page($jb, "/boards/$b/comments", ['headers' => ['Accept: application/json']]))['data'] ?? [];
ok(($rows['total'] ?? 0) >= 3 && isset($rows['rows'][0]['where'], $rows['rows'][0]['author']['name'], $rows['rows'][0]['url']) && !isset($rows['rows'][0]['body_html']), 'the page (JSON): the threads as a whitelist, with where, author and url');

echo "   the trail\n";
$ev = static fn (string $a): array => activity($a, $since);
$c = $ev('comment.create');
ok(count($c) >= 4 && (string) $c[0]['board_id'] === $b, 'comment.create is logged with the board');
$blob = json_encode(array_map(static fn ($r) => [$r['before'], $r['after']], q('SELECT before, after FROM activity_log WHERE id > :s AND action LIKE \'comment.%\'', ['s' => $since])));
ok(!str_contains($blob, 'slipped') && !str_contains($blob, 'Agreed') && !str_contains($blob, 'rewrites'), 'a comment\'s text is in no log payload');
$a0 = json_decode((string) $c[0]['after'], true);
ok(($a0['mentions'] ?? null) == ['member' => 0, 'agent' => 0, 'department' => 0] && $a0['item_id'] === $seed['sticky'] && $a0['not_shared'] === 0, 'its after: the comment, the item, mentions by kind, dispatched, needs_share, not_shared (a count)');
$ed = $ev('comment.edit');
ok(count($ed) === 1 && (int) (json_decode((string) $ed[0]['after'], true)['length'] ?? 0) === mb_strlen('This slipped by two weeks.'), 'comment.edit logs the length only');
ok(count($ev('comment.reply')) === 2 && count($ev('comment.resolve')) >= 2 && count($ev('comment.reopen')) === 1 && count($ev('comment.delete')) >= 4, 'comment.reply / resolve / reopen / delete are logged');
$sv = activity('screen.view', $since);
ok(count(array_filter($sv, static fn ($r) => $r['screen'] === 'board-comments' && (string) $r['board_id'] === $b)) >= 5, 'screen.view on board-comments carries the board');
$seed['t'] = ['t1' => $t1, 't4' => $t4, 't5' => $t5];
file_put_contents(need('BD_S6_SEED') . '.threads', json_encode($seed['t']));

/** The comment ids of a thread, not counting deleted ones (the proof's own view of the table). */
function find_thread_rows(string $root): array { return q('SELECT id FROM comments WHERE (id = CAST(:r AS uuid) OR parent_comment_id = CAST(:r AS uuid)) AND deleted_at IS NULL', ['r' => $root]); }
finish();
