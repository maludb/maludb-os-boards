<?php
/**
 * Helpers for slice 1's proofs (docs/build-specs/boards-folders.md "Proof") — Phase 2's (tests/phase2/lib.php: the requests, the hand-off, the
 * scratch database as the application and as postgres) plus the slice's own: a board made through the form's action, a share, a folder.
 * Run them through tests/phase3/slice1/run.sh (scratch bd_dev1). The fixture is bin/dev_directory.json's: 1 the owner (super-admin), 26 Priya
 * (Member, Sales), 27 Marco (Boards admin + Member, Finance), 28 Bea (Member by capability, Accounting), 29 Ann (external Viewer), 30 Dana
 * (Viewer, Sales), 31 Lee (Viewer by capability, Sales), 40 the Expert and 42 the Facilitator (agents, Members), 41 the Watcher (an agent with
 * no Boards grant). Sales is department 8.
 */
require dirname(__DIR__, 2) . '/phase2/lib.php';
const SALES = 8;

/** Make a board as the jar's person through board_create (JSON); answers its uuid (fails the proof loudly when refused). */
function make_board(string $jar, string $title, array $extra = []): string
{
    $r = post_as($jar, '/boards/save.php', ['title' => $title] + $extra);
    $id = (string) (body_json($r)['record_id'] ?? '');
    if ($r['code'] !== 200 || $id === '') { fwrite(STDERR, "make_board($title) failed: {$r['code']} {$r['body']}\n"); }
    return $id;
}
/** A board action as the jar's person, JSON. */
function act(string $jar, string $file, array $form): array { return post_as($jar, $file, $form); }
/** The error message of a JSON refusal. */
function msg(array $r): string { return (string) (body_json($r)['error']['message'] ?? ''); }
/** A member's level on a board, as the database answers it. */
function level_of(string $board, int $member): string { return (string) one('SELECT bd_board_level(CAST(:b AS uuid), :m)', ['b' => $board, 'm' => $member]); }
