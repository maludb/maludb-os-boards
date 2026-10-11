<?php
declare(strict_types=1);
/**
 * POST /boards/{board}/live-ticket — a fresh 60-second ticket for the live relay (live-relay.md; a door, not a screen): the board's gate at
 * `view` (a stranger: 404), the level the database gives now. `{"ticket": "…", "expires_in": 60}`; `{"ticket": null, "live": false}` when live
 * updates are off — a setting, not an error (the island polls and asks again in a minute). People only — an agent never opens a socket.
 * Changes nothing, so it is never logged.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/live.php';
require_post();
require_login();
verify_csrf();
require_human();
$pdo = db();
$gate = bd_board_or_404($pdo, isset($_GET['board']) ? (string) $_GET['board'] : (string) ($_POST['board'] ?? ''), 'view');
session_write_close();
if (!live_enabled($pdo)) {
    json_response(['ticket' => null, 'live' => false, 'retry_in' => 60]);
}
json_response(['ticket' => bd_live_ticket((int) current_member_id(), (string) $gate['board_id'], (string) $gate['my_level']), 'expires_in' => 60]);
