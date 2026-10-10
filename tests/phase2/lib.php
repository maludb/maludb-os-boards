<?php
/**
 * Helpers for the Phase 2 proofs (docs/build-specs/sso-shell.md "Proof"). Run them through tests/phase2/run.sh, which builds the scratch
 * database bd_dev_p2, starts the application (:9101 — php -S, or a user-level Apache with BD_APP=apache), a fake kernel (:9102), the fake
 * MaluDB + MaluMail (:9103), and puts the scratch environment in the process environment. Every proof prints "  ok   …" / "  FAIL …" and
 * ends "all N passed" or "N FAILED".
 * The fixture (bin/dev_directory.json): 1 the owner (super-admin, Boards admin, IT); 26 Priya a Member (Sales); 27 Marco a Boards admin and a
 * Member (Finance); 28 Bea (no roles sent — a Member by capability, Accounting); 29 Ann (external, a Viewer); 30 Dana a Viewer (Sales);
 * 31 Lee (read, no roles sent — a Viewer by capability, Sales); 40 the Expert (an agent, a Member); 41 the Watcher (an agent, not admitted);
 * 42 the Facilitator (an agent, a Member).
 */
chdir(dirname(__DIR__, 2));
$GLOBALS['fails'] = 0;
$GLOBALS['passes'] = 0;
const BASE = 'http://127.0.0.1:9101';
const APP = 'boards';
const LAUNCHER = 'https://app.example.invalid/launcher?app=boards';
function need(string $k): string { $v = getenv($k); if ($v === false || $v === '') { fwrite(STDERR, "$k is not set — run tests/phase2/run.sh\n"); exit(2); } return $v; }
function ok(bool $c, string $l): void { $GLOBALS[$c ? 'passes' : 'fails']++; echo ($c ? '  ok   ' : '  FAIL ') . $l . "\n"; }
function finish(): never { echo $GLOBALS['fails'] ? "{$GLOBALS['fails']} FAILED ({$GLOBALS['passes']} passed)\n" : "all {$GLOBALS['passes']} passed\n"; exit($GLOBALS['fails'] ? 1 : 0); }
function e_(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
/** The application's own role on the scratch database (the walls judge it as the member set by as_member()). */
function pdo(): PDO {
    static $p = null;
    return $p ??= new PDO(sprintf('pgsql:host=%s;port=%s;dbname=%s', need('DB_HOST'), need('DB_PORT'), need('DB_NAME')), need('DB_USER'), need('DB_PASSWORD'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
}
function q(string $sql, array $args = []): array { $st = pdo()->prepare($sql); $st->execute($args); return $st->fetchAll(); }
function one(string $sql, array $args = []) { $st = pdo()->prepare($sql); $st->execute($args); return $st->fetchColumn(); }
/** SQL as postgres on the scratch database (fixtures; the owner's standing exception covers bd_* scratch databases). Returns the output's lines. */
function pg(string $sql): array
{
    $p = proc_open(['sudo', '-n', '-u', 'postgres', 'psql', '-X', '-q', '-At', '-v', 'ON_ERROR_STOP=1', '-d', need('DB_NAME')], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fwrite($pipes[0], $sql);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    if (proc_close($p) !== 0) { fwrite(STDERR, "psql failed: $err\n"); }
    return array_values(array_filter(explode("\n", (string) $out), static fn ($l) => $l !== ''));
}
function fixture(): array { return json_decode(file_get_contents(dirname(__DIR__, 2) . '/bin/dev_directory.json'), true); }

/** One request. $o: jar, headers[], form[], json[], raw. Returns code, headers (string), body, and the Location. */
function req(string $method, string $path, array $o = []): array
{
    $ch = curl_init((str_starts_with($path, 'http') ? '' : BASE) . $path);
    $h = $o['headers'] ?? [];
    $opt = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 60,
            CURLOPT_COOKIEJAR => $o['jar'] ?? '/dev/null', CURLOPT_COOKIEFILE => $o['jar'] ?? '/dev/null'];
    if (isset($o['form'])) { $opt[CURLOPT_POSTFIELDS] = http_build_query($o['form']); }
    if (isset($o['json'])) { $opt[CURLOPT_POSTFIELDS] = json_encode($o['json']); $h[] = 'Content-Type: application/json'; }
    if (isset($o['raw'])) { $opt[CURLOPT_POSTFIELDS] = $o['raw']; }
    $opt[CURLOPT_HTTPHEADER] = $h;
    curl_setopt_array($ch, $opt);
    $r = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headers = substr($r, 0, $hs);
    return ['code' => $code, 'headers' => $headers, 'body' => substr($r, $hs), 'location' => preg_match('/^Location: (.+)$/mi', $headers, $m) ? trim($m[1]) : ''];
}
function jar(): string { return tempnam(sys_get_temp_dir(), 'bdjar'); }
function csrf_of(string $html): string { preg_match('/name="csrf-token" id="csrf-token-meta" content="([^"]+)"/', $html, $m); return $m[1] ?? ''; }
function body_json(array $r): array { return json_decode($r['body'], true) ?: []; }

/** A single-use hand-off URL path (/sso?token=&claims=), signed as the kernel signs one. $o: key, ttl, app, claims, next. */
function handoff(int $member, array $o = []): string
{
    $key = $o['key'] ?? need('ACTION_TOKEN_KEY');
    $payload = $member . '.' . (time() + ($o['ttl'] ?? 60)) . '.' . ($o['app'] ?? APP) . '.' . bin2hex(random_bytes(16));
    $token = $payload . '.' . hash_hmac('sha256', 'sso:' . $payload, $key);
    $claims = ($o['claims'] ?? (fixture()['claims'][(string) $member] ?? [])) + ['member_id' => $member];
    $text = rtrim(strtr(base64_encode(json_encode($claims)), '+/', '-_'), '=');
    return '/sso?' . http_build_query(['token' => $token, 'claims' => $text . '.' . hash_hmac('sha256', $text, $key)] + (isset($o['next']) ? ['next' => $o['next']] : []));
}
/** Sign a member on with a fresh jar; returns [jar, response]. */
function sign_on(int $member, array $o = []): array { $j = jar(); return [$j, req('GET', handoff($member, $o), ['jar' => $j])]; }
function page(string $jar, string $path, array $o = []): array { return req('GET', $path, ['jar' => $jar] + $o); }
function page_csrf(string $jar): string { return csrf_of(req('GET', '/activity', ['jar' => $jar])['body']); }
function badge_of(string $jar): string { preg_match('/id="header-role-badge">([^<]+)</', req('GET', '/', ['jar' => $jar])['body'], $m); return trim($m[1] ?? ''); }
/** A POST as a signed-on person, CSRF from their own page; JSON by default. */
function post_as(string $jar, string $path, array $form, array $headers = ['Accept: application/json']): array
{
    return req('POST', $path, ['jar' => $jar, 'headers' => array_merge($headers, ['X-CSRF-Token: ' . page_csrf($jar)]), 'form' => $form]);
}
/** A person's action token and an agent's run token with its relay, as the kernel mints them. */
function person_token(int $m, int $ttl = 300): string { $p = $m . '.' . (time() + $ttl); return $p . '.' . hash_hmac('sha256', $p, need('ACTION_TOKEN_KEY')); }
function run_token(int $m, int $runId, int $ttl = 300): string { $p = $m . '.' . (time() + $ttl) . '.' . $runId; return $p . '.' . hash_hmac('sha256', 'run:' . $p, need('ACTION_TOKEN_KEY')); }
function relay_of(string $tok): string { return hash_hmac('sha256', $tok, need('ACTIONS_RELAY_KEY')); }
function as_agent(string $tok): array { return ['Accept: application/json', 'X-Action-Token: ' . $tok, 'X-Action-Relay: ' . relay_of($tok)]; }

/** The fake kernel's state file: read, change, write. */
function kernel_state(?callable $change = null): array
{
    $f = need('FAKE_KERNEL_STATE');
    $s = json_decode((string) file_get_contents($f), true) ?: [];
    if ($change) { $s = $change($s) ?? $s; file_put_contents($f, json_encode($s)); }
    return $s;
}
function activity(string $action, ?int $since = null): array { return q('SELECT * FROM activity_log WHERE action = :a AND id > :s ORDER BY id', ['a' => $action, 's' => $since ?? 0]); }
function last_activity_id(): int { return (int) one('SELECT COALESCE(max(id), 0) FROM activity_log'); }
/** Act as a member on the proofs' own connection (the database's rules judge them). */
function as_member(int $m): void { pdo()->exec("SELECT set_config('app.member_id', '$m', false)"); }

/**
 * The proofs' SMOKE board (idempotent; through the schema's own functions, as postgres acting as Priya): "SMOKE Q4 retro", Priya's, private
 * (no everyone row — the default), shared with Dana (a Viewer) at view. Bea is a stranger to it. Returns its uuid.
 */
function seed_board(): string
{
    $id = pg("SELECT id FROM boards WHERE title = 'SMOKE Q4 retro' AND deleted_at IS NULL LIMIT 1")[0] ?? '';
    if ($id === '') {
        $id = pg("SELECT set_config('app.member_id', '26', false) \\g /dev/null\nSELECT bd_board_create(26, 'SMOKE Q4 retro', 'The quarter''s retrospective.');")[0] ?? '';
        pg("SELECT set_config('app.member_id', '26', false) \\g /dev/null\nSELECT bd_board_share('$id', 26, 'member', 30, 'view') \\g /dev/null");
    }
    return $id;
}
