<?php
/**
 * The browser proofs' boards (tests/phase3/slice2/browser.mjs, perf.mjs), made through the application as Priya and shared through the
 * schema's own function; prints {canvas, phone, thumb, perf} as JSON. The perf board is loaded from tests/fixtures/board-5000.json straight
 * into board_items as postgres (5,000 rows through the triggers — the box, the count — in one statement).
 */
require __DIR__ . '/lib.php';
[$jp, ] = sign_on(26);
$canvas = make_board($jp, 'SMOKE Canvas');
share_as(26, $canvas, 'member', 28, 'edit');      // Bea, a second editor
share_as(26, $canvas, 'member', 30, 'edit');      // Dana, a Viewer: comment at most
$phone = make_board($jp, 'SMOKE Phone');
$thumb = make_board($jp, 'SMOKE Thumbnail');
$perf = make_board($jp, 'SMOKE 5000');
$fx = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/fixtures/board-5000.json'), true);
$values = [];
foreach ($fx['items'] as $i) {
    $style = json_encode($i['type'] === 'sticky_note' ? ['fillColor' => $i['color']] : ($i['type'] === 'shape' ? ['fillColor' => $i['color'], 'borderColor' => '#1a1a1a'] : (object) []));
    $data = json_encode(['content' => [['type' => 'text', 'text' => ['content' => $i['text'], 'link' => null], 'plain_text' => $i['text']]]] + ($i['type'] === 'shape' ? ['shape' => $i['shape']] : []));
    $values[] = sprintf("('%s', '%s', '%s', %s, %s, %s, %s, '%s', '%s'::jsonb, '%s'::jsonb, 26, 26)", uuid4(), $perf, $i['type'], $i['x'], $i['y'], $i['w'], $i['h'], $i['z'],
        str_replace("'", "''", $style), str_replace("'", "''", $data));
}
pg("INSERT INTO board_items (id, board_id, type, x, y, width, height, z, style, data, created_by, updated_by) VALUES\n" . implode(",\n", $values) . ';');
echo json_encode(['canvas' => $canvas, 'phone' => $phone, 'thumb' => $thumb, 'perf' => $perf, 'perf_items' => (int) one('SELECT item_count FROM boards WHERE id = CAST(:b AS uuid)', ['b' => $perf])]);
