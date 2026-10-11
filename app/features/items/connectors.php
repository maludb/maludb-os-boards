<?php
declare(strict_types=1);

/**
 * Connectors (slice 4; docs/build-specs/connectors-frames.md "The data"): Miro's names — `start_item_id` / `end_item_id` (or a free end's absolute
 * `startPoint` / `endPoint`), `data.shape` straight | elbowed | curved, `data.startSnap` / `endSnap` auto | top | right | bottom | left,
 * `data.captions` [{content, position}], `style` strokeColor, strokeWidth (1–8), strokeStyle normal | dashed | dotted, startStrokeCap /
 * endStrokeCap none | arrow | filled_triangle. The route is the island's (render only — never stored); the connector's own x, y, width,
 * height stay 0 (its box is db/007's trigger's, from its ends). The database refuses a connector to a connector, to an item of another
 * board or to an item that does not exist (23514, its sentence) — PHP builds the op and judges nothing.
 */

require_once __DIR__ . '/write.php';

const CONNECTOR_SHAPES = ['straight', 'elbowed', 'curved'];
const CONNECTOR_CAPS = ['none', 'arrow', 'filled_triangle'];
const CONNECTOR_ARROWS = ['end' => ['none', 'arrow'], 'start' => ['arrow', 'none'], 'both' => ['arrow', 'arrow'], 'none' => ['none', 'none']];

/** ONE create op joining two items: ['op' => …, 'id' => the connector, 'from' => words, 'to' => words]. $opts: shape, caption, arrow. */
function connector_ops(PDO $pdo, string $boardId, string $from, string $to, array $opts): array
{
    $words = static function (string $id) use ($pdo, $boardId): string {
        $r = one_row($pdo, "SELECT type, left(COALESCE(NULLIF(plain_text, ''), data->>'title', ''), 40) AS w FROM board_items
                             WHERE id = CAST(:i AS uuid) AND board_id = CAST(:b AS uuid) AND deleted_at IS NULL", ['i' => $id, 'b' => $boardId]);
        return $r === null ? 'an item' : (trim((string) $r['w']) !== '' ? trim((string) $r['w']) : ($r['type'] === 'sticky_note' ? 'a sticky' : 'a ' . $r['type']));
    };
    [$startCap, $endCap] = CONNECTOR_ARROWS[$opts['arrow'] ?? 'end'];
    $caption = trim((string) ($opts['caption'] ?? ''));
    $id = uuid_v4();
    $op = ['op' => 'create', 'item' => [
        'id' => $id, 'type' => 'connector', 'parent_id' => null, 'x' => 0, 'y' => 0, 'width' => 0, 'height' => 0, 'rotation' => 0,
        'z' => z_after($pdo, $boardId), 'start_item_id' => $from, 'end_item_id' => $to, 'locked' => false,
        'style' => ['strokeColor' => '#1a1a1a', 'strokeWidth' => 2, 'strokeStyle' => 'normal', 'startStrokeCap' => $startCap, 'endStrokeCap' => $endCap],
        'data' => ['shape' => $opts['shape'] ?? 'elbowed', 'startSnap' => 'auto', 'endSnap' => 'auto',
                   'captions' => $caption === '' ? [] : [['content' => $caption, 'position' => 0.5]]],
    ]];
    return ['op' => $op, 'id' => $id, 'from' => $words($from), 'to' => $words($to)];
}

/**
 * What is joined to an item (the records tool `item_connections`, over mcp_board_items — the caller's wall): {incoming: [{connector_id,
 * from_id, from_type, from_excerpt, caption}], outgoing: [{connector_id, to_id, to_type, to_excerpt, caption}]}.
 */
function item_connections(PDO $pdo, string $itemId): array
{
    $q = static function (string $mine, string $other) use ($pdo, $itemId): array {
        $st = $pdo->prepare("SELECT k.item_id AS connector_id, o.item_id AS other_id, o.type AS other_type, left(o.plain_text, 80) AS excerpt,
                                    NULLIF(COALESCE(k.data->'captions'->0->>'content', k.data->>'caption'), '') AS caption
                               FROM mcp_board_items k JOIN mcp_board_items o ON o.item_id = k.$other
                              WHERE k.type = 'connector' AND k.$mine = CAST(:i AS uuid) ORDER BY k.created_at, k.item_id");
        $st->execute(['i' => $itemId]);
        return $st->fetchAll();
    };
    return [
        'incoming' => array_map(static fn ($r): array => ['connector_id' => (string) $r['connector_id'], 'from_id' => (string) $r['other_id'],
            'from_type' => (string) $r['other_type'], 'from_excerpt' => (string) $r['excerpt'], 'caption' => $r['caption']], $q('end_item_id', 'start_item_id')),
        'outgoing' => array_map(static fn ($r): array => ['connector_id' => (string) $r['connector_id'], 'to_id' => (string) $r['other_id'],
            'to_type' => (string) $r['other_type'], 'to_excerpt' => (string) $r['excerpt'], 'caption' => $r['caption']], $q('start_item_id', 'end_item_id')),
    ];
}
