<?php
declare(strict_types=1);

/**
 * The layout engine (canvas-core.md "The layout engine"; canvas.md "The agents' item actions"): an agent never does arithmetic — it names a
 * layout and the server places the block. Two layers (phase1-consistency reconciliation 5): bd_layout() answers CENTRES in a block whose
 * top-left is (0, 0); items_layout() turns them into create ops placed on the board (in a frame, at x/y, or in empty space below the board)
 * and grows a frame that is too small, in the same batch. Slice 9's items_arrange builds update ops over bd_layout().
 */

require_once __DIR__ . '/fractional.php';

const ITEM_SIZES = ['sticky_note' => [200.0, 200.0], 'shape' => [200.0, 120.0], 'text' => [200.0, 60.0]];
const LAYOUT_GAP = 24.0;
const LAYOUT_GAP_CLUSTER = 12.0;
const FRAME_TITLE_BAND = 60.0;       // a frame's title band: a block inside a frame starts below it
const EMPTY_SPACE_DROP = 200.0;      // a block with no place goes this far below everything on the board

/**
 * The centres of $n cells in a block whose top-left is (0, 0). $opts: width, height (the cell — the item's size), columns (grid, cluster),
 * gap. Layouts: grid (columns, else ⌈√n⌉), row, column, cluster (a tight grid, gap 12). Answers [[x, y], …] in reading order.
 */
function bd_layout(string $layout, int $n, array $opts): array
{
    $w = (float) ($opts['width'] ?? 200);
    $h = (float) ($opts['height'] ?? 200);
    $gap = (float) ($opts['gap'] ?? ($layout === 'cluster' ? LAYOUT_GAP_CLUSTER : LAYOUT_GAP));
    $cols = bd_layout_columns($layout, $n, $opts);
    $out = [];
    for ($i = 0; $i < $n; $i++) {
        $c = $i % $cols;
        $r = intdiv($i, $cols);
        $out[] = [$c * ($w + $gap) + $w / 2, $r * ($h + $gap) + $h / 2];
    }
    return $out;
}

/** The number of columns a layout uses for $n items. */
function bd_layout_columns(string $layout, int $n, array $opts): int
{
    $n = max(1, $n);
    return match ($layout) {
        'row' => $n,
        'column' => 1,
        default => max(1, min($n, (int) ($opts['columns'] ?? 0) ?: (int) ceil(sqrt($n)))),
    };
}

/** The block's width and height for $n cells. */
function bd_layout_size(string $layout, int $n, array $opts): array
{
    $w = (float) ($opts['width'] ?? 200);
    $h = (float) ($opts['height'] ?? 200);
    $gap = (float) ($opts['gap'] ?? ($layout === 'cluster' ? LAYOUT_GAP_CLUSTER : LAYOUT_GAP));
    $cols = bd_layout_columns($layout, $n, $opts);
    $rows = (int) ceil(max(1, $n) / $cols);
    return [$cols * $w + ($cols - 1) * $gap, $rows * $h + ($rows - 1) * $gap];
}

/**
 * The create ops for an agent's item_create_batch, placed and ordered, as ONE batch. $spec: type, texts (list; [] = one empty item), layout,
 * columns, x, y (the first item's centre — frame-relative when frame is given), frame (a frame's uuid), color, shape, width, height.
 * Answers ['ops' => [...], 'where' => words for the did sentence, 'ids' => the new items, 'grown' => bool].
 * The origin, in order: x/y given → the first centre there; else inside the frame, below what it already holds (gap, title band + gap when
 * empty), the frame growing to hold the block; else empty space — the block's top-left at (extent.x1, extent.y2 + 200), or (0, 0).
 */
function items_layout(PDO $pdo, string $boardId, array $spec): array
{
    $type = (string) $spec['type'];
    $texts = array_values($spec['texts'] ?? []);
    if ($texts === []) {
        $texts = [''];
    }
    $n = count($texts);
    [$w, $h] = ITEM_SIZES[$type];
    $w = isset($spec['width']) ? (float) $spec['width'] : $w;
    $h = isset($spec['height']) ? (float) $spec['height'] : $h;
    $layout = (string) ($spec['layout'] ?? 'grid');
    $opts = ['width' => $w, 'height' => $h] + (isset($spec['columns']) ? ['columns' => (int) $spec['columns']] : []);
    $centres = bd_layout($layout, $n, $opts);
    [$bw, $bh] = bd_layout_size($layout, $n, $opts);
    $gap = $layout === 'cluster' ? LAYOUT_GAP_CLUSTER : LAYOUT_GAP;
    $ops = [];
    $frame = null;
    $grown = false;

    if (!empty($spec['frame'])) {
        $frame = one_row($pdo, "SELECT id, x, y, width, height, data->>'title' AS title FROM board_items
                                 WHERE id = CAST(:f AS uuid) AND board_id = CAST(:b AS uuid) AND type = 'frame' AND deleted_at IS NULL",
            ['f' => $spec['frame'], 'b' => $boardId]);
        if ($frame === null) {
            throw new DomainException('There is no frame ' . $spec['frame'] . ' on this board.');
        }
    }
    if (isset($spec['x'], $spec['y'])) {
        // the first item's centre is given: the block's top-left follows from it
        $ox = (float) $spec['x'] - $w / 2;
        $oy = (float) $spec['y'] - $h / 2;
        $where = $frame !== null ? 'in ' . frame_words($frame) : 'at ' . round((float) $spec['x']) . ', ' . round((float) $spec['y']);
    } elseif ($frame !== null) {
        // below what the frame already holds (frame-relative bottoms), else under its title band
        $bottom = one_value($pdo, "SELECT max(y + height / 2) FROM board_items WHERE parent_id = CAST(:f AS uuid) AND deleted_at IS NULL", ['f' => $frame['id']]);
        $ox = $gap;
        $oy = $bottom === null ? FRAME_TITLE_BAND + $gap : (float) $bottom + $gap;
        $where = 'in ' . frame_words($frame);
    } else {
        $ext = one_row($pdo, "SELECT min((bbox[1])[0]) AS x1, max((bbox[0])[1]) AS y2 FROM board_items WHERE board_id = CAST(:b AS uuid) AND deleted_at IS NULL HAVING count(*) > 0",
            ['b' => $boardId]);
        $ox = $ext === null ? 0.0 : (float) $ext['x1'];
        $oy = $ext === null ? 0.0 : (float) $ext['y2'] + EMPTY_SPACE_DROP;
        $where = $ext === null ? 'on the empty board' : 'below the board';
    }
    if ($frame !== null) {
        // grow the frame (its top-left fixed — the children are relative to it) so the block fits, in the same batch
        $needW = max((float) $frame['width'], $ox + $bw + $gap);
        $needH = max((float) $frame['height'], $oy + $bh + $gap);
        if ($needW > (float) $frame['width'] || $needH > (float) $frame['height']) {
            $ops[] = ['op' => 'update', 'id' => $frame['id'], 'fields' => [
                'x' => (float) $frame['x'] + ($needW - (float) $frame['width']) / 2, 'y' => (float) $frame['y'] + ($needH - (float) $frame['height']) / 2,
                'width' => $needW, 'height' => $needH]];
            $grown = true;
        }
    }
    $zs = [z_after($pdo, $boardId)];
    for ($i = 1; $i < $n; $i++) {
        $zs[] = z_next($zs[$i - 1]);
    }
    $ids = [];
    foreach ($centres as $i => [$cx, $cy]) {
        $id = uuid_v4();
        $ids[] = $id;
        $item = ['id' => $id, 'type' => $type, 'parent_id' => $frame['id'] ?? null, 'x' => round($ox + $cx, 2), 'y' => round($oy + $cy, 2),
                 'width' => $w, 'height' => $h, 'rotation' => 0, 'z' => $zs[$i], 'style' => item_style_for($type, $spec['color'] ?? null),
                 'data' => item_data_for($type, (string) $texts[$i], $spec['shape'] ?? null), 'locked' => false];
        $ops[] = ['op' => 'create', 'item' => $item];
    }
    return ['ops' => $ops, 'where' => $where, 'ids' => $ids, 'grown' => $grown];
}

/** "Went well" for a frame row, else "the frame". */
function frame_words(array $frame): string
{
    $t = trim((string) ($frame['title'] ?? ''));
    return $t === '' ? 'the frame' : $t;
}

/** A random uuid v4 (a new item's id — items are named by the writer, db/007). */
function uuid_v4(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}
