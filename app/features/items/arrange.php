<?php
declare(strict_types=1);

/**
 * Arrange (slice 9; `items_arrange`, agents-search.md): the layout engine of slice 2 (bd_layout), turned on items that are already there. ONE batch of `update` ops
 * through bd_commit_ops() — the level, the lock, the cap and the inverse are the database's; this builds the positions and nothing it judges.
 *  - cells are the largest width and height among the items; grid = `columns` (default ⌈√n⌉) in the given order, column, row; cluster = the items grouped by style.fillColor
 *    (no colour = a group of its own) in order of first appearance, each group a grid of ⌈√k⌉ columns, the groups side by side 2 × gap apart;
 *  - with a frame: the block starts at (40, 40) of the frame, the items enter it (parent_id) and the frame grows in the same batch to the block plus 40 on every side when it
 *    does not hold it (its top-left fixed); without: the items must share one parent and the block starts at their present top-left.
 */
require_once __DIR__ . '/write.php';
require_once __DIR__ . '/layout.php';

const ARRANGE_PAD = 40.0;
const ARRANGE_GAP = 40.0;
const ARRANGE_LAYOUTS = ['grid', 'column', 'row', 'cluster'];
const ARRANGE_MAX = 500;

/**
 * The cells' centres (block top-left (0, 0)) and the block's size for $layout over items of the given order. $fills: each item's fill colour or null.
 * Answers ['centres' => [[x, y], …] in the items' order, 'width' => w, 'height' => h].
 */
function arrange_block(string $layout, array $fills, float $cw, float $ch, float $gap, ?int $columns): array
{
    $n = count($fills);
    if ($layout !== 'cluster') {
        $opts = ['width' => $cw, 'height' => $ch, 'gap' => $gap] + ($columns !== null ? ['columns' => $columns] : []);
        [$w, $h] = bd_layout_size($layout, $n, $opts);
        return ['centres' => bd_layout($layout, $n, $opts), 'width' => $w, 'height' => $h];
    }
    $groups = [];
    foreach ($fills as $i => $fill) {
        $groups[$fill ?? "\0own" . $i][] = $i;                  // first appearance keeps the order
    }
    $centres = [];
    $ox = 0.0;
    $height = 0.0;
    foreach ($groups as $members) {
        $k = count($members);
        $opts = ['width' => $cw, 'height' => $ch, 'gap' => $gap, 'columns' => (int) ceil(sqrt($k))];
        [$gw, $gh] = bd_layout_size('grid', $k, $opts);
        foreach (bd_layout('grid', $k, $opts) as $j => [$cx, $cy]) {
            $centres[$members[$j]] = [$ox + $cx, $cy];
        }
        $ox += $gw + 2 * $gap;
        $height = max($height, $gh);
    }
    ksort($centres);
    return ['centres' => array_values($centres), 'width' => $ox - 2 * $gap, 'height' => $height];
}

/**
 * Arrange $itemIds (their order is the layout's) on $board as $by. Answers bd_commit_ops()'s answer (seq, duplicate, …, inverse, summary) plus 'arranged' (count) and
 * 'grown' (the frame was made bigger). A frame or a connector among the items, items under two parents with no frame named, or a frame that is not there is a
 * DomainException (422); an item that is not on the board is a 404.
 */
function arrange_items(PDO $pdo, string $board, int $by, array $itemIds, string $layout, ?string $frame, ?int $columns, float $gap, string $source, ?int $runId): array
{
    if (!in_array($layout, ARRANGE_LAYOUTS, true)) {
        throw new DomainException('The layout is one of: ' . implode(', ', ARRANGE_LAYOUTS) . '.');
    }
    $itemIds = array_values(array_unique(array_map('strtolower', $itemIds)));
    if ($itemIds === [] || count($itemIds) > ARRANGE_MAX) {
        throw new DomainException('Name 1 to ' . ARRANGE_MAX . ' items to arrange.');
    }
    $rows = items_require_on_board($pdo, $board, $itemIds);
    foreach ($rows as $r) {
        if (in_array($r['type'], ['frame', 'connector'], true)) {
            throw new DomainException('Arrange moves items, not frames or connectors');
        }
    }
    $fillOf = $pdo->prepare('SELECT id, style->>\'fillColor\' AS fill FROM board_items WHERE board_id = CAST(:b AS uuid) AND id = ANY (CAST(:ids AS uuid[]))');
    $fillOf->execute(['b' => $board, 'ids' => pg_array_literal($itemIds)]);
    $fillById = array_column($fillOf->fetchAll(), 'fill', 'id');
    $fills = array_map(static fn (string $id): ?string => ($fillById[$id] ?? null) === null || $fillById[$id] === '' ? null : (string) $fillById[$id], $itemIds);
    $cw = max(array_map(static fn (array $r): float => (float) $r['width'], $rows));
    $ch = max(array_map(static fn (array $r): float => (float) $r['height'], $rows));
    $block = arrange_block($layout, $fills, $cw, $ch, $gap, $columns);

    $ops = [];
    $grown = false;
    if ($frame !== null) {
        $f = one_row($pdo, "SELECT id, x, y, width, height FROM board_items WHERE id = CAST(:f AS uuid) AND board_id = CAST(:b AS uuid) AND type = 'frame' AND deleted_at IS NULL",
            ['f' => preg_match('/^[0-9a-f-]{36}$/', strtolower($frame)) ? strtolower($frame) : '00000000-0000-0000-0000-000000000000', 'b' => $board]);
        if ($f === null) {
            throw new DomainException('There is no frame ' . $frame . ' on this board.');
        }
        [$ox, $oy, $parent] = [ARRANGE_PAD, ARRANGE_PAD, (string) $f['id']];
        $needW = max((float) $f['width'], $block['width'] + 2 * ARRANGE_PAD);
        $needH = max((float) $f['height'], $block['height'] + 2 * ARRANGE_PAD);
        if ($needW > (float) $f['width'] || $needH > (float) $f['height']) {
            $ops[] = ['op' => 'update', 'id' => $parent, 'fields' => ['x' => round((float) $f['x'] + ($needW - (float) $f['width']) / 2, 2),
                'y' => round((float) $f['y'] + ($needH - (float) $f['height']) / 2, 2), 'width' => $needW, 'height' => $needH]];
            $grown = true;
        }
    } else {
        $parents = array_unique(array_map(static fn (array $r): string => (string) ($r['parent_id'] ?? ''), $rows));
        if (count($parents) > 1) {
            throw new DomainException('Name a frame, or arrange items that share one');
        }
        $parent = ($rows[$itemIds[0]]['parent_id'] ?? null) === null ? null : (string) $rows[$itemIds[0]]['parent_id'];
        $ox = min(array_map(static fn (array $r): float => (float) $r['x'] - (float) $r['width'] / 2, $rows));
        $oy = min(array_map(static fn (array $r): float => (float) $r['y'] - (float) $r['height'] / 2, $rows));
    }
    foreach ($itemIds as $i => $id) {
        $fields = ['x' => round($ox + $block['centres'][$i][0], 2), 'y' => round($oy + $block['centres'][$i][1], 2)];
        if ($frame !== null && (string) ($rows[$id]['parent_id'] ?? '') !== $parent) {
            $fields['parent_id'] = $parent;
        }
        $ops[] = ['op' => 'update', 'id' => $id, 'fields' => $fields];
    }
    $answer = commit_ops($pdo, $board, $by, $source, null, items_batch_id(), $ops, $runId, agent_run_request_id() ?? request_id());
    return $answer + ['arranged' => count($itemIds), 'grown' => $grown];
}
