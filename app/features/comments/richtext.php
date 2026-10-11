<?php
declare(strict_types=1);

/**
 * Comment text <-> rich-text runs (comments-notifications.md "Divergences" 1): the composer writes plain text with @mentions; the server turns it
 * into the stored runs (text runs and mention runs only — no bold, no links; a URL stays text and is linked when rendered).
 */

const COMMENT_MAX_CHARS = 4000;

/** One plain text run, in bd_rich_text()'s shape (db/006). */
function comment_text_run(string $s): array
{
    return ['type' => 'text', 'text' => ['content' => $s, 'link' => null],
            'annotations' => ['bold' => false, 'italic' => false, 'strikethrough' => false, 'underline' => false, 'code' => false, 'color' => 'default'],
            'plain_text' => $s, 'href' => null];
}

/** A mention run: type member (a person), agent or department; the name as the directory writes it. */
function comment_mention_run(string $type, int $id, string $name): array
{
    return ['type' => 'mention', 'mention' => ['type' => $type, 'id' => $id, 'name' => $name], 'plain_text' => '@' . $name];
}

/**
 * Split on "@" followed by the longest display name (case-insensitive, ending at a word boundary) of a person or agent in mcp_members or a
 * department in mcp_departments, as the current app.member_id sees them. A name two records share (two people, or a person and a
 * department) is never guessed: it stays text and is named in 'unresolved'. Answers ['runs' => […], 'unresolved' => [names]].
 */
function comment_text_to_rich(PDO $pdo, string $text): array
{
    $names = [];
    if (str_contains($text, '@')) {
        foreach ($pdo->query('SELECT member_id, display_name, member_kind FROM mcp_members')->fetchAll() as $m) {
            $names[mb_strtolower(trim((string) $m['display_name']))][] = [$m['member_kind'] === 'agent' ? 'agent' : 'member', (int) $m['member_id'], (string) $m['display_name']];
        }
        foreach ($pdo->query('SELECT department_id, name FROM mcp_departments WHERE archived_at IS NULL')->fetchAll() as $d) {
            $names[mb_strtolower(trim((string) $d['name']))][] = ['department', (int) $d['department_id'], (string) $d['name']];
        }
        unset($names['']);
        uksort($names, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
    }
    $runs = [];
    $unresolved = [];
    $buf = '';
    $flush = static function () use (&$runs, &$buf): void {
        if ($buf !== '') {
            $runs[] = comment_text_run($buf);
            $buf = '';
        }
    };
    $len = strlen($text);
    $i = 0;
    while ($i < $len) {
        $c = $text[$i];
        if ($c === '@' && $names !== [] && ($i === 0 || !preg_match('/^[\p{L}\p{N}_]$/u', mb_substr(substr($text, 0, $i), -1)))) {
            $tail = substr($text, $i + 1, 400);
            $hit = null;
            foreach ($names as $lower => $who) {
                $cut = mb_substr($tail, 0, mb_strlen($lower));
                if (mb_strtolower($cut) !== $lower) {
                    continue;
                }
                $after = substr($tail, strlen($cut), 4);
                if ($after !== '' && preg_match('/^[\p{L}\p{N}_]/u', $after)) {
                    continue;                                   // "@Bea" must not take "@Beatrice"'s front
                }
                $hit = [$who, strlen($cut)];
                break;
            }
            if ($hit !== null) {
                [$who, $n] = $hit;
                if (count($who) === 1) {
                    $flush();
                    $runs[] = comment_mention_run($who[0][0], $who[0][1], $who[0][2]);
                } else {
                    $unresolved[] = $who[0][2];
                    $buf .= substr($text, $i, 1 + $n);
                }
                $i += 1 + $n;
                continue;
            }
        }
        $buf .= $c;
        $i++;
    }
    $flush();
    return ['runs' => $runs, 'unresolved' => array_values(array_unique($unresolved))];
}

/** The mentions in a run list, counted by kind: ['member' => n, 'agent' => n, 'department' => n]. */
function comment_mention_counts(array $runs): array
{
    $c = ['member' => 0, 'agent' => 0, 'department' => 0];
    foreach ($runs as $r) {
        if (($r['type'] ?? '') === 'mention' && isset($c[$r['mention']['type'] ?? ''])) {
            $c[$r['mention']['type']]++;
        }
    }
    return $c;
}

/** The runs as HTML: text escaped (line breaks kept, http(s):// linked with rel="noopener"), a mention a chip. */
function rich_to_html(array $runs): string
{
    $out = '';
    foreach ($runs as $r) {
        if (($r['type'] ?? '') === 'mention') {
            $t = (string) ($r['mention']['type'] ?? 'member');
            $out .= '<span class="badge bg-soft-' . ($t === 'agent' ? 'info' : ($t === 'department' ? 'secondary' : 'primary')) . ' text-' . ($t === 'agent' ? 'info' : ($t === 'department' ? 'secondary' : 'primary'))
                . ' comment-mention" data-mention="' . e($t) . '">@' . e((string) ($r['mention']['name'] ?? '')) . '</span>';
            continue;
        }
        $s = e((string) ($r['plain_text'] ?? $r['text']['content'] ?? ''));
        $s = preg_replace_callback('~https?://[^\s<]+~i', static function (array $m): string {
            $url = $m[0];
            $tail = '';
            while ($url !== '' && preg_match('/[.,;:!?)]$|&quot;$|&#039;$/', $url, $t)) {
                $tail = $t[0] . $tail;
                $url = substr($url, 0, -strlen($t[0]));
            }
            return '<a href="' . $url . '" target="_blank" rel="noopener">' . $url . '</a>' . $tail;
        }, $s);
        $out .= nl2br($s, false);
    }
    return $out;
}
