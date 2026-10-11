<?php
declare(strict_types=1);

/**
 * History — the reads (slice 7, history-versions.md "Query functions"): what changed on a board, its versions, a version's outline, who opened it.
 * Every read goes through the mcp_ views and bd_version_outline() — the database decides what the caller may see; nothing here re-implements the wall.
 */
require_once dirname(__DIR__) . '/boards/queries.php';        // board_uuid(), ago_words()

const CHANGE_PAGE = 50;
const CHANGE_SOURCES = ['web', 'agent', 'command_bar', 'restore'];
/** Item types in the words of a person, singular and plural. */
const CHANGE_NOUNS = ['sticky_note' => ['sticky', 'stickies'], 'shape' => ['shape', 'shapes'], 'text' => ['text', 'texts'], 'connector' => ['connector', 'connectors'],
                      'frame' => ['frame', 'frames'], 'drawing' => ['drawing', 'drawings'], 'image' => ['image', 'images'], 'document' => ['file', 'files']];
const CHANGE_VERBS = ['create' => 'created', 'update' => 'changed', 'delete' => 'deleted', 'restore' => 'brought back'];
/** A version's reason in words. */
const VERSION_REASONS = ['interval' => 'Autosaved', 'manual' => 'Saved', 'before_restore' => 'Before a restore', 'restore' => 'Restored', 'compaction' => 'Kept at clean-up'];

/**
 * The board's changes, newest first, one page of 50: $f = member (int|null), source (web|agent|command_bar|restore|null), since (Y-m-d|null).
 * ['rows' => …, 'total' => n, 'last_visit' => the caller's last visit to the board or null].
 */
function board_changes(PDO $pdo, string $board, array $f, int $page): array
{
    $where = ['c.board_id = CAST(:b AS uuid)'];
    $args = ['b' => $board];
    if (($f['member'] ?? null) !== null) {
        $where[] = 'c.member_id = :m';
        $args['m'] = (int) $f['member'];
    }
    if (($f['source'] ?? null) !== null) {
        $where[] = 'c.source = :s';
        $args['s'] = (string) $f['source'];
    }
    if (($f['since'] ?? null) !== null) {
        $where[] = 'c.at >= CAST(:since AS date)';
        $args['since'] = (string) $f['since'];
    }
    $w = implode(' AND ', $where);
    $total = (int) one_value($pdo, "SELECT count(*) FROM mcp_board_changes c WHERE $w", $args);
    $st = $pdo->prepare("SELECT c.seq, c.member_id, c.member_name, c.member_kind, c.source, c.summary, c.undoes_seq, c.at
                           FROM mcp_board_changes c WHERE $w ORDER BY c.seq DESC LIMIT " . CHANGE_PAGE . ' OFFSET ' . ((max(1, $page) - 1) * CHANGE_PAGE));
    $st->execute($args);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['summary'] = is_array($r['summary']) ? $r['summary'] : (json_decode((string) $r['summary'], true) ?: []);
        $r['undoes_seq'] = pg_int_array($r['undoes_seq'] ?? null);
    }
    unset($r);
    $last = one_value($pdo, 'SELECT my_last_visit FROM mcp_boards WHERE board_id = CAST(:b AS uuid)', ['b' => $board]);
    return ['rows' => $rows, 'total' => $total, 'last_visit' => $last === null ? null : (string) $last];
}

/** "created 3 stickies · changed 1 · deleted 1 frame" — the verbs from summary.counts, the nouns from summary.types (one type: on every verb; several: after a dash). */
function change_words(array $summary): string
{
    $counts = is_array($summary['counts'] ?? null) ? $summary['counts'] : [];
    $types = is_array($summary['types'] ?? null) ? $summary['types'] : [];
    $noun = static fn (string $t, int $n): string => (CHANGE_NOUNS[$t] ?? ['item', 'items'])[$n === 1 ? 0 : 1];
    $one = count($types) === 1 ? (string) array_key_first($types) : null;
    $parts = [];
    foreach (CHANGE_VERBS as $verb => $word) {
        $n = (int) ($counts[$verb] ?? 0);
        if ($n > 0) {
            $parts[] = $word . ' ' . $n . ($one !== null ? ' ' . $noun($one, $n) : '');
        }
    }
    if ($parts === []) {
        return 'changed the board';
    }
    $text = implode(' · ', $parts);
    if ($one === null && $types !== []) {
        $text .= ' — ' . implode(', ', array_map(static fn ($t, $n): string => (int) $n . ' ' . $noun((string) $t, (int) $n), array_keys($types), array_values($types)));
    }
    return $text;
}

/** The centre of the batch's box as the board URL's x and y, or null when the batch had none. */
function change_fly_to(array $summary): ?array
{
    $b = $summary['bbox'] ?? null;
    if (!is_array($b) || count($b) !== 4) {
        return null;
    }
    return ['x' => round(((float) $b[0] + (float) $b[2]) / 2, 1), 'y' => round(((float) $b[1] + (float) $b[3]) / 2, 1)];
}

/** The board's versions, newest first (mcp_board_versions) with who saved each. */
function board_versions(PDO $pdo, string $board, int $limit): array
{
    $st = $pdo->prepare('SELECT v.version_no, v.content_rev, v.reason, v.label, v.item_count, v.saved_by, m.display_name AS saved_by_name, v.created_at
                           FROM mcp_board_versions v LEFT JOIN members m ON m.id = v.saved_by
                          WHERE v.board_id = CAST(:b AS uuid) ORDER BY v.version_no DESC LIMIT ' . max(1, $limit));
    $st->execute(['b' => $board]);
    return $st->fetchAll();
}

/** One version's header row, or null. */
function find_version(PDO $pdo, string $board, int $no): ?array
{
    $st = $pdo->prepare('SELECT v.version_no, v.content_rev, v.reason, v.label, v.item_count, v.saved_by, m.display_name AS saved_by_name, v.created_at
                           FROM mcp_board_versions v LEFT JOIN members m ON m.id = v.saved_by
                          WHERE v.board_id = CAST(:b AS uuid) AND v.version_no = :n');
    $st->execute(['b' => $board, 'n' => $no]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

/** A version's outline as Markdown (bd_version_outline), or null when there is no such version or the caller cannot open the board. */
function version_outline(PDO $pdo, string $board, int $no): ?string
{
    $t = one_value($pdo, 'SELECT bd_version_outline(CAST(:b AS uuid), :n)', ['b' => $board, 'n' => $no]);
    return $t === null ? null : (string) $t;
}

/**
 * The outline's own Markdown as HTML — `#`/`##`… headings, `- ` lines, `**bold**`; everything escaped through e() first, nothing else interpreted.
 */
function outline_html(string $markdown): string
{
    $bold = static fn (string $s): string => (string) preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', e($s));
    $out = '';
    $list = false;
    foreach (preg_split('/\R/u', $markdown) ?: [] as $line) {
        $line = rtrim($line);
        if (preg_match('/^(\s*)- (.*)$/u', $line, $m)) {
            if (!$list) {
                $out .= '<ul class="list-unstyled mb-2">';
                $list = true;
            }
            $out .= '<li class="ms-' . min(4, intdiv(strlen($m[1]), 2) * 2) . '">' . $bold($m[2]) . '</li>';
            continue;
        }
        if ($list) {
            $out .= '</ul>';
            $list = false;
        }
        if (preg_match('/^(#{1,6})\s+(.*)$/u', $line, $m)) {
            $level = min(6, strlen($m[1]) + 3);               // # → h4: the page owns h1–h3
            $out .= '<h' . $level . ' class="fs-' . ($level === 4 ? '15' : '13') . ' fw-semibold mt-3 mb-1">' . $bold($m[2]) . '</h' . $level . '>';
        } elseif ($line !== '') {
            $out .= '<p class="mb-1">' . $bold($line) . '</p>';
        }
    }
    return $out . ($list ? '</ul>' : '');
}

/**
 * Who opened the board (and who edited it) in the last 30 days, from the local activity log: one row per member — name, kind, opens, edits, last at.
 * $since is a timestamp (default: 30 days ago).
 */
function board_trail(PDO $pdo, string $board, ?string $since, int $limit): array
{
    $st = $pdo->prepare("SELECT l.actor_member_id AS member_id, COALESCE(m.display_name, 'Someone who has since left') AS name, COALESCE(m.member_kind, 'human') AS kind,
                                count(*) FILTER (WHERE l.action = 'board.open') AS opens, count(*) FILTER (WHERE l.action LIKE 'item.%') AS edits, max(l.occurred_at) AS last_at
                           FROM mcp_activity_log l LEFT JOIN mcp_members m ON m.member_id = l.actor_member_id
                          WHERE l.board_id = CAST(:b AS uuid) AND l.actor_member_id IS NOT NULL AND (l.action = 'board.open' OR l.action LIKE 'item.%')
                            AND l.occurred_at >= COALESCE(CAST(:since AS timestamptz), now() - interval '30 days')
                          GROUP BY l.actor_member_id, m.display_name, m.member_kind
                          ORDER BY max(l.occurred_at) DESC LIMIT " . max(1, $limit));
    $st->execute(['b' => $board, 'since' => $since]);
    return $st->fetchAll();
}
