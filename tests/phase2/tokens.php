<?php
/**
 * Proof: my tokens and my trail (sso-shell.md "Proof" 5 and 6): a token minted once — the secret on the page that minted it and in that one
 * JSON answer, never in a log, a later page or a later JSON —, listed, resolving as its person, revoked; another member's revoke refused; an
 * agent never on the screen; the trail lists my sign-on and my token's mint in sentences, a board's rows naming the board by a link, the
 * filters, and nobody else's rows.
 */
require __DIR__ . '/lib.php';
$json = ['headers' => ['Accept: application/json']];
[$jp, ] = sign_on(26);
[$jm, ] = sign_on(27);

echo "5. A token, minted once\n";
$before = last_activity_id();
$r = req('POST', '/settings/tokens/mint.php', ['jar' => $jp, 'form' => ['csrf_token' => page_csrf($jp), 'label' => 'SMOKE Claude Desktop', 'expires_in_days' => '30']]);
ok($r['code'] === 302 && str_ends_with($r['location'], '/settings/tokens/'), 'a browser\'s mint lands on My tokens');
$p = page($jp, '/settings/tokens/');
preg_match('/id="tokens-minted-value">(mcp_[0-9a-f]{48})</', $p['body'], $m);
$secret = $m[1] ?? '';
ok($secret !== '' && str_contains($p['body'], 'it is shown once') && str_contains($p['body'], '/mcp/records') && str_contains($p['body'], '/mcp/activity'), 'the secret is on the page that follows the mint, with the two MCP URLs');
$p2 = page($jp, '/settings/tokens/');
ok(!str_contains($p2['body'], $secret) && !str_contains($p2['body'], 'id="tokens-minted"'), '…and never again: the next visit does not show it');
$id = (int) one("SELECT id FROM mcp_access_tokens WHERE member_id = 26 AND label = 'SMOKE Claude Desktop'");
ok($id > 0 && one('SELECT token_hash FROM mcp_access_tokens WHERE id = :i', ['i' => $id]) === hash('sha256', $secret), 'only the SHA-256 of the secret is stored');
ok(str_contains($p2['body'], 'id="token-' . $id . '"') && str_contains($p2['body'], 'SMOKE Claude Desktop') && str_contains($p2['body'], 'id="token-' . $id . '-revoke"'), 'the token is listed with its label and a Revoke button');
$logs = q("SELECT action, after::text AS a, before::text AS b FROM activity_log WHERE id > :s", ['s' => $before]);
ok(array_filter($logs, static fn ($l) => str_contains((string) $l['a'] . (string) $l['b'], $secret) || str_contains((string) $l['a'], hash('sha256', $secret))) === [], 'the secret and its hash appear in no log row');
$mint = activity('token.mint', $before);
ok(count($mint) === 1 && str_contains((string) $mint[0]['after'], 'SMOKE Claude Desktop') && (int) $mint[0]['entity_id'] === $id, 'token.mint is logged with the label');
as_member(26);
$res = q('SELECT member_id, member_kind FROM mcp_resolve_token(:h)', ['h' => hash('sha256', $secret)]);
ok(($res[0]['member_id'] ?? null) == 26, 'the token resolves as its person (mcp_resolve_token)');
$r = req('POST', '/settings/tokens/mint.php', ['jar' => $jp, 'headers' => ['Accept: application/json', 'X-CSRF-Token: ' . page_csrf($jp)], 'form' => ['label' => 'SMOKE script']]);
$b = body_json($r);
$secret2 = (string) ($b['token'] ?? '');
ok($r['code'] === 200 && ($b['ok'] ?? false) && str_starts_with($secret2, 'mcp_') && ($b['record_id'] ?? 0) > 0, 'a JSON mint answers the token once, with its id');
$l = page($jp, '/settings/tokens/', $json);
$lb = body_json($l);
ok($l['code'] === 200 && count($lb['data']['tokens'] ?? []) === 2 && !str_contains($l['body'], $secret2) && !str_contains($l['body'], 'token_hash'), 'the JSON list names both tokens and never a secret or a hash');
$r = req('POST', '/settings/tokens/mint.php', ['jar' => $jp, 'headers' => ['Accept: application/json', 'X-CSRF-Token: ' . page_csrf($jp)], 'form' => ['label' => '']]);
ok($r['code'] === 422 && isset(body_json($r)['error']['fields']['label']), 'a mint without a label: 422 with the field named');
$r = req('POST', '/settings/tokens/mint.php', ['jar' => $jp, 'headers' => ['Accept: application/json', 'X-CSRF-Token: ' . page_csrf($jp)], 'form' => ['label' => 'x', 'expires_in_days' => '0']]);
ok($r['code'] === 422, 'an expiry of 0 days: 422');
$r = req('POST', '/settings/tokens/mint.php', ['jar' => $jp, 'headers' => ['Accept: application/json'], 'form' => ['label' => 'no csrf']]);
ok($r['code'] === 403, 'a mint without the CSRF token: 403');

echo "   revoked\n";
$r = req('POST', '/settings/tokens/revoke.php', ['jar' => $jm, 'headers' => ['Accept: application/json', 'X-CSRF-Token: ' . page_csrf($jm)], 'form' => ['token' => (string) $id]]);
ok($r['code'] === 404 && one('SELECT revoked_at FROM mcp_access_tokens WHERE id = :i', ['i' => $id]) === null, 'Marco revoking Priya\'s token: 404 — another\'s token does not exist to him — and it stays live');
$before = last_activity_id();
$r = req('POST', '/settings/tokens/revoke.php', ['jar' => $jp, 'form' => ['csrf_token' => page_csrf($jp), 'token' => (string) $id]]);
ok($r['code'] === 302 && str_contains($r['location'], 'notice=token_revoked'), 'Priya revokes her own: lands on My tokens with a notice');
ok(one('SELECT revoked_at FROM mcp_access_tokens WHERE id = :i', ['i' => $id]) !== null && q('SELECT * FROM mcp_resolve_token(:h)', ['h' => hash('sha256', $secret)]) === [], '…the token is revoked and resolves as nobody');
ok(count(activity('token.revoke', $before)) === 1, 'token.revoke is logged');
$p = page($jp, '/settings/tokens/?notice=token_revoked');
ok(str_contains($p['body'], 'id="notice-banner"') && str_contains($p['body'], 'revoked</span>') && !str_contains($p['body'], 'id="token-' . $id . '-revoke"'), 'the list marks it revoked, with no Revoke button');
$r = req('POST', '/settings/tokens/revoke.php', ['jar' => $jp, 'headers' => ['Accept: application/json', 'X-CSRF-Token: ' . page_csrf($jp)], 'form' => ['token' => (string) $id]]);
ok($r['code'] === 404, 'revoking it twice: 404');
$tok = run_token(40, 7001);
kernel_state(function ($s) { $s['facts']['7001'] = ['valid' => true, 'is_agent' => true, 'member_id' => 40, 'run_id' => 7001, 'request_id' => 'r-7001', 'endpoints' => [['id' => 1, 'application' => APP]]]; return $s; });
$r = req('GET', '/settings/tokens/', ['headers' => as_agent($tok)]);
ok($r['code'] === 403, 'an agent on My tokens: 403 — "This is for people"');
$r = req('POST', '/settings/tokens/mint.php', ['headers' => as_agent($tok), 'form' => ['label' => 'agent']]);
ok($r['code'] === 403 && (int) one("SELECT count(*) FROM mcp_access_tokens WHERE member_id = 40") === 0, 'an agent minting: 403, nothing made');

echo "6. My trail\n";
$board = seed_board();
pg("INSERT INTO activity_log (actor_member_id, source, action, entity_type, board_id, after) VALUES (26, 'web', 'board.create', 'board', '$board', '{\"title\": \"SMOKE Q4 retro\"}'),
    (26, 'web', 'item.move', 'board', '$board', '{\"count\": 3}'), (27, 'web', 'board.create', 'board', '$board', '{}')");
$t = page($jp, '/activity');
ok($t['code'] === 200 && str_contains($t['body'], 'You signed on') && str_contains($t['body'], 'You made the MCP token “SMOKE Claude Desktop”'), 'the trail lists my sign-on and my token\'s mint in sentences');
ok(str_contains($t['body'], 'You created <a href="/activity?board=' . $board) && str_contains($t['body'], '<em>SMOKE Q4 retro</em>') && str_contains($t['body'], 'You moved 3 items on'),
    '…"You created SMOKE Q4 retro", "You moved 3 items on SMOKE Q4 retro" — the board\'s title a link');
ok(!str_contains($t['body'], 'opened a screen') && substr_count($t['body'], 'You created') === 1, '…only my own rows (not Marco\'s), and no screen views');
$tb = page($jp, '/activity?board=' . $board);
ok(str_contains($tb['body'], 'id="activity-filter-board">On SMOKE Q4 retro') && !str_contains($tb['body'], 'You signed on') && str_contains($tb['body'], 'You moved 3 items'), 'the board filter: that board\'s rows of mine');
pg("UPDATE activity_log SET occurred_at = now() - interval '40 days' WHERE action = 'item.move' AND actor_member_id = 26");
$ts = page($jp, '/activity?since=30');
ok(!str_contains($ts['body'], 'You moved 3 items') && str_contains($ts['body'], 'You created'), 'since=30: a row from 40 days ago is left out');
$tj = page($jp, '/activity', $json);
$rows = body_json($tj)['data']['rows'] ?? [];
ok($tj['code'] === 200 && $rows !== [] && isset($rows[0]['sentence']) && !array_key_exists('after', $rows[0]), 'JSON: the sentences and the keys, never a payload');
$tx = page($jp, '/activity?board=' . $board, ['headers' => ['HX-Request: true', 'HX-Target: activity-results']]);
ok(str_starts_with(trim($tx['body']), '<div id="activity-results"'), 'the filters swap only the rows over HTMX');
[$jd, ] = sign_on(30);
ok(!str_contains(page($jd, '/activity')['body'], 'You created'), '…none at all of anyone else\'s');
finish();
