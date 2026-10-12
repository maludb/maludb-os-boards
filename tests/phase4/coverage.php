<?php
/**
 * Proof — coverage: every tool the surface names (27 records + 7 activity) was CALLED at least once by the proofs of this run (lib.php's mcp_tool() records each call in $BD_DEV_STATE/p4-called.txt), no call named a tool the surface does not have,
 * and every question of design §7 (Q1–Q18 and A1–A6) has a tool that exists and was called, and appears in the surface's coverage table. Run last, after the others: BD_PROOFS="coverage" alone has nothing to read.
 */
require __DIR__ . '/lib.php';
$S = surface();
$called = array_count_values(array_filter(array_map('trim', file(need('BD_DEV_STATE') . '/p4-called.txt'))));
ok(count($called) > 0, 'the proofs of this run recorded their calls (' . array_sum($called) . ' calls, ' . count($called) . ' distinct tools)');
$all = array_merge($S['records'], $S['activity']);
$never = array_values(array_filter($all, fn ($t) => !isset($called[$t])));
ok(count($all) === 34 && $never === [], 'every one of the surface\'s ' . count($all) . ' tools was called at least once' . ($never ? ' — never called: ' . implode(', ', $never) : ''));
$stray = array_values(array_diff(array_keys($called), $all));
ok($stray === [], 'and no call named a tool the surface does not have' . ($stray ? ' — ' . implode(', ', $stray) : ''));
$thin = array_keys(array_filter($called, fn ($n) => $n < 2));
ok($thin === [] || count($thin) <= 3, 'every tool was called more than once (' . (count($thin) ? 'once only: ' . implode(', ', $thin) : 'all of them') . ')');
$doc = (string) file_get_contents(dirname(__DIR__, 2) . '/docs/boards-design.md');
$a7 = strpos($doc, "\n## 7."); $b7 = strpos($doc, "\n## 8.");
$sect = substr($doc, $a7, $b7 - $a7);
preg_match_all('/^\| (\d+|A\d+) \| [^|]*\| ([^|]*)\|/m', $sect, $qs, PREG_SET_ORDER);
$questions = [];
foreach ($qs as $m) { preg_match_all('/`([a-z_]+)`/', $m[2], $t); $questions[$m[1]] = $t[1]; }
ok(count($questions) === 24 && isset($questions['1'], $questions['18'], $questions['A1'], $questions['A6']), 'design §7 holds 18 record questions and 6 activity questions: ' . count($questions));
$missing = []; $uncalled = [];
foreach ($questions as $q => $tools) {
    $tools = array_values(array_diff($tools, ['board_ops']));             // a table named in a question's words, not a tool
    if ($tools === []) { $missing[] = $q; }
    foreach ($tools as $t) { if (!in_array($t, $all, true)) { $missing[] = "$q:$t"; } elseif (!isset($called[$t])) { $uncalled[] = "$q:$t"; } }
}
ok($missing === [], 'every question names tools that exist on the surface' . ($missing ? ' — ' . implode(', ', $missing) : ''));
ok($uncalled === [], 'and every one of those tools was called by a proof' . ($uncalled ? ' — ' . implode(', ', $uncalled) : ''));
$cov = (string) file_get_contents(dirname(__DIR__, 2) . '/docs/boards-mcp-tool-surface.md');
$cov = substr($cov, strpos($cov, '## Coverage'));
$absent = [];
foreach (array_keys($questions) as $q) {
    $label = ctype_digit((string) $q) ? "Q$q" : $q;
    if (!preg_match('/\b' . preg_quote($label, '/') . '\b/', $cov) && !(str_starts_with($label, 'A') && str_contains($cov, 'A1–A6'))) { $absent[] = $label; }
}
ok($absent === [], 'every question appears in the surface\'s coverage table (Q1–Q18, A1–A6)' . ($absent ? ' — missing: ' . implode(', ', $absent) : ''));
finish();
