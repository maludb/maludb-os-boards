<?php
/**
 * Slice 8's helpers (docs/build-specs/templates-export.md "Proof"), on slice 7's (and through it 6's … Phase 2's): the gallery and a template's page as JSON or HTML, the four actions, the export door,
 * a board with a frame and a few items, a fixture board past the SVG's 400 items. `php tests/phase3/slice8/seed.php` makes the browser's boards as JSON.
 */
require dirname(__DIR__) . '/slice7/lib.php';

const SHIPPED = ['retro' => '7e000000-0000-4000-8000-000000000001', 'brainstorm' => '7e000000-0000-4000-8000-000000000002', 'swot' => '7e000000-0000-4000-8000-000000000003',
                 'storymap' => '7e000000-0000-4000-8000-000000000004', 'flow' => '7e000000-0000-4000-8000-000000000005', 'weekly' => '7e000000-0000-4000-8000-000000000006'];

function gallery(string $jar, string $query = '', array $headers = ['Accept: application/json']): array { return page($jar, '/templates' . ($query !== '' ? "?$query" : ''), ['headers' => $headers]); }
function gallery_rows(string $jar, string $query = ''): array { return body_json(gallery($jar, $query))['data']['rows'] ?? []; }
function template_page(string $jar, string $id, array $headers = ['Accept: application/json']): array { return page($jar, "/templates/$id", ['headers' => $headers]); }
function use_template(string $jar, string $template, array $more = [], array $headers = ['Accept: application/json']): array { return post_as($jar, '/templates/use.php', ['template' => $template] + $more, $headers); }
function duplicate_board(string $jar, string $board, array $more = [], array $headers = ['Accept: application/json']): array { return post_as($jar, '/boards/duplicate.php', ['board' => $board] + $more, $headers); }
function save_template(string $jar, string $board, array $more = [], array $headers = ['Accept: application/json']): array { return post_as($jar, '/templates/save.php', ['board' => $board] + $more, $headers); }
function publish_template(string $jar, string $board, array $more = [], array $headers = ['Accept: application/json']): array { return post_as($jar, '/templates/publish.php', ['board' => $board] + $more, $headers); }
function export_get(string $jar, string $board, string $query = 'format=svg'): array { return req('GET', "/boards/$board/export" . ($query !== '' ? "?$query" : ''), ['jar' => $jar]); }
function export_png_log(string $jar, string $board, array $form = ['format' => 'png', 'scope' => 'view']): array
{
    return req('POST', "/boards/$board/export", ['jar' => $jar, 'headers' => ['Accept: application/json', 'X-CSRF-Token: ' . csrf_for($jar)], 'form' => $form]);
}
function header_of(array $r, string $name): string { return preg_match('/^' . preg_quote($name, '/') . ':\s*(.+?)\r?$/mi', $r['headers'], $m) ? trim($m[1]) : ''; }
/** A board's live items by type, as a map. */
function counts_of(string $board): array { return array_column(q('SELECT type, count(*) AS n FROM board_items WHERE board_id = CAST(:b AS uuid) AND deleted_at IS NULL GROUP BY type', ['b' => $board]), 'n', 'type'); }
function item_total(string $board): int { return (int) one('SELECT count(*) FROM board_items WHERE board_id = CAST(:b AS uuid) AND deleted_at IS NULL', ['b' => $board]); }
/** A sticky inside a frame (relative centre), by the op a canvas would send. */
function child_op(string $frame, string $text, float $x, float $y, string $z): array { return item_op(['text' => $text, 'parent_id' => $frame, 'x' => $x, 'y' => $y, 'z' => $z]); }
/** A board with a frame "Plan" and two stickies in it ("Alpha", "Beta") and one free "Gamma" — the jar's person's. [board, frame, alpha, beta, gamma] */
function board_with_frame(string $jar, string $title): array
{
    $b = make_board($jar, $title);
    $f = frame_op('Plan', 0, 0, 800, 600);
    $a = child_op($f['item']['id'], 'Alpha', 200, 200, 'a1');
    $c = child_op($f['item']['id'], 'Beta', 500, 200, 'a2');
    $g = item_op(['text' => 'Gamma', 'x' => 1200, 'y' => 0, 'z' => 'a3']);
    ops_post($jar, $b, [$f, $a, $c, $g]);
    return [$b, $f['item']['id'], $a['item']['id'], $c['item']['id'], $g['item']['id']];
}
/** A board of $n stickies, 60 of them in one frame — straight into the table as postgres (a fixture; the op path has its own proofs). [board, frame] */
function big_board(string $jar, int $n, string $title): array
{
    $b = make_board($jar, $title);
    $f = uuid4();
    pg("INSERT INTO board_items (id, board_id, type, x, y, width, height, z, style, data, created_by, updated_by)
        VALUES ('$f', '$b', 'frame', 0, 0, 2400, 1600, 'a0', '{}', '{\"title\": \"Crowd\"}', 26, 26)");
    pg("INSERT INTO board_items (id, board_id, type, parent_id, x, y, width, height, z, style, data, created_by, updated_by)
        SELECT gen_random_uuid(), '$b', 'sticky_note', '$f', 100 + (n % 10) * 220, 100 + (n / 10) * 220, 200, 200, 'a' || to_char(n, 'FM999'), '{\"fillColor\": \"light_yellow\"}',
               jsonb_build_object('content', bd_rich_text('Crowd ' || n)), 26, 26 FROM generate_series(1, 60) n");
    pg("INSERT INTO board_items (id, board_id, type, x, y, width, height, z, style, data, created_by, updated_by)
        SELECT gen_random_uuid(), '$b', 'sticky_note', 3000 + (n % 30) * 220, (n / 30) * 220, 200, 200, 'a' || to_char(n, 'FM999'), '{\"fillColor\": \"light_blue\"}',
               jsonb_build_object('content', bd_rich_text('Spread ' || n)), 26, 26 FROM generate_series(1, " . ($n - 61) . ') n');
    return [$b, $f];
}
