<?php
/**
 * Slice 9's browser seed (`php tests/phase3/slice9/seed.php` → JSON): Priya's (26) boards — `search` ("SMOKE Search browser": stickies "Okapi relocation scheme" and "Axolotl & co", a comment "Tapir question", shared with Bea (28) at edit and
 * the Facilitator (42) at edit), `phone` (the same words, for the 375 px proof) and `arrange` (six stickies for the arrangement through the island's Undo) — and dispatch fixtures on `search`: one answered, one failed and one waiting
 * for approval, for the admin pages.
 */
require __DIR__ . '/lib.php';
[$jp] = sign_on(26);
$out = [];
foreach (['search' => 'SMOKE Search browser', 'phone' => 'SMOKE Search phone'] as $k => $title) {
    $b = make_board($jp, $title);
    share_as(26, $b, 'member', 28, 'edit');
    share_as(26, $b, 'agent', FACILITATOR, 'edit');
    $s1 = item_op(['text' => 'Okapi relocation scheme', 'x' => -250, 'y' => 0, 'z' => 'a1']);
    $s2 = item_op(['text' => 'Axolotl & co', 'x' => 250, 'y' => 0, 'z' => 'a2']);
    ops_post($jp, $b, [$s1, $s2]);
    $c = (string) body_json(thread_on($jp, $b, 'Tapir question about the scheme', $s1['item']['id']))['record_id'];
    $out[$k] = $b;
    $out[$k . '_sticky'] = $s1['item']['id'];
    $out[$k . '_comment'] = $c;
    foreach (['answered', 'failed', 'awaiting_approval'] as $i => $status) {
        $root = (string) body_json(thread_on($jp, $b, "@SMOKE Facilitator fixture $status", $s2['item']['id']))['record_id'];
        $d = dispatch_of($root);
        pg("UPDATE agent_dispatches SET status = '$status', attempts = " . ($status === 'failed' ? 6 : 1) . ", run_id = " . ($status === 'awaiting_approval' ? 55 : 'NULL') . ",
            reply_excerpt = " . ($status === 'answered' ? "'Moved the notes into a grid.'" : 'NULL') . ", detail = " . ($status === 'failed' ? "'The kernel answered 500.'" : ($status === 'awaiting_approval' ? "'Waiting for a person''s approval (request 5)'" : 'NULL')) . ",
            next_attempt_at = now() + interval '1 day' WHERE id = " . (int) $d['id']);
        $out[$k . '_' . $status] = (int) $d['id'];
    }
}
[$a, $ids] = board_with_notes($jp, 'SMOKE Arrange browser', 6, ['light_yellow', 'light_blue', 'light_yellow', 'light_blue', 'light_yellow', 'light_blue']);
share_as(26, $a, 'member', 28, 'edit');
$out['arrange'] = $a;
$out['arrange_items'] = $ids;
echo json_encode($out);
