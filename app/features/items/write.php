<?php
declare(strict_types=1);

/**
 * The agents' item actions' helpers (canvas.md "The agents' item actions"; canvas-core.md "The doors (PHP)"): the words an agent may use
 * turned into ops — colours, shapes, text as rich-text runs, the batch's idempotency key, the source — and committed by commit_ops()
 * (app/features/canvas/write.php), the same bd_commit_ops() the canvas's own door calls. PHP builds ops; the database judges them.
 */

require_once __DIR__ . '/layout.php';

/** Miro's sticky-note colours (its REST API's fillColor names) and the hex the canvas paints them in. */
const STICKY_COLORS = [
    'gray' => '#f5f6f8', 'light_yellow' => '#fff9b1', 'yellow' => '#f5d128', 'orange' => '#ff9d48', 'light_green' => '#d5f692',
    'green' => '#c9df56', 'dark_green' => '#93d275', 'cyan' => '#67c6c0', 'light_pink' => '#ffcee0', 'pink' => '#ea94bb',
    'violet' => '#c6a2d2', 'red' => '#f0939d', 'light_blue' => '#a6ccf5', 'blue' => '#6cd8fa', 'dark_blue' => '#9ea9ff', 'black' => '#000000',
];
/** The fills and text colours the Properties panel offers for shapes and text (any #rrggbb is accepted). */
const ITEM_PALETTE = ['#ffffff', '#1a1a1a', '#e6e6e6', '#fef445', '#fac710', '#f24726', '#da0063', '#9510ac', '#652cb3', '#2d9bf0', '#0ca789', '#8fd14f'];
/** The pen's colours (slice 5: the pen menu, a drawing's Properties) — hex => words. */
const PEN_COLORS = ['#1a1a1a' => 'Black', '#e03131' => 'Red', '#f08c00' => 'Orange', '#ffd43b' => 'Yellow', '#2f9e44' => 'Green', '#1971c2' => 'Blue',
                    '#7048e8' => 'Violet', '#e64980' => 'Pink'];
/** Miro's ten basic shapes, by its names. */
const SHAPE_KINDS = ['rectangle', 'round_rectangle', 'ellipse', 'triangle', 'diamond', 'parallelogram', 'hexagon', 'star', 'right_arrow', 'wedge_round_rectangle_callout'];
/** The text sizes the Properties panel offers: S M L XL. */
const TEXT_SIZES = ['S' => 14, 'M' => 18, 'L' => 24, 'XL' => 36];
const ITEM_TYPES_DRAWN = ['sticky_note', 'shape', 'text'];

/** A plain text as rich-text runs — the shape bd_rich_text() (db/006) makes, so the database's plain_text and the client agree. '' → []. */
function bd_rich_text_runs(string $text): array
{
    if ($text === '') {
        return [];
    }
    return [['type' => 'text', 'text' => ['content' => $text, 'link' => null],
             'annotations' => ['bold' => false, 'italic' => false, 'strikethrough' => false, 'underline' => false, 'code' => false, 'color' => 'default'],
             'plain_text' => $text, 'href' => null]];
}

/** The style patch a colour word makes for an item type, or null when the word is not a colour for it. */
function item_color(string $type, string $color): ?array
{
    $c = strtolower(trim($color));
    $hex = preg_match('/^#[0-9a-f]{6}$/', $c) ? $c : (STICKY_COLORS[$c] ?? null);
    return match ($type) {
        'sticky_note' => isset(STICKY_COLORS[$c]) ? ['fillColor' => $c] : null,
        'shape', 'frame' => $hex === null ? null : ['fillColor' => $hex],
        'text' => $hex === null ? null : ['textColor' => $hex],
        default => null,
    };
}

/** A new item's style: its colour, else the type's default. */
function item_style_for(string $type, ?string $color): array
{
    $style = match ($type) {
        'sticky_note' => ['fillColor' => 'light_yellow'],
        'shape' => ['fillColor' => '#ffffff', 'borderColor' => '#1a1a1a'],
        default => [],
    };
    if ($color !== null && $color !== '') {
        $style = (item_color($type, $color) ?? []) + $style;
    }
    return $style;
}

/** A new item's data: its text, and a shape's kind. */
function item_data_for(string $type, string $text, ?string $shape): array
{
    $data = ['content' => bd_rich_text_runs($text)];
    if ($type === 'shape') {
        $data['shape'] = $shape !== null && $shape !== '' ? $shape : 'rectangle';
    }
    return $data;
}

/** Who committed: 'agent' (a run token), 'command_bar' (a person's action token — the command bar), 'web' (a session). */
function items_source(): string
{
    if (is_action_authed()) {
        return current_agent_run_id() !== null ? 'agent' : 'command_bar';
    }
    return 'web';
}

/**
 * The batch's idempotency key: a uuid from md5 of the request id, the action's path and its parameters — so the kernel retrying a call
 * commits it once. (canvas-core decision 6 named the request id alone; every call of one agent run carries the run's request id, so two
 * different calls in a turn would have answered "duplicate" — the path and the parameters keep them apart. Two identical calls in one turn
 * commit once, which is what a retry is.)
 */
function items_batch_id(): string
{
    $params = $_POST;
    unset($params['csrf_token']);
    ksort($params);
    $h = md5((agent_run_request_id() ?? request_id()) . '|' . (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '') . '|'
        . json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20, 12);
}

/** The live items of a board among $ids, by id (type, parent, centre, size, the absolute box). Ids not on the board are left out. */
function items_on_board(PDO $pdo, string $boardId, array $ids): array
{
    if ($ids === []) {
        return [];
    }
    $st = $pdo->prepare('SELECT id, type, parent_id, x, y, width, height, locked, (bbox[1])[0] AS x1, (bbox[1])[1] AS y1, (bbox[0])[0] AS x2, (bbox[0])[1] AS y2
                          FROM board_items WHERE board_id = CAST(:b AS uuid) AND deleted_at IS NULL AND id = ANY (CAST(:ids AS uuid[]))');
    $st->execute(['b' => $boardId, 'ids' => pg_array_literal($ids)]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[(string) $r['id']] = $r;
    }
    return $out;
}

/** Every id is a live item on the board, else a 404 naming the first that is not (canvas.md: "P0002 → 404 with the id"). */
function items_require_on_board(PDO $pdo, string $boardId, array $ids): array
{
    foreach ($ids as $id) {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id)) {
            refuse(404, 'Item ' . $id . ' is not on this board.');
        }
    }
    $rows = items_on_board($pdo, $boardId, $ids);
    foreach ($ids as $id) {
        if (!isset($rows[$id])) {
            refuse(404, 'Item ' . $id . ' is not on this board.');
        }
    }
    return $rows;
}

/** "5 stickies", "a shape", "2 items" — for the did sentence. */
function items_words(int $n, string $type): string
{
    $one = ['sticky_note' => 'sticky', 'shape' => 'shape', 'text' => 'text', 'frame' => 'frame', 'connector' => 'connector'][$type] ?? 'item';
    $many = ['sticky_note' => 'stickies', 'shape' => 'shapes', 'text' => 'texts', 'frame' => 'frames', 'connector' => 'connectors'][$type] ?? 'items';
    return $n === 1 ? 'a ' . $one : $n . ' ' . $many;
}
