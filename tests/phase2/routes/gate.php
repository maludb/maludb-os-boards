<?php
declare(strict_types=1);
/**
 * THE GATE'S FIXTURE ROUTE — proofs only (served at /__proof/gate.php by tests/dev_router.php under php -S, and by a proof-only Alias that
 * tests/phase2/servers.sh adds to the rendered vhost under Apache; never in deploy/ or html/). GET ?board=<uuid>&need=<level>: the board gate
 * exactly as a slice's page calls it (bd_board_or_404 — 404 for a board one cannot see, 403 with the database's sentence below the need);
 * with &batch=<json>, the canvas's write path after it (bd_commit_ops through bd_guard — a malformed batch is the database's 22023 → 422).
 */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_login();
$pdo = db();
$b = bd_board_or_404($pdo, request_string('board'), request_string('need', 'view'));
$out = ['board_id' => $b['board_id'], 'title' => $b['title'], 'my_level' => $b['my_level']];
if (isset($_GET['batch'])) {
    $ops = (string) $_GET['batch'];
    $out['commit'] = bd_guard($pdo, static fn () => json_decode((string) one_value($pdo,
        'SELECT bd_commit_ops(CAST(:b AS uuid), app_current_member_id(), \'web\', \'proof\', gen_random_uuid(), CAST(:ops AS jsonb))', ['b' => $b['board_id'], 'ops' => $ops]), true));
}
if (wants_json()) {
    respond_screen($out);
}
header('Content-Type: text/plain');
echo 'gate ok ' . json_encode($out);
