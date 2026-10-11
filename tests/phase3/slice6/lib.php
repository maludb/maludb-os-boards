<?php
/**
 * Slice 6's helpers (docs/build-specs/comments-notifications.md "Proof"), on slice 5's (and through it 4's … Phase 2's): a comment posted as the island's rail posts it,
 * the notices and the outbox as rows, the fake kernel's text log and the fake MaluMail's mail log. `php tests/phase3/slice6/lib.php seed` makes the browser's boards as
 * JSON: Priya's (26) `main` ("SMOKE Comments" and `browser` for the browser proof — a sticky "Shipped late" and a shape, shared with Bea (28) at edit and Dana (30), a Viewer, at comment; Lee (31), the
 * Expert (40) and the Facilitator (42) not shared) and `phone`; the fixture's names are "SMOKE Priya", "SMOKE Bea" … so a mention is "@SMOKE Bea".
 */
require dirname(__DIR__) . '/slice5/lib.php';

/** POST a comment action as the jar's person (JSON). */
function comment_as(string $jar, string $file, array $form): array { return post_as($jar, '/comments/' . $file, $form); }
/** A new thread on an item (or at a point); answers the JSON. */
function thread_on(string $jar, string $board, string $text, ?string $item = null, array $more = []): array
{
    return comment_as($jar, 'create.php', ['board' => $board, 'text' => $text] + ($item !== null ? ['item' => $item] : ['x' => 100, 'y' => 40]) + $more);
}
/** The comment row (as postgres-visible to the proof's connection: the table itself). */
function comment_row(string $id): array { return q('SELECT * FROM comments WHERE id = CAST(:i AS uuid)', ['i' => $id])[0] ?? []; }
/** Notices of a member, newest first. */
function notices_of(int $member, ?string $kind = null): array
{
    return q('SELECT * FROM notifications WHERE member_id = :m' . ($kind !== null ? ' AND kind = :k' : '') . ' ORDER BY id DESC', ['m' => $member] + ($kind !== null ? ['k' => $kind] : []));
}
/** Outbox rows of a member (by channel when given), oldest first. */
function outbox_of(int $member, ?string $channel = null): array
{
    return q('SELECT * FROM notification_outbox WHERE member_id = :m' . ($channel !== null ? ' AND channel = :c' : '') . ' ORDER BY id', ['m' => $member] + ($channel !== null ? ['c' => $channel] : []));
}
/** The lines of a log file the fakes keep (JSON per line). */
function log_lines(string $file): array
{
    return array_values(array_filter(array_map(static fn (string $l): array => json_decode($l, true) ?: [], is_file($file) ? file($file, FILE_IGNORE_NEW_LINES) : [])));
}
/** One pass of the real worker (the outbox only), at a fake clock when given; answers the pass's report. */
function worker_outbox(?string $now = null, string $prefix = ''): array
{
    $env = ($now !== null ? 'BD_WORKER_NOW=' . escapeshellarg($now) . ' ' : '') . $prefix;
    exec('cd ' . escapeshellarg(dirname(__DIR__, 3)) . " && {$env}php bin/worker.php --passes=outbox 2>&1", $out, $rc);
    $j = json_decode((string) end($out), true);
    return ['rc' => $rc, 'ran' => $j['ran']['outbox'] ?? null, 'errors' => $j['errors'] ?? [], 'raw' => implode("\n", $out)];
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['argv'][0] ?? '') === realpath(__FILE__) && ($_SERVER['argv'][1] ?? '') === 'seed') {
    [$jp, ] = sign_on(26);
    $out = [];
    foreach (['main' => 'SMOKE Comments', 'browser' => 'SMOKE Comments browser', 'phone' => 'SMOKE Comments phone', 'wall' => 'SMOKE Comments wall'] as $k => $title) {
        $out[$k] = make_board($jp, $title);
        if ($k !== 'wall') {
            share_as(26, $out[$k], 'member', 28, 'edit');
            share_as(26, $out[$k], 'member', 30, 'comment');
        }
    }
    $sticky = item_op(['text' => 'Shipped late', 'x' => -250, 'y' => 0, 'z' => 'a1']);
    $shape = item_op(['type' => 'shape', 'text' => 'Retro actions', 'x' => 250, 'y' => 120, 'width' => 200, 'height' => 120, 'z' => 'a2',
                      'style' => ['fillColor' => '#ffffff', 'borderColor' => '#1a1a1a'], 'data' => ['shape' => 'rectangle']]);
    ops_post($jp, $out['main'], [$sticky, $shape]);
    $out['sticky'] = $sticky['item']['id'];
    $out['shape'] = $shape['item']['id'];
    $bs = item_op(['text' => 'Shipped late', 'x' => -250, 'y' => 0, 'z' => 'a1']);
    ops_post($jp, $out['browser'], [$bs, item_op(['type' => 'shape', 'text' => 'Retro actions', 'x' => 250, 'y' => 120, 'width' => 200, 'height' => 120, 'z' => 'a2', 'data' => ['shape' => 'rectangle']])]);
    $out['browser_sticky'] = $bs['item']['id'];
    $s2 = item_op(['text' => 'Phone sticky', 'x' => 0, 'y' => 0, 'z' => 'a1']);
    ops_post($jp, $out['phone'], [$s2]);
    $out['phone_sticky'] = $s2['item']['id'];
    echo json_encode($out);
}
