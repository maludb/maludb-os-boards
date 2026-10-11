<?php
declare(strict_types=1);

/**
 * The outbox sender and the morning digest (slice 6, comments-notifications.md "The outbox pass"; a port of Spaces' app/features/worker/outbox.php and
 * digest.php, `sp_` -> `bd_`, the rules verbatim). The rows bd_notify() (db/011) queues — one per recipient AND channel — are delivered here. Email goes
 * through MaluMail (the kit's malumail_send(); Boards holds no mailbox), texts through the kernel's K6 door (kernel_call to /api/v1/notify/sms.php —
 * Boards holds no Twilio key). THE RULES:
 *   - email is AT-MOST-ONCE: the row is marked `sent` BEFORE the send is attempted and `failed` (never queued again) after a failure — a person is never
 *     mailed twice; a provider's refusal of the address is `skipped` with the code; no MALUMAIL_API_KEY at all -> every queued email `skipped` "no mail key";
 *   - a text the kernel could not take (a 5xx, no answer) is retried with a backoff (send_after + 2^attempts minutes), five attempts then `failed`; each K6
 *     refusal (no_sender, not_held, no_verified_phone, opted_out, rate_limited, invalid) is `skipped` with the code and the email row stands.
 * Never a body, a subject or an address in the trail: the channel, the kind and a code.
 */
const OUTBOX_MAX_ATTEMPTS = 5;
const OUTBOX_DIGEST_ITEMS = 30;
const OUTBOX_DIGEST_HOUR = 7;
const K6_REFUSALS = ['no_sender', 'not_held', 'no_verified_phone', 'opted_out', 'rate_limited', 'invalid'];

/** Where a queued row leads, as an absolute URL: the notice it was queued with (bd_notify() names it in the dedupe key `n:<id>:<channel>`), else the bell. */
function outbox_link(PDO $pdo, array $o): string
{
    if (preg_match('/^n:(\d+):/', (string) ($o['dedupe_key'] ?? ''), $m)) {
        $st = $pdo->prepare('SELECT board_id, record_type, record_uuid::text AS record_uuid FROM notifications WHERE id = :id');
        $st->execute(['id' => (int) $m[1]]);
        $n = $st->fetch();
        if ($n !== false && ($path = notification_url($n)) !== null) {
            return app_url($path);
        }
    }
    return app_url('/notifications');
}

function outbox_business(PDO $pdo): string
{
    return trim((string) one_value($pdo, 'SELECT business_name FROM bd_settings WHERE id = 1')) ?: app_name();
}

/** The email as MaluMail takes it: the text part with the link and the sign-off, the HTML from the views (the row's own body_html when it has one). */
function outbox_mail(PDO $pdo, array $o): array
{
    $business = outbox_business($pdo);
    $link = outbox_link($pdo, $o);
    $subject = (string) ($o['subject'] ?? '') !== '' ? (string) $o['subject'] : 'A notice from ' . $business;
    $text = trim((string) $o['body']) . "\n\nOpen the board: " . $link . "\n\n-- " . $business . ($business !== app_name() ? ' (' . app_name() . ')' : '') . "\n";
    $html = (string) ($o['body_html'] ?? '') !== '' ? (string) $o['body_html']
        : view('emails/notice.php', ['title' => $subject, 'body' => (string) $o['body'], 'link' => $link, 'business' => $business]);
    return ['subject' => $subject, 'text' => $text, 'html' => $html];
}

function outbox_log(PDO $pdo, string $action, array $o, ?string $code = null): void
{
    log_activity($pdo, $action, 'notification_outbox', (int) $o['id'], ['actor_member_id' => null, 'source' => 'cron',
        'after' => ['channel' => $o['channel'], 'kind' => $o['kind'], 'member_id' => (int) $o['member_id']] + ($code === null ? [] : ['code' => $code])]);
}

function outbox_finish(PDO $pdo, array $o, string $status, ?string $detail, ?string $ref = null): void
{
    $pdo->prepare('UPDATE notification_outbox SET status = :s, detail = :d, provider_ref = COALESCE(:r, provider_ref), sent_at = CASE WHEN :s2 = \'sent\' THEN COALESCE(sent_at, now()) ELSE sent_at END WHERE id = :id')
        ->execute(['s' => $status, 's2' => $status, 'd' => $detail === null ? null : mb_substr($detail, 0, 200), 'r' => $ref, 'id' => (int) $o['id']]);
}

/** A text that was not taken: five tries with 2^attempts minutes between, then failed. Returns 'retried' or 'failed'. */
function outbox_retry(PDO $pdo, array $o, string $why): string
{
    $attempts = (int) $o['attempts'] + 1;
    if ($attempts >= OUTBOX_MAX_ATTEMPTS) {
        $pdo->prepare("UPDATE notification_outbox SET status = 'failed', attempts = :a, detail = :d WHERE id = :id")->execute(['a' => $attempts, 'd' => $why, 'id' => (int) $o['id']]);
        outbox_log($pdo, 'notification.fail', $o, 'attempts');
        return 'failed';
    }
    $pdo->prepare("UPDATE notification_outbox SET attempts = :a, detail = :d, send_after = now() + make_interval(mins => :m) WHERE id = :id AND status = 'queued'")
        ->execute(['a' => $attempts, 'd' => $why, 'm' => 2 ** $attempts, 'id' => (int) $o['id']]);
    return 'retried';
}

/** One email. At-most-once: claimed as sent first. Returns 'sent', 'skipped' or 'failed'. */
function outbox_send_email(PDO $pdo, array $o): string
{
    if ((string) env('MALUMAIL_API_KEY', '') === '') {                                       // mail is optional
        outbox_finish($pdo, $o, 'skipped', 'no mail key');
        outbox_log($pdo, 'notification.skip', $o, 'no_mail_key');
        return 'skipped';
    }
    $to = trim((string) ($o['to_email'] ?? '')) !== '' ? (string) $o['to_email'] : (string) ($o['member_email'] ?? '');
    if ($to === '') {
        outbox_finish($pdo, $o, 'skipped', 'no_address');
        outbox_log($pdo, 'notification.skip', $o, 'no_address');
        return 'skipped';
    }
    $claim = $pdo->prepare("UPDATE notification_outbox SET status = 'sent', attempts = attempts + 1, sent_at = now(), detail = 'sending' WHERE id = :id AND status = 'queued'");
    $claim->execute(['id' => (int) $o['id']]);
    if ($claim->rowCount() !== 1) {
        return 'skipped';                                      // another pass took it
    }
    try {
        $mail = outbox_mail($pdo, $o);
        $r = malumail_send(['to' => $to, 'subject' => $mail['subject'], 'text' => $mail['text'], 'html' => $mail['html'],
                            'from' => (string) env('MAIL_FROM', ''), 'from_name' => (string) env('MAIL_FROM_NAME', app_name())]);
    } catch (Throwable $e) {
        error_log('outbox ' . $o['id'] . ': ' . $e->getMessage());
        outbox_finish($pdo, $o, 'failed', 'The mail could not be sent.');
        outbox_log($pdo, 'notification.fail', $o, 'transport');
        return 'failed';
    }
    $status = (int) $r['status'];
    $rejected = $r['body']['rejected'][0]['reason'] ?? null;
    if ($status === 400 || ($status === 200 && empty($r['body']['accepted']) && $rejected !== null)) {
        $code = is_string($rejected) && str_starts_with($rejected, 'suppressed') ? 'suppressed' : 'rejected';
        outbox_finish($pdo, $o, 'skipped', $code);
        outbox_log($pdo, 'notification.skip', $o, $code);
        return 'skipped';
    }
    if ($status >= 200 && $status < 300) {
        outbox_finish($pdo, $o, 'sent', null, $r['message_id']);
        outbox_log($pdo, 'notification.send', $o);
        return 'sent';
    }
    outbox_finish($pdo, $o, 'failed', 'MaluMail answered ' . $status . '.');
    outbox_log($pdo, 'notification.fail', $o, 'http_' . $status);
    return 'failed';
}

/** One text through K6. Returns 'sent', 'skipped', 'retried' or 'failed'. */
function outbox_send_text(PDO $pdo, array $o): string
{
    $text = mb_substr((string) $o['body'], 0, 480);
    $ref = (string) $o['kind'] . ':' . ($o['record_id'] ?? $o['id']);
    $r = kernel_call('POST', '/api/v1/notify/sms.php', ['member_id' => (int) $o['member_id'], 'text' => $text, 'reference' => $ref], [], 10);
    if ($r === null) {
        return outbox_retry($pdo, $o, 'The kernel did not answer.');
    }
    $status = (int) $r['status'];
    if ($status >= 200 && $status < 300) {
        $pdo->prepare('UPDATE notification_outbox SET attempts = attempts + 1 WHERE id = :id')->execute(['id' => (int) $o['id']]);
        outbox_finish($pdo, $o, 'sent', null, isset($r['body']['notification']['id']) ? (string) $r['body']['notification']['id'] : null);
        outbox_log($pdo, 'notification.send', $o);
        return 'sent';
    }
    $code = (string) ($r['body']['error']['code'] ?? '');
    if (in_array($code, K6_REFUSALS, true) || ($status >= 400 && $status < 500 && $status !== 429 && $status !== 408)) {
        $code = $code !== '' ? $code : 'refused';
        outbox_finish($pdo, $o, 'skipped', $code);
        outbox_log($pdo, 'notification.skip', $o, $code);
        return 'skipped';
    }
    return outbox_retry($pdo, $o, 'The kernel answered ' . $status . '.');
}

/** Deliver what is due. Returns ['sent', 'skipped', 'failed', 'retried']. */
function outbox_send_batch(PDO $pdo, int $limit): array
{
    $out = ['sent' => 0, 'skipped' => 0, 'failed' => 0, 'retried' => 0];
    $st = $pdo->prepare("SELECT o.id, o.channel, o.member_id, o.to_email, o.kind, o.record_type, o.record_id, o.dedupe_key, o.subject, o.body, o.body_html, o.attempts,
                                o.created_at, m.email AS member_email, m.status AS member_status
                           FROM notification_outbox o JOIN members m ON m.id = o.member_id
                          WHERE o.status = 'queued' AND o.send_after <= now() ORDER BY o.send_after, o.id LIMIT :l");
    $st->bindValue('l', max(1, $limit), PDO::PARAM_INT);
    $st->execute();
    foreach ($st->fetchAll() as $o) {
        try {
            if ($o['member_status'] !== 'active') {
                outbox_finish($pdo, $o, 'skipped', 'inactive');
                outbox_log($pdo, 'notification.skip', $o, 'inactive');
                $r = 'skipped';
            } else {
                $r = $o['channel'] === 'text' ? outbox_send_text($pdo, $o) : outbox_send_email($pdo, $o);
            }
        } catch (Throwable $e) {
            error_log('outbox row ' . $o['id'] . ': ' . $e->getMessage());
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $r = 'failed';
        }
        $out[$r] = ($out[$r] ?? 0) + 1;
    }
    return $out;
}

/** A time zone by name, the business's when the name is bad. */
function outbox_zone(?string $name, string $fallback): DateTimeZone
{
    foreach ([(string) $name, $fallback, 'UTC'] as $n) {
        try {
            if ($n !== '') {
                return new DateTimeZone($n);
            }
        } catch (Exception) {
        }
    }
    return new DateTimeZone('UTC');
}

/**
 * The morning digest: for every member who chose one email a day instead of one per event (notification_prefs.digest) whose local time has passed 07:00
 * today and who has no outbox row `digest:<member>:<their date>`: one email of their unread notices of the last 24 hours (at most 30, then "and N more"),
 * none when there are none. Returns how many digests were queued.
 */
function digest_queue(PDO $pdo, DateTimeImmutable $now): int
{
    $zoneOfBusiness = (string) one_value($pdo, 'SELECT timezone FROM bd_settings WHERE id = 1');
    $business = outbox_business($pdo);
    $members = $pdo->query("SELECT m.id, m.timezone, m.email FROM members m JOIN notification_prefs p ON p.member_id = m.id
                             WHERE p.digest AND p.email_enabled AND m.status = 'active' AND m.capability IS NOT NULL AND m.member_kind = 'human' AND m.email IS NOT NULL ORDER BY m.id")->fetchAll();
    $made = 0;
    foreach ($members as $m) {
        $local = $now->setTimezone(outbox_zone($m['timezone'], $zoneOfBusiness));
        if ((int) $local->format('G') < OUTBOX_DIGEST_HOUR) {
            continue;
        }
        $date = $local->format('Y-m-d');
        $key = 'digest:' . $m['id'] . ':' . $date;
        if ((bool) one_value($pdo, 'SELECT EXISTS (SELECT 1 FROM notification_outbox WHERE dedupe_key = :k)', ['k' => $key])) {
            continue;
        }
        $st = $pdo->prepare("SELECT n.title, n.kind, n.created_at, n.board_id, n.record_type, n.record_uuid::text AS record_uuid, a.display_name AS who
                               FROM notifications n LEFT JOIN members a ON a.id = n.actor_member_id
                              WHERE n.member_id = :m AND n.read_at IS NULL AND n.kind <> 'digest' AND n.created_at > CAST(:now AS timestamptz) - interval '24 hours'
                              ORDER BY n.created_at, n.id");
        $st->execute(['m' => (int) $m['id'], 'now' => $now->format(DATE_ATOM)]);
        $rows = $st->fetchAll();
        if ($rows === []) {
            continue;
        }
        $tz = $local->getTimezone();
        $items = [];
        foreach (array_slice($rows, 0, OUTBOX_DIGEST_ITEMS) as $r) {
            $items[] = ['title' => (string) $r['title'], 'who' => (string) ($r['who'] ?? ''), 'link' => app_url(notification_url($r) ?? '/notifications'),
                        'when' => (new DateTimeImmutable((string) $r['created_at']))->setTimezone($tz)->format('M j, g:i A')];
        }
        $more = count($rows) - count($items);
        $title = 'Your Boards digest: ' . count($rows) . ' unread ' . (count($rows) === 1 ? 'notice' : 'notices');
        $text = $title . "\n\n";
        foreach ($items as $i) {
            $text .= '- ' . $i['title'] . ($i['who'] !== '' ? ' (' . $i['who'] . ')' : '') . ' - ' . $i['when'] . "\n  " . $i['link'] . "\n";
        }
        if ($more > 0) {
            $text .= "\nand " . $more . " more in your bell.\n";
        }
        $html = view('emails/digest.php', ['title' => $title, 'items' => $items, 'more' => $more, 'link' => app_url('/notifications'), 'business' => $business]);
        $ins = $pdo->prepare("INSERT INTO notification_outbox (channel, member_id, to_email, kind, dedupe_key, subject, body, body_html)
                              VALUES ('email', :m, :e, 'digest', :k, :s, :b, :h) ON CONFLICT DO NOTHING");
        $ins->execute(['m' => (int) $m['id'], 'e' => $m['email'], 'k' => $key, 's' => $title, 'b' => $text, 'h' => $html]);
        if ($ins->rowCount() === 1) {
            log_activity($pdo, 'notification.digest', 'notification_outbox', null, ['actor_member_id' => null, 'source' => 'cron', 'after' => ['member_id' => (int) $m['id'], 'count' => count($rows), 'date' => $date]]);
            $made++;
        }
    }
    return $made;
}

/** The worker's pass: queue the digests that are due, then deliver what is due. ['sent', 'failed', 'skipped', 'retried', 'digests']. */
function outbox_pass(PDO $pdo, DateTimeImmutable $now, int $limit): array
{
    $digests = digest_queue($pdo, $now);
    $r = outbox_send_batch($pdo, $limit);
    $out = ['sent' => $r['sent'], 'failed' => $r['failed'], 'skipped' => $r['skipped'], 'retried' => $r['retried'], 'digests' => $digests];
    if (array_sum($out) > 0) {
        log_activity($pdo, 'outbox.send', null, null, ['source' => 'cron', 'actor_member_id' => null, 'after' => $out]);
    }
    return $out;
}
