<?php
declare(strict_types=1);

/**
 * Frames (slice 4; docs/build-specs/connectors-frames.md "The data"): `data.title`, `style.fillColor`, rotation 0, top-level — the database
 * refuses a nested or a rotated frame. A child's x, y are its centre relative to the frame's top-left (slice 2's rule); entering a frame is
 * `update {parent_id, x, y}` in the same batch.
 */

require_once __DIR__ . '/write.php';           // the items helpers (and through them the layout engine)

const FRAME_PAD = 40.0;                 // around the items it wraps — and 40 more on top for its title (decision 4)
const FRAME_DEFAULT = [1200.0, 800.0];

/**
 * The frame's create and, with $around, its adoptions — ONE batch: ['ops' => …, 'id' => the frame, 'adopted' => n, 'where' => words].
 * $around: item ids (a frame among them is refused "Frames do not nest"; a connector follows its ends and is not adopted); the frame's box is
 * their union + 40 on every side + 40 more on top. Without $around: $box = [x, y, width, height] (centre and size), else empty space below the
 * board (slice 2's rule, reconciled: below, never right of, the extent).
 */
function frame_ops(PDO $pdo, string $boardId, string $title, array $around, ?array $box): array
{
    $id = uuid_v4();
    $adopt = [];
    if ($around !== []) {
        $rows = items_require_on_board($pdo, $boardId, $around);
        foreach ($rows as $r) {
            if ($r['type'] === 'frame') {
                throw new DomainException('Frames do not nest: ' . $r['id'] . ' is a frame.');
            }
        }
        $rows = array_filter($rows, static fn ($r): bool => $r['type'] !== 'connector');
        if ($rows === []) {
            throw new DomainException('There is nothing to put in the frame: give items, not connectors.');
        }
        $x1 = min(array_map(static fn ($r) => (float) $r['x1'], $rows)) - FRAME_PAD;
        $y1 = min(array_map(static fn ($r) => (float) $r['y1'], $rows)) - 2 * FRAME_PAD;
        $x2 = max(array_map(static fn ($r) => (float) $r['x2'], $rows)) + FRAME_PAD;
        $y2 = max(array_map(static fn ($r) => (float) $r['y2'], $rows)) + FRAME_PAD;
        [$w, $h, $cx, $cy] = [$x2 - $x1, $y2 - $y1, ($x1 + $x2) / 2, ($y1 + $y2) / 2];
        foreach ($rows as $r) {
            $adopt[] = ['op' => 'update', 'id' => (string) $r['id'], 'fields' => ['parent_id' => $id,
                'x' => round(((float) $r['x1'] + (float) $r['x2']) / 2 - $x1, 2), 'y' => round(((float) $r['y1'] + (float) $r['y2']) / 2 - $y1, 2)]];
        }
        $where = 'around ' . count($adopt) . ' item' . (count($adopt) === 1 ? '' : 's');
    } elseif ($box !== null) {
        [$cx, $cy, $w, $h] = [(float) $box[0], (float) $box[1], (float) $box[2], (float) $box[3]];
        $where = 'at ' . round($cx) . ', ' . round($cy);
    } else {
        [$w, $h] = FRAME_DEFAULT;
        $ext = one_row($pdo, "SELECT min((bbox[1])[0]) AS x1, max((bbox[0])[1]) AS y2 FROM board_items WHERE board_id = CAST(:b AS uuid) AND deleted_at IS NULL HAVING count(*) > 0",
            ['b' => $boardId]);
        $cx = ($ext === null ? 0.0 : (float) $ext['x1']) + $w / 2;
        $cy = ($ext === null ? 0.0 : (float) $ext['y2'] + EMPTY_SPACE_DROP) + $h / 2;
        $where = $ext === null ? 'on the empty board' : 'below the board';
    }
    $create = ['op' => 'create', 'item' => ['id' => $id, 'type' => 'frame', 'parent_id' => null, 'x' => round($cx, 2), 'y' => round($cy, 2),
        'width' => round($w, 2), 'height' => round($h, 2), 'rotation' => 0, 'z' => z_after($pdo, $boardId), 'style' => ['fillColor' => '#ffffff'],
        'data' => ['title' => $title], 'locked' => false]];
    return ['ops' => array_merge([$create], $adopt), 'id' => $id, 'adopted' => count($adopt), 'where' => $where];
}

/** The board's frames in reading order — top to bottom, then left to right — with their counts (bd_board_overview()'s `frames`). */
function frames_in_order(PDO $pdo, string $boardId): array
{
    $o = json_decode((string) (one_value($pdo, 'SELECT bd_board_overview(CAST(:b AS uuid))', ['b' => $boardId]) ?? 'null'), true);
    return is_array($o) ? ($o['frames'] ?? []) : [];
}
