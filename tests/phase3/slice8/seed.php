<?php
/**
 * Slice 8's browser seed (`php tests/phase3/slice8/seed.php` → JSON): Priya's (26) boards — `canvas` ("SMOKE Export canvas": a frame "Plan" with two stickies "Alpha" and "Beta" in it, a free "Gamma", a connector
 * Alpha → Gamma — what the PNG export draws; shared with Bea (28) at edit and Dana (30) at view), `phone` ("SMOKE Export phone": the same, for the settings forms at 375 px and the JSON proof) and `empty`.
 */
require __DIR__ . '/lib.php';
[$jp] = sign_on(26);
$out = [];
foreach (['canvas' => 'SMOKE Export canvas', 'phone' => 'SMOKE Export phone'] as $k => $title) {
    [$b, $f, $a, $c, $g] = board_with_frame($jp, $title);
    $con = ['op' => 'create', 'item' => ['id' => uuid4(), 'type' => 'connector', 'start_item_id' => $a, 'end_item_id' => $g, 'x' => 0, 'y' => 0, 'width' => 0, 'height' => 0, 'z' => 'a4',
        'style' => ['endStrokeCap' => 'arrow'], 'data' => ['shape' => 'straight']]];
    ops_post($jp, $b, [$con]);
    share_as(26, $b, 'member', 28, 'edit');
    share_as(26, $b, 'member', 30, 'view');
    $out[$k] = $b;
    $out[$k . '_frame'] = $f;
    $out[$k . '_alpha'] = $a;
}
$out['empty'] = make_board($jp, 'SMOKE Export empty');
share_as(26, $out['empty'], 'member', 30, 'view');
$out['folder'] = (int) body_json(act($jp, '/folders/save.php', ['name' => 'SMOKE Browser folder']))['record_id'];
echo json_encode($out);
