<?php
/**
 * Slice 4's helpers (docs/build-specs/connectors-frames.md "Proof"), on slice 2's: `php tests/phase3/slice4/lib.php seed` makes the browser's
 * boards as JSON — {draw, frames, phone}: Priya's (26), shared with Bea (28) at edit; `frames` holds three frames placed for the navigator's
 * reading order, `draw` a sticky and a shape for the connectors.
 */
require dirname(__DIR__) . '/slice2/lib.php';

/** A frame's create op (frames are slice 4's; the database's rules apply). */
function frame_op(string $title, float $x, float $y, float $w = 600, float $h = 400): array
{
    return ['op' => 'create', 'item' => ['id' => uuid4(), 'type' => 'frame', 'x' => $x, 'y' => $y, 'width' => $w, 'height' => $h, 'z' => 'a0',
        'style' => ['fillColor' => '#ffffff'], 'data' => ['title' => $title]]];
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['argv'][0] ?? '') === realpath(__FILE__) && ($_SERVER['argv'][1] ?? '') === 'seed') {
    [$jp, ] = sign_on(26);
    $draw = make_board($jp, 'SMOKE Connectors');
    $frames = make_board($jp, 'SMOKE Frames');
    $phone = make_board($jp, 'SMOKE Frames phone');
    foreach ([$draw, $frames, $phone] as $b) { share_as(26, $b, 'member', 28, 'edit'); }
    $sticky = item_op(['text' => 'Shipped late', 'x' => -250, 'y' => 0, 'z' => 'a1']);
    $shape = item_op(['type' => 'shape', 'text' => 'Retro actions', 'x' => 250, 'y' => 120, 'width' => 200, 'height' => 120, 'z' => 'a2',
                      'style' => ['fillColor' => '#ffffff', 'borderColor' => '#1a1a1a'], 'data' => ['shape' => 'rectangle']]);
    ops_post($jp, $draw, [$sticky, $shape]);
    // three frames: reading order is top to bottom, then left to right — C (top), then A (left) and B (right) on the row below
    $fa = frame_op('Alpha', -400, 700);
    $fb = frame_op('Beta', 400, 700);
    $fc = frame_op('Charlie', 0, 0);
    ops_post($jp, $frames, [$fa, $fb, $fc]);
    ops_post($jp, $frames, [item_op(['text' => 'in Alpha', 'parent_id' => $fa['item']['id'], 'x' => 150, 'y' => 200])]);
    $pa = frame_op('Phone one', 0, 0);
    $pb = frame_op('Phone two', 0, 900);
    ops_post($jp, $phone, [$pa, $pb]);
    echo json_encode(['draw' => $draw, 'frames' => $frames, 'phone' => $phone, 'sticky' => $sticky['item']['id'], 'shape' => $shape['item']['id'],
        'order' => [$fc['item']['id'], $fa['item']['id'], $fb['item']['id']], 'phone_frames' => [$pa['item']['id'], $pb['item']['id']]]);
}
