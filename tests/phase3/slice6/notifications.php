<?php
/**
 * Slice 6 — the bell, my notices and my preferences (comments-notifications.md "Proof" 5, and the prefs screen): the bell's count and its partial; the notices unread first,
 * 30 a page; a notice opens its board at the comment and is read; one I cannot follow lands on the list; Mark all read; another person's notice is not mine; the settings page
 * and prefs_save (partial, validated, logged by field name). Run through tests/phase3/slice6/run.sh.
 */
require __DIR__ . '/lib.php';
$seed = json_decode((string) file_get_contents(need('BD_S6_SEED')), true);
$b = $seed['main'];
[$jd] = sign_on(30);   // Dana: notices from the earlier proofs
[$jl] = sign_on(31);   // Lee
[$jb] = sign_on(28);
$since = last_activity_id();

echo "5. The bell and the notices\n";
$unread = (int) one('SELECT count(*) FROM notifications WHERE member_id = 30 AND read_at IS NULL');
ok($unread >= 2, "Dana has $unread unread notices from the mentions");
$bell = page($jd, '/notifications/bell');
ok($bell['code'] === 200 && preg_match('/id="bell-count">' . $unread . '</', $bell['body']) === 1 && str_contains($bell['body'], 'id="bell"') && !str_contains($bell['body'], '<html'), 'the bell partial carries the count and no shell');
ok(preg_match('/id="header-bell" hx-get="\/notifications\/bell" hx-trigger="every 60s[^"]*"/', page($jd, '/')['body']) === 1 && preg_match('/id="bell-count">' . $unread . '</', page($jd, '/')['body']) === 1, 'the header\'s #header-bell polls it every 60 s and starts with the count');
ok(body_json(page($jd, '/notifications/bell', ['headers' => ['Accept: application/json']]))['data']['unread'] === $unread, 'the bell (JSON): the count');
ok(!str_contains(page($jl, '/notifications/bell')['body'], 'bell-count'), 'a person with nothing unread has no badge');
ok(req('GET', '/notifications/bell')['code'] === 302, 'the bell needs a sign-in');
$pg = page($jd, '/notifications');
ok($pg['code'] === 200 && str_contains($pg['body'], 'data-screen="notifications"') && str_contains($pg['body'], 'id="notifications-read-all"') && str_contains($pg['body'], 'id="notifications-list"'), 'the page: the list and Mark all read');
$rows = body_json(page($jd, '/notifications', ['headers' => ['Accept: application/json']]))['data'];
ok($rows['unread'] === $unread && $rows['total'] >= $unread && $rows['rows'][0]['read'] === false, 'JSON: the unread count and the notices, unread first');
$first = $rows['rows'][0];
ok(preg_match('~^/boards/' . $b . '\?comment=[0-9a-f-]{36}$~', (string) $first['url']) === 1 && $first['board_title'] === 'SMOKE Comments' && $first['kind'] === 'mention', 'a mention notice names its board and links to it at the comment');
ok(str_contains($pg['body'], 'id="notification-row-' . $first['notification_id'] . '"') && str_contains($pg['body'], 'SMOKE Comments'), 'a notice shows its title and the board\'s title');

// the order: an old unread comes before a newer read one
pg("UPDATE notifications SET read_at = now() WHERE id = " . (int) $first['notification_id']);
$rows = body_json(page($jd, '/notifications', ['headers' => ['Accept: application/json']]))['data']['rows'];
$kinds = array_column($rows, 'read');
$firstRead = array_search(true, $kinds, true);
ok($firstRead === false || !in_array(false, array_slice($kinds, $firstRead), true), 'every unread notice comes before every read one');
pg("UPDATE notifications SET read_at = NULL WHERE id = " . (int) $first['notification_id']);

// opening one
$open = post_as($jd, '/notifications/read.php', ['notification' => $first['notification_id']]);
$oj = body_json($open);
ok($open['code'] === 200 && ($oj['location'] ?? '') === $first['url'] && (int) ($oj['record_id'] ?? 0) === $first['notification_id'], 'opening a notice answers the board at the comment as its location');
ok(one('SELECT read_at IS NOT NULL FROM notifications WHERE id = :i', ['i' => $first['notification_id']]) === true && (int) one('SELECT count(*) FROM notifications WHERE member_id = 30 AND read_at IS NULL') === $unread - 1, 'and marks it read');
$o2 = req('POST', '/notifications/read.php', ['jar' => $jd, 'headers' => ['HX-Request: true', 'X-CSRF-Token: ' . page_csrf($jd)], 'form' => ['notification' => $first['notification_id']]]);
ok($o2['code'] === 200 && preg_match('/^HX-Location: .*comment=/mi', $o2['headers']) === 1, 'over HTMX a notice follows to the board (HX-Location)');
$o3 = req('POST', '/notifications/read.php', ['jar' => $jd, 'headers' => ['X-CSRF-Token: ' . page_csrf($jd)], 'form' => ['notification' => $first['notification_id']]]);
ok($o3['code'] === 302 && str_contains($o3['location'], '/boards/' . $b . '?comment='), 'a browser is redirected to the board');
$other = (int) one('SELECT id FROM notifications WHERE member_id = 28 LIMIT 1');
$o4 = post_as($jd, '/notifications/read.php', ['notification' => $other]);
ok($o4['code'] === 404 && (int) one('SELECT count(*) FROM notifications WHERE id = :i AND read_at IS NULL', ['i' => $other]) === 1, 'another person\'s notice is not mine (404) and stays unread');
ok(post_as($jd, '/notifications/read.php', ['notification' => 99999999])['code'] === 404, 'a notice that is not there: 404');

// a notice whose board I can no longer open
pg("INSERT INTO notifications (member_id, kind, title, body, board_id, record_type, record_uuid) VALUES (31, 'mention', 'SMOKE a gone board', 'x', '$b', 'comment', gen_random_uuid())");
$gone = (int) one("SELECT max(id) FROM notifications WHERE member_id = 31");
$og = post_as($jl, '/notifications/read.php', ['notification' => $gone]);
ok($og['code'] === 200 && str_starts_with((string) body_json($og)['location'], '/notifications?notice=gone') && one('SELECT read_at IS NOT NULL FROM notifications WHERE id = :i', ['i' => $gone]) === true,
    'a notice for a board I cannot open is read and lands on the list with a sentence, not a 404');
$ls = page($jl, '/notifications?notice=gone');
ok(str_contains($ls['body'], 'That notice names a board you can no longer open.') && str_contains($ls['body'], 'a board you can no longer open'), 'the list says so');
// a notice that names no board (a plain one)
pg("INSERT INTO notifications (member_id, kind, title) VALUES (31, 'share', 'SMOKE plain notice')");
$plain = (int) one("SELECT max(id) FROM notifications WHERE member_id = 31");
$op = post_as($jl, '/notifications/read.php', ['notification' => $plain]);
ok(str_starts_with((string) body_json($op)['location'], '/notifications'), 'a notice with no board lands on the list');

echo "   paging, the filter, Mark all read\n";
pg("INSERT INTO notifications (member_id, kind, title, created_at) SELECT 31, 'comment', 'SMOKE bulk ' || g, now() - (g || ' minutes')::interval FROM generate_series(1, 34) g");
$p1 = body_json(page($jl, '/notifications', ['headers' => ['Accept: application/json']]))['data'];
$p2 = body_json(page($jl, '/notifications?page=2', ['headers' => ['Accept: application/json']]))['data'];
ok(count($p1['rows']) === 30 && $p1['total'] === 36 && $p1['unread'] === 34 && count($p2['rows']) === 6 && $p2['page'] === 2, 'thirty a page: 30 then 6 of 36 (34 unread)');
ok(str_contains(page($jl, '/notifications')['body'], 'id="notifications-next"') && str_contains(page($jl, '/notifications?page=2')['body'], 'id="notifications-prev"'), 'the page links Older and Newer');
$un = body_json(page($jl, '/notifications?unread=1', ['headers' => ['Accept: application/json']]))['data'];
ok($un['total'] === 34 && !in_array(true, array_column($un['rows'], 'read'), true), 'unread=1 shows only the unread');
$ra = post_as($jl, '/notifications/read-all.php', ['all' => 'yes']);
ok($ra['code'] === 200 && (int) (body_json($ra)['count'] ?? -1) === 34 && (int) one('SELECT count(*) FROM notifications WHERE member_id = 31 AND read_at IS NULL') === 0, 'Mark all read: the count is 34 and nothing is unread');
ok((int) body_json(post_as($jl, '/notifications/read-all.php', ['all' => 'yes']))['count'] === 0, 'again: 0');
ok((int) one('SELECT count(*) FROM notifications WHERE member_id = 28 AND read_at IS NULL') > 0, 'Mark all read is mine alone: Bea\'s stay unread');
ok(str_contains(page($jl, '/notifications')['body'], 'id="notifications-read-all"') === false, 'with nothing unread there is no Mark all read');
$ra = post_as($jl, '/notifications/read-all.php', ['all' => 'no']);
ok($ra['code'] === 422, 'all=no is refused in words');

echo "   my settings\n";
$pf = page($jb, '/settings/');
ok($pf['code'] === 200 && str_contains($pf['body'], 'data-screen="settings"') && str_contains($pf['body'], 'id="settings-form-field-email_enabled"') && str_contains($pf['body'], 'id="settings-form-field-digest"')
    && str_contains($pf['body'], 'id="settings-form-field-text_enabled"') && str_contains($pf['body'], 'id="settings-form-field-kinds-mention"') && str_contains($pf['body'], 'id="settings-form-field-text_kinds-agent_replied"')
    && str_contains($pf['body'], 'id="settings-tokens-link"') && str_contains($pf['body'], 'verified phone'), 'the settings page: email, the digest, texts (with the K6 sentence), the kinds by email and by text, a link to My tokens');
ok(preg_match('/id="settings-form-field-email_enabled"[^>]* checked/', $pf['body']) === 1 && preg_match('/id="settings-form-field-text_enabled"[^>]* checked/', $pf['body']) === 0 && preg_match('/id="settings-form-field-kinds-mention"[^>]* checked/', $pf['body']) === 1
    && preg_match('/id="settings-form-field-text_kinds-mention"[^>]* checked/', $pf['body']) === 1 && preg_match('/id="settings-form-field-text_kinds-reply"[^>]* checked/', $pf['body']) === 0, 'with nothing saved the defaults show: email on, texts off, every kind by email, mentions by text');
$pj = body_json(page($jb, '/settings/', ['headers' => ['Accept: application/json']]))['data'];
ok($pj['prefs'] === ['email_enabled' => true, 'text_enabled' => false, 'digest' => false, 'kinds' => ['mention', 'reply', 'comment', 'share', 'agent_replied'], 'text_kinds' => ['mention']] && isset($pj['kinds']['mention']), 'JSON: the defaults and the kinds');
pg('DELETE FROM notification_prefs WHERE member_id = 28');   // the scratch directory sync gave every member the defaults; a person who never saved has no row
ok((int) one('SELECT count(*) FROM notification_prefs WHERE member_id = 28') === 0, 'a person who saved nothing has no row');
$r = post_as($jb, '/settings/prefs.php', ['digest' => 'yes']);
ok($r['code'] === 200 && one('SELECT digest FROM notification_prefs WHERE member_id = 28') === true && one('SELECT email_enabled FROM notification_prefs WHERE member_id = 28') === true, 'prefs_save with one field: it is an upsert; what was not sent stays as it was');
$r = post_as($jb, '/settings/prefs.php', ['text_enabled' => 'yes', 'text_kinds' => 'mention,reply']);
$pr = q('SELECT * FROM notification_prefs WHERE member_id = 28')[0];
ok($r['code'] === 200 && $pr['text_enabled'] === true && $pr['text_kinds'] === '{mention,reply}' && $pr['digest'] === true, 'texts on, kinds by text mention and reply (a comma list, as an agent sends it)');
$r = post_as($jb, '/settings/prefs.php', ['kinds' => 'mention,bogus']);
ok($r['code'] === 422 && str_contains(msg($r), 'The kinds are'), 'a kind that is not one is refused in words');
$r = post_as($jb, '/settings/prefs.php', ['email_enabled' => 'maybe']);
ok($r['code'] === 422, 'email_enabled=maybe is refused');
$r = post_as($jb, '/settings/prefs.php', ['kinds' => '']);
ok($r['code'] === 200 && one('SELECT kinds FROM notification_prefs WHERE member_id = 28') === '{}', 'an emptied list means no kinds by email');
// the form: unchecked boxes arrive as markers only
$r = req('POST', '/settings/prefs.php', ['jar' => $jb, 'headers' => ['Accept: application/json', 'X-CSRF-Token: ' . page_csrf($jb)],
    'form' => ['email_enabled__sent' => '1', 'digest__sent' => '1', 'kinds__sent' => '1', 'text_enabled__sent' => '1', 'text_kinds__sent' => '1', 'kinds' => ['mention', 'share'], 'email_enabled' => '1']]);
$pr = q('SELECT * FROM notification_prefs WHERE member_id = 28')[0];
ok($r['code'] === 200 && $pr['email_enabled'] === true && $pr['digest'] === false && $pr['text_enabled'] === false && $pr['kinds'] === '{mention,share}' && $pr['text_kinds'] === '{}',
    'the form\'s unchecked boxes read as no (digest off, texts off, no kinds by text), its checked ones as they are');
$pf = page($jb, '/settings/');
ok(preg_match('/id="settings-form-field-kinds-share"[^>]* checked/', $pf['body']) === 1 && preg_match('/id="settings-form-field-kinds-reply"[^>]* checked/', $pf['body']) === 0 && preg_match('/id="settings-form-field-digest"[^>]* checked/', $pf['body']) === 0, 'the page shows what was saved');
$s0 = activity('settings.prefs', $since);
ok(count($s0) >= 4 && json_decode((string) $s0[0]['after'], true)['changed'] === ['digest'] && str_contains(json_encode(array_column($s0, 'after')), 'text_kinds'), 'settings.prefs logs the names of the fields changed');
$nochange = post_as($jb, '/settings/prefs.php', ['email_enabled' => 'yes']);
ok(body_json($nochange)['did'] === 'Nothing to change', 'saving what is already so changes nothing');

echo "   the trail\n";
ok(count(activity('notification.read', $since)) >= 3 && count(activity('notification.read_all', $since)) === 2, 'notification.read and notification.read_all are logged');
$ra = activity('notification.read_all', $since);
ok((int) json_decode((string) $ra[0]['after'], true)['count'] === 34, 'read_all logs the count');
$sv = activity('screen.view', $since);
ok(count(array_filter($sv, static fn ($r) => $r['screen'] === 'notifications')) >= 3 && count(array_filter($sv, static fn ($r) => $r['screen'] === 'settings')) >= 2, 'screen.view on notifications and settings');
ok(count(array_filter($sv, static fn ($r) => str_contains((string) $r['route'], 'bell'))) === 0, 'the bell\'s poll is not logged');
finish();
