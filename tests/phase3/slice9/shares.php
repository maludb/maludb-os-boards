<?php
/**
 * Slice 9 — the two K7 shares' SQL (agents-search.md "The two K7 shares", "Proof" 8), run as the Facilitator on the records role (the MCP server's own login) with app.member_id the agent the
 * kernel names in X-OS-Consumer-Agent: `boards_index` lists exactly the boards shared with it, no template, with their folders; `board_outline` of a board it cannot see is NULL.
 * Run through tests/phase3/slice9/run.sh.
 */
require __DIR__ . '/lib.php';
[$jp] = sign_on(26);

echo "8. The shares' SQL as the Facilitator\n";
$rec = new PDO(sprintf('pgsql:host=%s;port=%s;dbname=%s', need('DB_HOST'), need('DB_PORT'), need('DB_NAME')), need('MCP_RECORDS_DB_USER'), need('MCP_RECORDS_DB_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$as = static function (PDO $c, int $m): void { $c->exec("SELECT set_config('app.member_id', '$m', false)"); };
$x = make_board($jp, 'SMOKE Share index X');
$y = make_board($jp, 'SMOKE Share index Y');
$z = make_board($jp, 'SMOKE Share outline Z');
share_as(26, $x, 'agent', FACILITATOR, 'view');
share_as(26, $z, 'agent', FACILITATOR, 'comment');
ops_post($jp, $x, [item_op(['text' => 'Outline sticky seen by the agent', 'x' => 0, 'y' => 0, 'z' => 'a1'])]);
ops_post($jp, $y, [item_op(['text' => 'Hidden sticky', 'x' => 0, 'y' => 0, 'z' => 'a1'])]);
$fold = (int) body_json(act($jp, '/folders/save.php', ['name' => 'SMOKE Shared folder', 'team' => 'yes']))['record_id'];
post_as($jp, '/folders/add.php', ['folder' => $fold, 'board' => $x]);
save_template($jp, $x, ['title' => 'SMOKE Index template', 'category' => 'other']);
$deleted = make_board($jp, 'SMOKE Share deleted');
share_as(26, $deleted, 'agent', FACILITATOR, 'edit');
post_as($jp, '/boards/delete.php', ['board' => $deleted]);

$index = "SELECT b.board_id, b.title, b.owner_name, b.item_count, b.last_activity_at AS updated_at,
                 (SELECT COALESCE(array_agg(f.name ORDER BY f.name), '{}') FROM mcp_folder_entries e JOIN mcp_folders f ON f.folder_id = e.folder_id WHERE e.board_id = b.board_id) AS folder_names
            FROM mcp_boards b WHERE NOT b.is_template AND (CAST(:q AS text) IS NULL OR b.title ILIKE '%' || CAST(:q AS text) || '%') ORDER BY b.last_activity_at DESC LIMIT :limit";
$run = static function (PDO $c, ?string $q, int $limit = 50) use ($index): array { $st = $c->prepare($index); $st->execute(['q' => $q, 'limit' => $limit]); return $st->fetchAll(); };
foreach ([['the application role', pdo()], ['the records role', $rec]] as [$label, $c]) {
    $as($c, FACILITATOR);
    $rows = $run($c, null);
    $got = array_column($rows, 'board_id');
    sort($got);
    $want = array_column(q("SELECT b.id FROM boards b WHERE b.deleted_at IS NULL AND NOT b.is_template AND bd_board_level(b.id, :a) <> 'none'", ['a' => FACILITATOR]), 'id');
    sort($want);
    ok($got === $want && in_array($x, $got, true) && in_array($z, $got, true) && !in_array($y, $got, true) && !in_array($deleted, $got, true), "boards_index ($label): exactly the boards the agent can open (" . count($got) . "), not Y, not the deleted one");
    ok(array_filter($rows, static fn ($r) => str_starts_with((string) $r['title'], 'SMOKE Index template')) === [], "boards_index ($label): no template, though the agent could open the business's");
    $rx = array_values(array_filter($rows, static fn ($r) => $r['board_id'] === $x))[0];
    ok($rx['title'] === 'SMOKE Share index X' && $rx['owner_name'] === 'SMOKE Priya' && (int) $rx['item_count'] === 1 && $rx['updated_at'] !== null && $rx['folder_names'] === '{"SMOKE Shared folder"}', "boards_index ($label): title, owner, item count, updated, folder names");
    $f = $run($c, 'outline');
    ok(array_column($f, 'board_id') === [$z], "boards_index ($label): q filters by title");
    ok(count($run($c, null, 1)) === 1, "boards_index ($label): limit");
}
$as($rec, FACILITATOR);
$o = $rec->prepare('SELECT bd_board_outline(CAST(:b AS uuid))');
$o->execute(['b' => $x]);
$text = (string) $o->fetchColumn();
ok(str_contains($text, 'SMOKE Share index X') && str_contains($text, 'Outline sticky seen by the agent') && strlen($text) < 262144, 'board_outline of a board it can see: the outline text, under 256 kB');
$o->execute(['b' => $y]);
ok($o->fetchColumn() === null, 'board_outline of a board it cannot see: NULL (not_found)');
$o->execute(['b' => uuid4()]);
ok($o->fetchColumn() === null, 'board_outline of a board that is not there: NULL');
$as($rec, EXPERT);
$o->execute(['b' => $x]);
ok($o->fetchColumn() === null, 'another agent (the Expert, shared with nothing here) gets NULL for it');
$as($rec, 41);
ok(array_column($run($rec, null), 'board_id') === [], 'an agent that is not admitted to Boards (the Watcher) lists nothing');
finish();
