<?php
/**
 * Slice 7's helpers (docs/build-specs/history-versions.md "Proof"), on slice 6's (and through it 5's … Phase 2's): the history page as JSON or HTML, a version saved or restored,
 * a batch committed as an agent through the schema's own function, an aged fixture, one pass of the real worker. `php tests/phase3/slice7/lib.php seed` makes the browser's boards as JSON:
 * Priya's (26) `browser` ("SMOKE History browser": a frame, two stickies "Alpha" and "Beta", one named version "Baseline" taken before they moved), `phone`, shared with Bea (28) at edit
 * and Dana (30) at comment; `wall` is Priya's alone.
 */
require dirname(__DIR__) . '/slice6/lib.php';

/** The history page as the jar's person: JSON (data) or, with $html, the page. */
function history_json(string $jar, string $board, string $query = ''): array { return page($jar, "/boards/$board/history" . ($query !== '' ? "?$query" : ''), ['headers' => ['Accept: application/json']]); }
function history_html(string $jar, string $board, string $query = '', array $headers = []): array { return page($jar, "/boards/$board/history" . ($query !== '' ? "?$query" : ''), $headers !== [] ? ['headers' => $headers] : []); }
/** Save a named version / restore one, as the jar's person (JSON). */
function save_version(string $jar, string $board, string $label): array { return post_as($jar, '/boards/version-save.php', ['board' => $board, 'label' => $label]); }
function restore_version(string $jar, string $board, $no, array $headers = ['Accept: application/json']): array { return post_as($jar, '/boards/version-restore.php', ['board' => $board, 'version' => $no], $headers); }
/** A batch as a member that is not a session (an agent): the schema's own function, source agent — what the kernel's actions server ends up calling. Answers the seq. */
function commit_as_member(string $board, int $member, string $source, array $ops): int
{
    $json = str_replace("'", "''", json_encode($ops));
    return (int) (pg("SELECT set_config('app.member_id', '$member', false) \\g /dev/null\nSELECT (bd_commit_ops('$board', $member, '$source', NULL, NULL, '$json'::jsonb)->>'seq')")[0] ?? 0);
}
/** An item's live state at a glance: [x, deleted?]. */
function item_state(string $id): array { $r = item_row($id); return $r === [] ? [null, true] : [(float) $r['x'], $r['deleted_at'] !== null]; }
function versions_of(string $board): array { return q('SELECT version_no, reason, label FROM board_versions WHERE board_id = CAST(:b AS uuid) ORDER BY version_no', ['b' => $board]); }
function ops_of(string $board, ?string $source = null): array
{
    return q('SELECT seq, source, member_id FROM board_ops WHERE board_id = CAST(:b AS uuid)' . ($source !== null ? ' AND source = :s' : '') . ' ORDER BY seq', ['b' => $board] + ($source !== null ? ['s' => $source] : []));
}
/** One pass of the real worker (the named passes), at a fake clock when given; answers the pass's report. */
function worker_pass(string $passes, ?string $now = null): array
{
    $env = $now !== null ? 'BD_WORKER_NOW=' . escapeshellarg($now) . ' ' : '';
    exec('cd ' . escapeshellarg(dirname(__DIR__, 3)) . " && {$env}php bin/worker.php --passes=$passes 2>&1", $out, $rc);
    $j = json_decode((string) end($out), true);
    return ['rc' => $rc, 'ran' => $j['ran'] ?? [], 'errors' => $j['errors'] ?? [], 'raw' => implode("\n", $out)];
}
/** A board with a frame and two stickies (Alpha at x 0, Beta at x 300), made by the jar's person. [board, frame, alpha, beta]. */
function board_with_three(string $jar, string $title): array
{
    $b = make_board($jar, $title);
    $f = frame_op('Plan', -400, -300, 800, 600);
    $a = item_op(['text' => 'Alpha', 'x' => 0, 'y' => 0, 'z' => 'a1']);
    $c = item_op(['text' => 'Beta', 'x' => 300, 'y' => 0, 'z' => 'a2']);
    ops_post($jar, $b, [$f, $a, $c]);
    return [$b, $f['item']['id'], $a['item']['id'], $c['item']['id']];
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['argv'][0] ?? '') === realpath(__FILE__) && ($_SERVER['argv'][1] ?? '') === 'seed') {
    [$jp, ] = sign_on(26);
    $out = [];
    foreach (['browser' => 'SMOKE History browser', 'phone' => 'SMOKE History phone', 'wall' => 'SMOKE History wall'] as $k => $title) {
        [$b, $f, $a, $c] = board_with_three($jp, $title);
        $out[$k] = $b;
        $out[$k . '_frame'] = $f;
        $out[$k . '_alpha'] = $a;
        $out[$k . '_beta'] = $c;
        if ($k !== 'wall') {
            share_as(26, $b, 'member', 28, 'edit');
            share_as(26, $b, 'member', 30, 'comment');
            $v = body_json(save_version($jp, $b, 'Baseline'));
            $out[$k . '_baseline'] = $v['record_id'] ?? null;
        }
    }
    // after the baseline: Alpha moves and Beta goes, so that Restore has something to do
    foreach (['browser', 'phone'] as $k) {
        ops_post($jp, $out[$k], [['op' => 'update', 'id' => $out[$k . '_alpha'], 'fields' => ['x' => 700]], ['op' => 'delete', 'ids' => [$out[$k . '_beta']]]]);
    }
    echo json_encode($out);
}
