<?php
/**
 * Slice 6 — the worker's outbox pass (comments-notifications.md "Proof" 6, 7): queued email goes through the fake MaluMail at most once, with the board link; a failure is
 * `failed` and never resent; an address the provider refuses is `skipped`; no MALUMAIL_API_KEY at all skips every email with "no mail key"; a digest holds the per-notice
 * email back and is sent once at 07:00 in the member's own time zone (a second pass sends none); a text goes to the fake K6 for an opted-in kind, a refusal is `skipped` with its
 * code, a 5xx is retried with a backoff and fails after five. Run through tests/phase3/slice6/run.sh.
 */
require __DIR__ . '/lib.php';
$seed = json_decode((string) file_get_contents(need('BD_S6_SEED')), true);
$b = $seed['main'];
$wall = $seed['wall'];
$mailLog = need('FAKE_MALUMAIL_LOG');
$smsLog = need('FAKE_KERNEL_STATE') . '.sms';
[$jp] = sign_on(26);
[$jb] = sign_on(28);
$since = last_activity_id();
$sms = static function (array $s): void { kernel_state(static function (array $st) use ($s): array { $st['sms'] = $s; return $st; }); };

echo "6. Email: at most once, with the board's link\n";
$queued = (int) one("SELECT count(*) FROM notification_outbox WHERE channel = 'email' AND status = 'queued'");
ok($queued >= 5, "the earlier proofs left $queued emails queued");
$m0 = count(log_lines($mailLog));
$rep = worker_outbox();
ok($rep['rc'] === 0 && $rep['errors'] === [] && is_array($rep['ran']) && $rep['ran']['sent'] === $queued && $rep['ran']['failed'] === 0 && $rep['ran']['skipped'] === 0, 'one pass sends them all: ' . json_encode($rep['ran']));
$mail = array_slice(log_lines($mailLog), $m0);
ok(count($mail) === $queued, 'the fake MaluMail received exactly that many');
$bea = array_values(array_filter($mail, static fn ($x) => $x['to'] === 'bea@example.invalid' && str_contains($x['subject'], 'mentioned you')));
ok($bea !== [] && $bea[0]['from'] === 'boards@example.invalid' && $bea[0]['from_name'] === 'Boards' && str_contains($bea[0]['subject'], 'SMOKE Priya on SMOKE Comments mentioned you'), 'a mention email: from MAIL_FROM / MAIL_FROM_NAME, the notice\'s title as its subject');
$link = null;
foreach ($bea as $x) { if (preg_match('~http://127\.0\.0\.1:9101/boards/' . $b . '\?comment=[0-9a-f-]{36}~', $x['text'], $m)) { $link = $m[0]; } }
ok($link !== null && str_contains($bea[0]['html'], 'Open the board') && str_contains($bea[0]['html'], 'href="http://127.0.0.1:9101/boards/' . $b . '?comment='), 'its text and its HTML carry "Open the board" {APP_URL}/boards/{board}?comment={record_uuid}');
ok(str_starts_with($bea[0]['text'], 'Please look at this') && !str_contains($bea[0]['text'], '<') && str_contains($bea[0]['text'], "\n\n-- Boards"), 'the text part is plain: the notice\'s body, the link, the sign-off');
$row = q("SELECT * FROM notification_outbox WHERE channel = 'email' AND status = 'sent' ORDER BY id DESC LIMIT 1")[0];
ok($row['sent_at'] !== null && (int) $row['attempts'] === 1 && $row['detail'] === null, 'a sent row keeps when it was sent and one attempt (MaluMail\'s message id too, when it answers one)');
$rep = worker_outbox();
ok($rep['ran']['sent'] === 0 && count(log_lines($mailLog)) === $m0 + $queued, 'a second pass sends nothing more');

echo "   a failure is failed and never resent; a refused address is skipped\n";
pg("UPDATE members SET email = 'flaky@example.invalid' WHERE id = 31; UPDATE members SET email = 'suppressed@client.invalid' WHERE id = 29");
share_as(26, $wall, 'member', 31, 'comment');
share_as(26, $wall, 'member', 29, 'comment');
$r = thread_on($jp, $wall, 'For you both: @SMOKE Lee and @SMOKE Ann', null);
ok($r['code'] === 200 && (body_json($r)['not_shared'] ?? null) === [], 'Priya mentions Lee and Ann on a board both can now open: both are told');
$fl = q("SELECT * FROM notification_outbox WHERE member_id = 31 AND channel = 'email'")[0];
ok($fl['status'] === 'queued', 'Lee\'s email is queued');
$m1 = count(log_lines($mailLog));
$rep = worker_outbox();
ok($rep['ran']['failed'] === 1 && $rep['ran']['skipped'] === 1 && $rep['ran']['sent'] === 0, 'the pass: one failed (the relay refused), one skipped (the address is suppressed): ' . json_encode($rep['ran']));
$fl = q("SELECT * FROM notification_outbox WHERE member_id = 31 AND channel = 'email'")[0];
$su = q("SELECT * FROM notification_outbox WHERE member_id = 29 AND channel = 'email'")[0];
ok($fl['status'] === 'failed' && $fl['detail'] === 'MaluMail answered 502.' && (int) $fl['attempts'] === 1, 'a failed row says what MaluMail answered');
ok($su['status'] === 'skipped' && $su['detail'] === 'suppressed', 'a refused address is skipped with the code: suppressed');
ok(count(log_lines($mailLog)) === $m1 + 2, 'each was tried once');
worker_outbox();
ok(count(log_lines($mailLog)) === $m1 + 2, 'a failed email is never queued again (no second attempt)');
ok(str_contains(json_encode(q('SELECT after FROM activity_log WHERE id > :s AND action LIKE \'notification.%\'', ['s' => $since])), 'flaky') === false, 'no address is in the trail');
$failRows = activity('notification.fail', $since);
ok(count($failRows) === 1 && json_decode((string) $failRows[0]['after'], true)['code'] === 'http_502' && $failRows[0]["source"] === 'cron', 'notification.fail is logged by the worker (source cron) with a code and no address');
pg("UPDATE members SET email = 'lee@example.invalid' WHERE id = 31; UPDATE members SET email = 'ann@client.invalid' WHERE id = 29");
share_as(26, $wall, 'member', 31, 'none');
share_as(26, $wall, 'member', 29, 'none');

echo "7. No MaluMail key: mail is optional\n";
thread_on($jp, $b, 'No key, @SMOKE Bea', $seed['sticky']);
$m2 = count(log_lines($mailLog));
$rep = worker_outbox(null, 'MALUMAIL_API_KEY= ');
ok($rep['ran']['skipped'] >= 1 && $rep['ran']['sent'] === 0 && count(log_lines($mailLog)) === $m2, 'with no key every queued email is skipped and the fake saw nothing');
$sk = q("SELECT * FROM notification_outbox WHERE member_id = 28 AND channel = 'email' ORDER BY id DESC LIMIT 1")[0];
ok($sk['status'] === 'skipped' && $sk['detail'] === 'no mail key', 'the row: skipped, "no mail key"');

echo "6. The morning digest\n";
// Dana takes the digest: the per-notice email is held back
pg("DELETE FROM notification_prefs WHERE member_id = 30; DELETE FROM notification_outbox WHERE member_id = 30 AND status = 'queued'");
pg("INSERT INTO notification_prefs (member_id, email_enabled, digest) VALUES (30, true, true)");
pg("UPDATE notifications SET read_at = now() WHERE member_id = 30 AND created_at < now() - interval '1 second'");
$o1 = (int) one('SELECT COALESCE(max(id), 0) FROM notification_outbox');
thread_on($jp, $b, 'Digest one for @SMOKE Dana', $seed['sticky']);
thread_on($jp, $b, 'Digest two for @SMOKE Dana', $seed['shape']);
ok((int) one("SELECT count(*) FROM notification_outbox WHERE member_id = 30 AND id > :o", ['o' => $o1]) === 0 && (int) one('SELECT count(*) FROM notifications WHERE member_id = 30 AND read_at IS NULL') === 2, 'the digest holds the per-notice email back (two notices, no outbox row)');
$today = gmdate('Y-m-d');
$m3 = count(log_lines($mailLog));
$rep = worker_outbox($today . 'T06:55:00+00:00');
ok($rep['ran']['digests'] === 0 && count(log_lines($mailLog)) === $m3, 'at 06:55 local: no digest yet');
$rep = worker_outbox($today . 'T07:05:00+00:00');
ok($rep['ran']['digests'] === 1 && $rep['ran']['sent'] === 1, 'at 07:05 local: one digest, sent: ' . json_encode($rep['ran']));
$dm = array_slice(log_lines($mailLog), $m3);
ok(count($dm) === 1 && $dm[0]['to'] === 'dana@example.invalid' && $dm[0]['subject'] === 'Your Boards digest: 2 unread notices' && substr_count($dm[0]['text'], '/boards/' . $b . '?comment=') === 2 && str_contains($dm[0]['html'], 'Digest one') === false,
    'ONE email to Dana: "Your Boards digest: 2 unread notices", the two notices\' titles and their links (not the comments\' text)');
ok(str_contains($dm[0]['text'], 'SMOKE Priya') && str_contains($dm[0]['html'], 'href="http://127.0.0.1:9101/boards/' . $b . '?comment='), 'with who wrote them, in the HTML as links');
$drow = q("SELECT * FROM notification_outbox WHERE member_id = 30 AND kind = 'digest'")[0];
ok($drow['dedupe_key'] === 'digest:30:' . $today && $drow['status'] === 'sent', 'its dedupe key is digest:{member}:{their date}');
$rep = worker_outbox($today . 'T07:06:00+00:00');
ok($rep['ran']['digests'] === 0 && count(log_lines($mailLog)) === $m3 + 1, 'a second pass the same day sends none (dedupe)');
echo "   at 07:00 in the member's own time zone\n";
pg("DELETE FROM notification_outbox WHERE member_id = 30 AND kind = 'digest'; UPDATE members SET timezone = 'America/New_York' WHERE id = 30");
$ny = new DateTimeZone('America/New_York');
$at = static fn (string $hm): string => (new DateTimeImmutable('today ' . $hm, $ny))->format(DATE_ATOM);
$rep = worker_outbox($at('06:30'));
ok($rep['ran']['digests'] === 0, 'Dana in New York: 06:30 there is too early');
$rep = worker_outbox($at('07:05'));
ok($rep['ran']['digests'] === 1, 'at 07:05 in New York the digest is queued');
$dr = q("SELECT dedupe_key FROM notification_outbox WHERE member_id = 30 AND kind = 'digest'")[0]['dedupe_key'];
ok($dr === 'digest:30:' . (new DateTimeImmutable('today', $ny))->format('Y-m-d'), 'keyed by HER date: ' . $dr);
pg("UPDATE members SET timezone = 'UTC' WHERE id = 30");
// a digest with nothing unread sends nothing
pg("UPDATE notifications SET read_at = now() WHERE member_id = 30; DELETE FROM notification_outbox WHERE member_id = 30 AND kind = 'digest'");
$rep = worker_outbox($today . 'T08:00:00+00:00');
ok($rep['ran']['digests'] === 0, 'nothing unread: no digest');
pg("UPDATE notification_prefs SET digest = false WHERE member_id = 30");

echo "6. Texts through the kernel's K6\n";
pg("UPDATE notification_prefs SET text_enabled = true, text_kinds = '{mention}' WHERE member_id = 30");
$sms(['mode' => 'ok']);
@unlink($smsLog);
$o2 = (int) one('SELECT COALESCE(max(id), 0) FROM notification_outbox');
$r = thread_on($jp, $b, 'Text for @SMOKE Dana', $seed['sticky']);
$tx = q("SELECT * FROM notification_outbox WHERE id > :o AND member_id = 30 AND channel = 'text'", ['o' => $o2]);
ok(count($tx) === 1 && $tx[0]['status'] === 'queued' && $tx[0]['kind'] === 'mention', 'a text is queued for an opted-in kind (mention) — and Dana\'s email too (the digest is off now)');
$rep = worker_outbox();
$lines = log_lines($smsLog);
ok($rep['ran']['sent'] >= 1 && count($lines) === 1 && $lines[0]['member_id'] === 30 && mb_strlen($lines[0]['text']) <= 480 && preg_match('/^mention:\d+$/', $lines[0]['reference']) === 1, 'the pass posts it to the fake K6: member_id, a text of at most 480 characters, reference "mention:{id}"');
ok(q("SELECT status FROM notification_outbox WHERE id = :i", ['i' => $tx[0]['id']])[0]['status'] === 'sent', 'and the row is sent');
// a reply is not an opted-in text kind
$o3 = (int) one('SELECT COALESCE(max(id), 0) FROM notification_outbox');
$sd = $seed['sticky'];
$rr = thread_on($jb, $b, 'By Bea.', $sd);
comment_as($jp, 'reply.php', ['comment' => body_json($rr)['record_id'], 'text' => 'Thanks Bea']);
ok((int) one("SELECT count(*) FROM notification_outbox WHERE id > :o AND channel = 'text'", ['o' => $o3]) === 0, 'a kind she did not opt into by text (a reply, a comment on a board she owns) queues no text');
// K6 refuses: no verified phone
$sms(['mode' => 'ok', 'members' => ['30' => 'no_verified_phone']]);
thread_on($jp, $b, 'Second text for @SMOKE Dana', $seed['shape']);
$rep = worker_outbox();
$sk = q("SELECT * FROM notification_outbox WHERE member_id = 30 AND channel = 'text' ORDER BY id DESC LIMIT 1")[0];
ok($sk['status'] === 'skipped' && $sk['detail'] === 'no_verified_phone', 'a K6 refusal (no_verified_phone) is skipped with the code');
ok(q("SELECT status FROM notification_outbox WHERE member_id = 30 AND channel = 'email' ORDER BY id DESC LIMIT 1")[0]['status'] === 'sent', 'and the email row stands');
foreach (['opted_out', 'rate_limited', 'not_held'] as $mode) {
    $sms(['mode' => 'ok', 'members' => ['30' => $mode]]);
    thread_on($jp, $b, "Text again ($mode) for @SMOKE Dana", $seed['shape']);
    worker_outbox();
    $sk = q("SELECT * FROM notification_outbox WHERE member_id = 30 AND channel = 'text' ORDER BY id DESC LIMIT 1")[0];
    ok($sk['status'] === 'skipped' && $sk['detail'] === $mode, "K6 says $mode: skipped with that code");
}
// K6 down: retried with a backoff, five attempts then failed
$sms(['mode' => 'server_error']);
thread_on($jp, $b, 'Text while K6 is down for @SMOKE Dana', $seed['shape']);
$rep = worker_outbox();
$rt = q("SELECT *, send_after > now() AS later FROM notification_outbox WHERE member_id = 30 AND channel = 'text' ORDER BY id DESC LIMIT 1")[0];
ok($rep['ran']['retried'] === 1 && $rt['status'] === 'queued' && (int) $rt['attempts'] === 1 && $rt['later'] === true && $rt['detail'] === 'The kernel answered 500.', 'a 5xx is retried: still queued, one attempt, send_after pushed out');
$sec = (int) one("SELECT extract(epoch FROM send_after - now()) FROM notification_outbox WHERE id = :i", ['i' => $rt['id']]);
ok($sec > 60 && $sec <= 125, "…by 2^1 minutes (about 120 s; $sec)");
$rep = worker_outbox();
ok($rep['ran']['retried'] === 0, 'not tried again before its time');
for ($i = 2; $i <= 5; $i++) {
    pg("UPDATE notification_outbox SET send_after = now() WHERE id = " . (int) $rt['id']);
    $rep = worker_outbox();
}
$rt = q("SELECT * FROM notification_outbox WHERE id = :i", ['i' => $rt['id']])[0];
ok($rt['status'] === 'failed' && (int) $rt['attempts'] === 5, 'after five attempts it is failed');
ok(count(activity('notification.fail', $since)) >= 2, 'and logged');
$sms(['mode' => 'ok']);
echo "   the pass in the worker's report\n";
$all = exec('cd ' . escapeshellarg(dirname(__DIR__, 3)) . ' && php bin/worker.php 2>&1 | tail -1');
$aj = json_decode($all, true);
ok(isset($aj['ran']['outbox']['sent'], $aj['ran']['outbox']['digests']) && !isset($aj['ran']['outbox']['stub']) && ($aj['errors'] ?? null) === [], 'the full worker pass runs the real outbox (no stub) and reports its counts');
ok(count(activity('outbox.send', $since)) >= 1 && activity('outbox.send', $since)[0]['source'] === 'cron', 'outbox.send is logged by the worker with the counts');
finish();
