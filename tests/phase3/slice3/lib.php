<?php
/**
 * Slice 3's PHP helpers (docs/build-specs/live.md "Proof"), on slice 2's (and through them slice 1's and Phase 2's): run as a command by
 * tests/phase3/slice3/run.sh and relay_test.py —
 *   php tests/phase3/slice3/lib.php seed      → the boards the proofs use, as JSON: {live, big, trash, phone}
 *   php tests/phase3/slice3/lib.php signout N → the kernel's sign-out notice for member N posted to /sso/logout (as the kernel posts it)
 * Every board is Priya's (26), shared with Bea (28) at edit and Dana (30, a Viewer) at view; Lee (31) is a stranger to them but for the phone's
 * board (view); the Expert (40, an agent) edits the browser's board.
 */
require dirname(__DIR__) . '/slice2/lib.php';

/** POST the kernel's sign-out notice for a member (app/auth.php verify_sso_logout_notice's shape). */
function sign_out_at_kernel(int $member): int
{
    $notice = $member . '.' . time() . '.' . APP;
    $mac = hash_hmac('sha256', 'sso-logout:' . $notice, need('ACTION_TOKEN_KEY'));
    return req('POST', '/sso/logout', ['form' => ['notice' => $notice . '.' . $mac]])['code'];
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['argv'][0] ?? '') === realpath(__FILE__)) {
    $cmd = $_SERVER['argv'][1] ?? '';
    if ($cmd === 'seed') {
        [$jp, ] = sign_on(26);
        $out = [];
        foreach (['live' => 'SMOKE Live', 'big' => 'SMOKE Live big', 'trash' => 'SMOKE Live trash', 'phone' => 'SMOKE Live phone', 'browser' => 'SMOKE Live browser'] as $k => $title) {
            $b = make_board($jp, $title);
            share_as(26, $b, 'member', 28, 'edit');
            share_as(26, $b, 'member', 30, 'view');
            $out[$k] = $b;
        }
        share_as(26, $out['browser'], 'agent', 40, 'edit');      // the Expert works on the browser's board (proof 10)
        share_as(26, $out['phone'], 'member', 31, 'view');       // Lee — a fifth person in the phone's room (proof 13)
        echo json_encode($out);
        exit(0);
    }
    if ($cmd === 'signout') {
        echo sign_out_at_kernel((int) ($_SERVER['argv'][2] ?? 0));
        exit(0);
    }
    fwrite(STDERR, "usage: lib.php seed | signout <member>\n");
    exit(2);
}
