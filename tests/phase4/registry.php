<?php
/**
 * Proof — the registry wrapper and the manifest, against the KERNEL's own code (read only): deploy/kernel-registry-boards.json + mcp/action_registry.json, composed as the installer writes mcp/registries/boards.json — each built action carrying
 * the tool_name K55 gives it (the kernel's own assign_registry_tool_names(), against the registries installed beside it) — are loaded by the kernel's loader (application_actions.load_registries) and registered on a FastMCP exactly as the kernel's actions
 * server does (application_actions.register): 45 built actions, a tool each, approval categories the kernel's policies know, the `confirmed` guard on destructive ones; every resolve entity is answered by THIS application's live records server through
 * the kernel's own resolver (application_actions.resolve_via_mcp) with the caller's token; and whole actions are driven through the kernel's tool (the approval hook and the handler stubbed) — then the posts the kernel WOULD make are replayed against
 * the real handlers, as an agent under its run token. The three known kernel behaviours are proved around, not fixed. The kernel's python runs the loader; nothing of the kernel is written.
 */
require __DIR__ . '/lib.php';
require_once dirname(__DIR__) . '/phase3/slice9/lib.php';
$w = p4_world();
$root = dirname(__DIR__, 2);
$S = surface();
$wrapper = json_decode((string) file_get_contents($root . '/deploy/kernel-registry-boards.json'), true);
$reg = json_decode((string) file_get_contents($root . '/mcp/action_registry.json'), true);
$manifest = json_decode((string) file_get_contents($root . '/maludb-os.json'), true);
$actions = $reg['actions'];
$KNOWN_CATEGORIES = ['money_out', 'deletion', 'external_send', 'other'];      // the kernel's approval_policies CHECK and bin/app_install.php's mapping

echo "1. The wrapper, the registry and the manifest read right\n";
ok(($wrapper['schema'] ?? '') === 'maludb-os.registry/1' && $wrapper['app_key'] === 'boards' && count($wrapper['resolve']) === 5 && $reg['generated_from'] !== null, 'the wrapper: schema maludb-os.registry/1, app_key boards, five resolve entities');
$php = (int) shell_exec('cd ' . escapeshellarg($root) . ' && php bin/build_action_registry.php --check >/dev/null 2>&1; echo $?');
ok($php === 0, 'the registry is current with the action manifest (bin/build_action_registry.php --check)');
$built = array_filter($actions, fn ($a) => !empty($a['built']));
ok(count($actions) === 45 && count($built) === 45 && count(array_filter($reg['screens'], fn ($s) => !empty($s['built']))) === 26, 'the registry holds 45 actions, every one built (and 26 screens)');
$cats = array_unique(array_filter(array_column($actions, 'approval')));
$withCat = count(array_filter($actions, fn ($a) => $a['approval'] !== null));
ok(count(array_diff($cats, $KNOWN_CATEGORIES)) === 0 && $withCat === 12, 'every approval category is one the kernel knows (' . implode(', ', $cats) . '); ' . $withCat . ' actions carry one');
$missing = [];
foreach ($actions as $k => $a) { $p = $root . '/html' . parse_url($a['endpoint'], PHP_URL_PATH); if (!is_file($p)) { $missing[] = $k . ' → ' . $a['endpoint']; } }
ok($missing === [], 'every action\'s endpoint exists under html/' . ($missing ? ' — missing: ' . implode(', ', array_slice($missing, 0, 5)) : ''));
$phantom = [];
foreach ($actions as $k => $a) { foreach ($a['params'] as $p) { if (($p['name'] ?? null) === null) { $phantom[] = $k; } } }
ok($phantom === [], 'no action carries a phantom parameter (a comma or semicolon inside a note splits a manifest cell)' . ($phantom ? ' — ' . implode(', ', array_unique($phantom)) : ''));
$noLog = array_filter($actions, fn ($a) => empty($a['log_event']));
ok($noLog === [], 'every action names the log event the approval hook asks about');
$dupe = array_diff_key(array_column($actions, 'action'), array_unique(array_column($actions, 'action')));
ok($dupe === [] && count(array_unique(array_keys($actions))) === 45, 'no two actions share a name');

echo "2. Every resolve entity: its tool exists on the records server, answers the plain list\n";
foreach ($wrapper['resolve'] as $entity => $spec) {
    ok(in_array($spec['tool'], $S['records'], true) && ($spec['query_param'] ?? '') === 'q' && !empty($spec['id_field']) && !empty($spec['label_field']) && !empty($spec['params']), "$entity: tool {$spec['tool']} exists on the records server; id {$spec['id_field']}, label {$spec['label_field']}, params " . implode(', ', $spec['params']));
}
$pm = [];
foreach ($wrapper['resolve'] as $e => $spec) { foreach ($spec['params'] as $p) { $pm[$p][] = $e; } }
ok(count(array_filter($pm, fn ($e) => count($e) > 1)) === 0, 'no param is claimed by two entities');
$used = [];
foreach ($actions as $k => $a) { foreach ($a['params'] as $p) { $n = $p['name'] ?? null; if ($n !== null && isset($pm[$n])) { $used[$n][] = $k; } } }
ok(count($used) >= 6 && isset($used['board'], $used['template'], $used['folder'], $used['member'], $used['department'], $used['new_owner']), 'the resolved params are really used by actions (' . implode(', ', array_keys($used)) . ')');
$idsOnly = ['item', 'items', 'comment', 'version', 'notification', 'token', 'dispatch', 'from', 'to', 'around', 'context', 'frame'];
ok(count(array_intersect($idsOnly, array_keys($pm))) === 0, 'the ids-only params (item, items, comment, version, notification, token, dispatch, from, to, around, context, frame) are never resolved by name');
$ghost = [];
foreach (array_keys($pm) as $n) { if (empty($used[$n])) { $ghost[] = $n; } }
sort($ghost);
ok($ghost === ['agent', 'person', 'source_board'], 'the resolved params no action uses are the registry\'s spare names (agent, person, source_board): harmless — found: ' . implode(', ', $ghost));
// KNOWN KERNEL BEHAVIOUR 2: the kernel resolves a resolve entity's params registry-wide — an enum or free-text param named like an entity would be looked up as a record.
$collide = [];
foreach ($actions as $k => $a) { foreach ($a['params'] as $p) { $n = $p['name'] ?? ''; if (isset($pm[$n]) && !in_array($pm[$n][0], ['board', 'template', 'folder', 'member', 'department'], true) && !in_array($n, ['new_owner'], true)) { $collide[] = "$k.$n"; } } }
ok($collide === [], 'no param of any action is named like a resolve entity and means something else (the kernel looks such a name up as a record — Shareholder Intelligence had to rename one): none here');
$levels = [];
foreach ($actions as $k => $a) { foreach ($a['params'] as $p) { if (in_array($p['name'] ?? '', ['member', 'department', 'folder', 'template', 'board', 'new_owner'], true) && !preg_match('/^(board|member|department|folder|template|new_owner)$/', $p['name'])) { $levels[] = $k; } } }
ok($levels === [], 'and none of the entity-named params is a level, a layout or a flag');

echo "3. The registry, as the installer composes it, in the kernel's own loader\n";
require_once '/var/www/app/features/applications/action_tool_names.php';              // the kernel's pure naming functions, read only
$others = load_action_registries('/var/www/mcp/registries');
unset($others['boards']);
$composed = ['schema' => 'maludb-os.registry/1', 'app_key' => 'boards', 'name' => 'Boards', 'base_url' => 'http://127.0.0.1:' . need('APP_INTERNAL_PORT'), 'records_url' => 'http://127.0.0.1:' . REC . '/mcp', 'resolve' => $wrapper['resolve'], 'registry' => $reg];
[$named, $clash] = assign_registry_tool_names('boards', $composed, null, $others, kernel_action_names('/var/www'));
$toolOf = [];
foreach ($named['registry']['actions'] as $k => $a) { $toolOf[$a['action']] = $a['tool_name'] ?? null; }
$prefixed = array_keys(array_filter($toolOf, fn ($t, $a) => $t !== $a, ARRAY_FILTER_USE_BOTH));
sort($prefixed);
ok($clash === [] && !in_array(null, $toolOf, true), 'K55: every one of the 45 actions can be named against the ' . count($others) . ' applications installed here (' . implode(', ', array_keys($others)) . '); none clashes');
ok(count(array_unique($toolOf)) === 45 && max(array_map('strlen', $toolOf)) <= 64, 'the 45 tool names are distinct, within the 64 characters a model API takes');
echo '   (the actions that take the prefix here, because another application or the kernel has the bare name: ' . ($prefixed ? implode(', ', $prefixed) : 'none') . ")\n";
foreach ($manifest['agents'] as $a) {
    $bad = array_filter($a['tool_grants']['Actions MCP'] ?? [], fn ($t) => !isset($toolOf[$t]));
    ok($bad === [], "the {$a['key']}'s granted actions are all actions of the registry — the hire script grants them by their tool name (K55), which maps them" . ($bad ? ': ' . implode(', ', $bad) : ''));
}
$tmp = sys_get_temp_dir() . '/bd-p4-registry-' . getmypid() . '.json';
file_put_contents($tmp, json_encode($named));

$gid = $w['replay_items'];
$tid = fn ($n) => $n;
$resolve = [
    ['board', 'SMOKE p4 Roadmap', atoken(PRIYA)], ['board', $w['roadmap'], atoken(PRIYA)], ['board', $w['trashed'], atoken(PRIYA)], ['board', 'SMOKE p4 Twin', atoken(PRIYA)], ['template', 'SMOKE p4 Roadmap template', atoken(PRIYA)], ['template', 'Retrospective', atoken(PRIYA)],
    ['folder', 'SMOKE p4 Planning', atoken(PRIYA)], ['member', 'SMOKE Bea', atoken(PRIYA)], ['member', 'SMOKE Facilitator', atoken(PRIYA)], ['department', 'Sales', atoken(PRIYA)], ['department', 'accounting', atoken(PRIYA)],
];
$drive = [
    /* 0 */ ['board_share', ['board' => 'SMOKE p4 Roadmap', 'member' => 'SMOKE Lee', 'level' => 'view', 'confirmed' => true], atoken(PRIYA)],
    /* 1 */ ['board_share', ['board' => 'SMOKE p4 Roadmap', 'member' => 'SMOKE Lee', 'level' => 'view', 'confirmed' => true], atoken(PRIYA), 'pending_approval'],
    /* 2 */ ['board_share', ['board' => 'SMOKE p4 Roadmap', 'department' => 'Sales', 'level' => 'view'], atoken(PRIYA)],
    /* 3 */ ['board_restore', ['board' => $w['trashed']], atoken(PRIYA)],
    /* 4 */ ['trash_purge', ['board' => $w['trashed'], 'confirmed' => true], atoken(PRIYA)],
    /* 5 */ ['template_use', ['template' => 'Retrospective', 'title' => 'SMOKE p4 from a template'], atoken(PRIYA)],
    /* 6 */ ['board_duplicate', ['board' => 'SMOKE p4 Roadmap', 'title' => 'SMOKE p4 copy', 'folder' => 'SMOKE p4 Planning'], atoken(PRIYA)],
    /* 7 */ ['board_transfer', ['board' => 'SMOKE p4 Replay', 'new_owner' => 'SMOKE Bea', 'confirmed' => true], atoken(PRIYA)],
    /* 8 */ ['board_lock', ['board' => 'SMOKE p4 Twin', 'locked' => 'yes'], atoken(PRIYA)],
    /* 9 */ ['board_favorite', ['board' => 'nonesuch board'], atoken(PRIYA)],
    /* 10 */ ['folder_add_board', ['folder' => 'SMOKE p4 Planning', 'board' => 'SMOKE p4 Replay'], atoken(PRIYA)],
    /* 11 */ ['board_delete', ['board' => 'SMOKE p4 Sales board', 'confirmed' => true], atoken(MARCO), 'pending_approval'],
    /* 12 */ ['item_create_batch', ['board' => 'SMOKE p4 Replay', 'type' => 'sticky_note', 'texts' => 'one, two'], atoken(PRIYA)],
    /* 13 */ ['comment_create', ['board' => 'SMOKE p4 Replay', 'item' => $gid[0], 'text' => 'SMOKE p4 replay thread'], atoken(PRIYA)],
    /* 14 */ ['comment_reply', ['comment' => $w['thread_open'], 'text' => 'SMOKE p4 replay reply'], atoken(PRIYA)],
    /* 15 */ ['board_update', ['board' => 'SMOKE p4 Replay', 'title' => 'SMOKE p4 Replay renamed'], atoken(PRIYA)],
    /* 16 */ ['version_save', ['board' => $w['replay'], 'label' => 'p4 registry'], atoken(PRIYA)],
    /* 17 */ ['connector_create', ['board' => $w['replay'], 'from' => $gid[0], 'to' => $gid[1], 'caption' => 'replayed'], atoken(PRIYA)],
    /* 18 */ ['frame_create', ['board' => $w['replay'], 'title' => 'Replayed frame', 'around' => $gid[2] . ',' . $gid[3]], atoken(PRIYA)],
    /* 19 */ ['item_update_batch', ['board' => $w['replay'], 'items' => $gid[2] . ',' . $gid[3], 'color' => 'light_green'], atoken(PRIYA)],
    /* 20 */ ['items_arrange', ['board' => $w['replay'], 'items' => $gid[0] . ',' . $gid[1], 'layout' => 'column'], atoken(PRIYA)],
    /* 21 */ ['ops_undo', ['board' => $w['replay'], 'count' => '1'], atoken(PRIYA)],
    /* 22 */ ['item_delete_batch', ['board' => $w['replay'], 'items' => $gid[3], 'confirmed' => true], atoken(PRIYA)],
    /* 23 */ ['item_delete_batch', ['board' => $w['replay'], 'items' => $gid[3]], atoken(PRIYA)],
];
$kpy = '/var/www/mcp/venv/bin/python';
if (!is_executable($kpy)) { $kpy = trim((string) shell_exec('command -v python3')); }
$cmd = escapeshellarg($kpy) . ' -I ' . escapeshellarg(__DIR__ . '/registry_check.py') . ' ' . escapeshellarg($tmp) . ' ' . escapeshellarg('http://127.0.0.1:' . REC . '/mcp') . ' ' . escapeshellarg(json_encode(['resolve' => $resolve, 'drive' => $drive])) . ' 2>/tmp/bd-registry-check.err';
$out = (string) shell_exec($cmd);
@unlink($tmp);
$k = json_decode($out, true);
ok(is_array($k), 'the kernel\'s loader and tool factory ran against the composed registry' . (is_array($k) ? '' : ' — ' . substr((string) @file_get_contents('/tmp/bd-registry-check.err'), -400)));
if (!is_array($k)) { finish(); }
ok($k['loaded'] === ['boards'] && $k['registered'] === 45, 'application_actions.load_registries() accepts it and register() adds 45 tools');
ok(count($k['tools']) === 45 && array_values(array_diff(array_values($toolOf), array_keys($k['tools']))) === [], 'one tool per action, under the name K55 gave it');
$noApp = [];
foreach ($actions as $key => $a) { if ($a['approval'] !== null && !str_contains($k['tools'][$toolOf[$a['action']]]['description'], 'category: ' . $a['approval'])) { $noApp[] = $key; } }
ok($noApp === [], 'a tool\'s description states its approval category (' . $withCat . ' of them)');
$noConfirm = [];
foreach ($actions as $key => $a) { $txt = json_encode($k['tools'][$toolOf[$a['action']]]['schema']); if (!empty($a['confirm']) && !str_contains($txt, 'confirmed')) { $noConfirm[] = $key; } }
$nConf = count(array_filter($actions, fn ($a) => !empty($a['confirm'])));
ok($noConfirm === [] && $nConf === 13, "every destructive action carries the `confirmed` guard ($nConf of them)");
$noParam = [];
foreach ($actions as $key => $a) { $sc = $k['tools'][$toolOf[$a['action']]]['schema']; $props = array_keys($sc['$defs'][array_key_first($sc['$defs'] ?? [])]['properties'] ?? []); foreach ($a['params'] as $p) { if (($p['name'] ?? null) && !in_array($p['name'], $props, true)) { $noParam[] = "$key.{$p['name']}"; } } }
ok($noParam === [], 'every manifest parameter is a field of its tool' . ($noParam ? ' — missing: ' . implode(', ', array_slice($noParam, 0, 5)) : ''));
ok(array_filter($actions, fn ($a) => !empty($a['partial'])) === [], 'no action is `partial` in the registry: board_update and folder_update take their other fields from the record themselves (proved below, over HTTP)');

echo "   resolving through the kernel's resolver, as the person who asks:\n";
foreach ($resolve as [$entity, $value]) {
    $r = $k['resolved'][$entity . '|' . $value] ?? null;
    $spec = $wrapper['resolve'][$entity];
    $want = $value === 'SMOKE p4 Twin' ? 2 : 1;
    ok($r !== null && !isset($r['error']) && $r['matches'] === $want && ($want === 2 || ($r['id'] !== null && $r['label'] !== null)) && in_array($spec['id_field'], $r['keys'], true) && in_array($spec['label_field'], $r['keys'], true),
        "resolver: $entity '" . substr((string) $value, 0, 28) . "' → $want match" . ($want > 1 ? 'es (the kernel asks which)' : '') . ($r && isset($r['label']) ? ', ' . $spec['id_field'] . ' ' . substr((string) $r['id'], 0, 8) . ', "' . substr((string) $r['label'], 0, 36) . '"' : ' — ' . json_encode($r)));
}
$id = fn ($e, $v) => (string) $k['resolved'][$e . '|' . $v]['id'];
ok($k['resolved']['board|' . $w['trashed']]['label'] === 'SMOKE p4 Trashed' && $k['resolved']['department|accounting']['id'] === 3, 'a board in the trash resolves by its id (so board_restore and trash_purge work through the kernel), a department by a lower-case name');

function same(array $a, array $b): bool { ksort($a); ksort($b); return $a === $b; }
echo "4. Whole actions through the kernel's tool\n";
$d = $k['driven'];
$hookOf = fn ($i) => json_decode($d[$i]['posts'][0]['fields']['parameters'] ?? '[]', true);
ok(($d[0]['answer']['status'] ?? '') === 'success' && $d[0]['answer']['resolved'] === ['board' => 'SMOKE p4 Roadmap', 'member' => 'SMOKE Lee'] && count($d[0]['posts']) === 2 && $d[0]['posts'][0]['path'] === '/approvals/hook.php' && $d[0]['posts'][1]['path'] === '/boards/share-set.php',
    'board_share by NAMES with confirmed: the names resolve, the kernel\'s hook is asked first, then the handler');
ok($d[0]['posts'][0]['fields']['action_key'] === 'board_share' && $d[0]['posts'][0]['fields']['log_event'] === 'board.share' && $hookOf(0) === ['board' => $w['roadmap'], 'member' => '31', 'level' => 'view'] && same($d[0]['posts'][1]['fields'], ['board' => $w['roadmap'], 'member' => '31', 'level' => 'view']),
    'the hook is asked about the LOG EVENT board.share with the RESOLVED ids (a board uuid, member 31); the handler gets the same fields');
ok(($d[1]['answer']['status'] ?? '') === 'pending_approval' && count($d[1]['posts']) === 1 && $d[1]['answer']['application'] === 'boards', 'when the kernel pauses it (pending_approval): nothing is posted to Boards');
ok(($d[2]['answer']['status'] ?? '') === 'needs_confirmation' && $d[2]['posts'] === [], 'without confirmed=true: needs_confirmation, nothing posted, nothing asked');
ok(($d[3]['answer']['status'] ?? '') === 'success' && count($d[3]['posts']) === 1 && $d[3]['posts'][0]['path'] === '/boards/restore.php' && same($d[3]['posts'][0]['fields'], ['board' => $w['trashed']]), 'board_restore (no approval): a trashed board named by the id trash_list gave — one post');
ok(($d[4]['answer']['status'] ?? '') === 'success' && $d[4]['posts'][0]['fields']['log_event'] === 'trash.purge' && $d[4]['posts'][1]['path'] === '/admin/trash-purge.php' && same($d[4]['posts'][1]['fields'], ['board' => $w['trashed']]), 'trash_purge (deletion): the hook, then the handler with the trashed board\'s id');
ok(($d[5]['answer']['status'] ?? '') === 'success' && $d[5]['posts'][0]['path'] === '/templates/use.php' && same($d[5]['posts'][0]['fields'], ['template' => $id('template', 'Retrospective'), 'title' => 'SMOKE p4 from a template']) && $d[5]['answer']['resolved']['template'] === 'Retrospective', 'template_use: "Retrospective" resolves to the shipped template\'s id');
ok(($d[6]['answer']['status'] ?? '') === 'success' && same($d[6]['posts'][0]['fields'], ['board' => $w['roadmap'], 'title' => 'SMOKE p4 copy', 'folder' => (string) $w['folder']]), 'board_duplicate: the board and the folder resolve by name');
ok(($d[7]['answer']['status'] ?? '') === 'success' && $d[7]['posts'][0]['fields']['log_event'] === 'board.transfer' && $hookOf(7) === ['board' => $w['replay'], 'new_owner' => '28'] && same($d[7]['posts'][1]['fields'], ['board' => $w['replay'], 'new_owner' => '28']), 'board_transfer (other): new_owner resolves to member 28');
ok(($d[8]['answer']['status'] ?? '') === 'error' && str_contains($d[8]['answer']['message'], 'Several match') && str_contains($d[8]['answer']['message'], 'SMOKE p4 Twin (id ') && $d[8]['posts'] === [], 'an ambiguous title (two Twin boards): the kernel lists both with their ids and posts nothing');
ok(($d[9]['answer']['status'] ?? '') === 'error' && str_contains($d[9]['answer']['message'], "No board matching 'nonesuch board'") && $d[9]['posts'] === [], 'an unknown name: "No board matching \'nonesuch board\' in Boards that you can see."');
ok(($d[10]['answer']['status'] ?? '') === 'success' && same($d[10]['posts'][0]['fields'], ['folder' => (string) $w['folder'], 'board' => $w['replay']]), 'folder_add_board: the folder and the board both by name');
ok(($d[11]['answer']['status'] ?? '') === 'pending_approval' && count($d[11]['posts']) === 1 && $d[11]['posts'][0]['fields']['log_event'] === 'board.delete', 'board_delete paused for approval: nothing posted');
// KNOWN KERNEL BEHAVIOUR 1: a repeated parameter is split on commas
ok(($d[12]['answer']['status'] ?? '') === 'success' && $d[12]['posts'][0]['path'] === '/items/create.php' && $d[12]['posts'][0]['fields']['texts[]'] === ['one', 'two'], 'item_create_batch with texts "one, two": the kernel splits a repeated parameter on commas — TWO stickies (a sticky\'s text cannot hold a comma through the Actions MCP; the kernel\'s, reported)');
ok(($d[13]['answer']['status'] ?? '') === 'success' && same($d[13]['posts'][0]['fields'], ['board' => $w['replay'], 'item' => $gid[0], 'text' => 'SMOKE p4 replay thread']), 'comment_create: the item is an id, passed as given');
ok(($d[14]['answer']['status'] ?? '') === 'success' && same($d[14]['posts'][0]['fields'], ['comment' => $w['thread_open'], 'text' => 'SMOKE p4 replay reply']), 'comment_reply: a comment is never resolved by name');
ok(($d[15]['answer']['status'] ?? '') === 'success' && same($d[15]['posts'][0]['fields'], ['board' => $w['replay'], 'title' => 'SMOKE p4 Replay renamed']), 'board_update posts the record and the field that changes');
ok(($d[16]['answer']['status'] ?? '') === 'success' && $d[16]['posts'][0]['fields']['board'] === $w['replay'] && $d[16]['posts'][0]['fields']['label'] === 'p4 registry', 'version_save: a board by its title');
ok(($d[18]['answer']['status'] ?? '') === 'success' && $d[18]['posts'][0]['fields']['around[]'] === [$gid[2], $gid[3]], 'frame_create: around ids reach the handler as a list');
ok(($d[20]['answer']['status'] ?? '') === 'success' && $d[20]['posts'][0]['fields']['items[]'] === [$gid[0], $gid[1]] && $d[20]['posts'][0]['fields']['layout'] === 'column', 'items_arrange: the ids as a list, the layout as given');
ok(($d[22]['answer']['status'] ?? '') === 'success' && $d[22]['posts'][0]['fields']['log_event'] === 'item.delete' && $d[22]['posts'][1]['path'] === '/items/delete.php' && ($d[23]['answer']['status'] ?? '') === 'needs_confirmation', 'item_delete_batch (deletion): confirmed -> the hook, then the handler; without it, needs_confirmation');
$wrote = (int) one("SELECT count(*) FROM boards WHERE title IN ('SMOKE p4 copy', 'SMOKE p4 from a template')");
ok($wrote === 0 && (string) one("SELECT deleted_at FROM boards WHERE id = CAST(:b AS uuid)", ['b' => $w['trashed']]) !== '' && (int) one('SELECT owner_member_id FROM boards WHERE id = CAST(:b AS uuid)', ['b' => $w['replay']]) === 26, 'and nothing was written to the application by the stubbed posts');

echo "5. The posts the kernel would make, replayed against the real handlers as an agent\n";
$rn = runid(70); run_facts($rn, 42);
$replay = function (int $i) use ($d, $rn) {
    $post = end($d[$i]['posts']);
    $form = [];
    foreach ($post['fields'] as $key => $value) { $form[str_ends_with($key, '[]') ? substr($key, 0, -2) : $key] = $value; }
    return agent_act(42, $rn, $post['path'], $form);
};
$items0 = fn () => (int) one('SELECT count(*) FROM board_items WHERE board_id = CAST(:b AS uuid) AND deleted_at IS NULL', ['b' => $w['replay']]);
$n0 = $items0();
$r = $replay(12);
ok($r['code'] === 200 && $items0() === $n0 + 2 && (int) one("SELECT count(*) FROM board_items WHERE board_id = CAST(:b AS uuid) AND plain_text IN ('one', 'two')", ['b' => $w['replay']]) === 2, 'item_create_batch "one, two": the handler makes the two stickies the kernel sent');
$r = $replay(13);
ok($r['code'] === 200 && (int) one("SELECT count(*) FROM comments WHERE plain_text = 'SMOKE p4 replay thread' AND board_id = CAST(:b AS uuid)", ['b' => $w['replay']]) === 1, 'comment_create: the thread is made (an agent\'s, with its run)');
$r = $replay(14);
ok($r['code'] === 200 && (int) one("SELECT count(*) FROM comments WHERE plain_text = 'SMOKE p4 replay reply'") === 1, 'comment_reply (on a thread of the Roadmap, which the Facilitator may comment on): the reply is made');
$desc = (string) one('SELECT description FROM boards WHERE id = CAST(:b AS uuid)', ['b' => $w['replay']]);
$r = $replay(15);
ok($r['code'] === 200 && (string) one('SELECT title FROM boards WHERE id = CAST(:b AS uuid)', ['b' => $w['replay']]) === 'SMOKE p4 Replay renamed' && (string) one('SELECT description FROM boards WHERE id = CAST(:b AS uuid)', ['b' => $w['replay']]) === $desc && $desc === 'Keep this description',
    'board_update with only the title: the title changes and the description stays ("Keep this description") — a partial update that needs no _partial');
$r = $replay(16);
ok($r['code'] === 200 && (int) one("SELECT count(*) FROM board_versions WHERE board_id = CAST(:b AS uuid) AND label = 'p4 registry'", ['b' => $w['replay']]) === 1, 'version_save: the named version is saved');
$r = $replay(17);
ok($r['code'] === 200 && (int) one("SELECT count(*) FROM board_items WHERE board_id = CAST(:b AS uuid) AND type = 'connector' AND start_item_id = CAST(:s AS uuid) AND end_item_id = CAST(:e AS uuid) AND deleted_at IS NULL", ['b' => $w['replay'], 's' => $gid[0], 'e' => $gid[1]]) === 1, 'connector_create: the connector joins the two');
$r = $replay(18);
ok($r['code'] === 200 && (int) one("SELECT count(*) FROM board_items WHERE board_id = CAST(:b AS uuid) AND type = 'frame' AND data->>'title' = 'Replayed frame' AND deleted_at IS NULL", ['b' => $w['replay']]) === 1, 'frame_create around two items: the frame is made');
$r = $replay(19);
ok($r['code'] === 200 && (int) one("SELECT count(*) FROM board_items WHERE board_id = CAST(:b AS uuid) AND style->>'fillColor' = 'light_green'", ['b' => $w['replay']]) === 2, 'item_update_batch: the two items change colour');
$before = (int) one('SELECT max(seq) FROM board_ops WHERE board_id = CAST(:b AS uuid)', ['b' => $w['replay']]);
$r = $replay(20);
$arr = (int) one('SELECT max(seq) FROM board_ops WHERE board_id = CAST(:b AS uuid)', ['b' => $w['replay']]);
ok($r['code'] === 200 && $arr === $before + 1 && (int) one("SELECT count(*) FROM board_ops WHERE board_id = CAST(:b AS uuid) AND source = 'agent' AND agent_run_id = :r", ['b' => $w['replay'], 'r' => $rn]) >= 5, 'items_arrange: one batch, and every batch of the replay carries the agent source and the run (' . $rn . ')');
$r = $replay(21);
ok($r['code'] === 200 && (int) one('SELECT count(*) FROM board_ops WHERE board_id = CAST(:b AS uuid) AND :s = ANY (undoes_seq)', ['b' => $w['replay'], 's' => $arr]) === 1, 'ops_undo: the arrangement is undone by its own inverse');
$r = $replay(22);
ok($r['code'] === 200 && (int) one('SELECT count(*) FROM board_items WHERE id = CAST(:i AS uuid) AND deleted_at IS NOT NULL', ['i' => $gid[3]]) === 1, 'item_delete_batch: the item is deleted (the approval the kernel asked first is the kernel\'s)');
$log = q("SELECT action, source, agent_run_id FROM activity_log WHERE agent_run_id = :r ORDER BY id", ['r' => $rn]);
ok(count($log) >= 10 && array_unique(array_column($log, 'source')) === ['agent'], 'the trail says what the agent did: ' . count($log) . ' rows, source agent, each with the run');

echo "6. maludb-os.json is in step with what the servers offer\n";
$agents = array_column($manifest['agents'], null, 'key');
ok(array_keys($agents) === ['expert', 'facilitator'], 'two agents hired on install: the Boards Expert and the Facilitator');
$badTool = [];
foreach ($manifest['agents'] as $a) {
    foreach ($a['tool_grants']['Records MCP'] ?? [] as $t) { if (!in_array($t, $S['person'], true)) { $badTool[] = $a['key'] . ' ' . $t; } }
    foreach ($a['tool_grants']['Activity MCP'] ?? [] as $t) { if (!in_array($t, $S['activity'], true)) { $badTool[] = $a['key'] . ' ' . $t; } }
    foreach ($a['tool_grants']['Actions MCP'] ?? [] as $t) { if (!isset($actions[$t])) { $badTool[] = $a['key'] . ' ' . $t; } }
}
ok($badTool === [], 'every tool an agent is granted exists: a records or activity tool of the surface, an action of the registry' . ($badTool ? ' — ' . implode(', ', $badTool) : ''));
ok(count(array_intersect($agents['expert']['tool_grants']['Records MCP'], ['boards_index', 'boards_usage', 'agent_dispatches', 'trash_list'])) === 0 && count(array_intersect($agents['facilitator']['tool_grants']['Records MCP'], ['boards_index', 'boards_usage', 'agent_dispatches', 'board_access'])) === 0, 'neither agent is granted a share, the usage report, the dispatches or (the Facilitator) who-can-open');
$never = array_intersect($agents['facilitator']['tool_grants']['Actions MCP'], ['board_delete', 'board_share', 'board_unshare', 'board_access_set', 'board_transfer', 'comment_delete', 'settings_save', 'template_publish', 'trash_purge', 'version_restore', 'folder_delete', 'token_mint', 'token_revoke', 'dispatch_retry']);
ok($never === [], 'the Facilitator is granted no action that shares, transfers, publishes or administers; its one deleting action, item_delete_batch, pauses for a deletion approval');
$appr = array_column($manifest['approvals'], 'category', 'action');
$bad = [];
foreach ($manifest['approvals'] as $a) { if (!isset($actions[$a['action']]) || $actions[$a['action']]['approval'] !== $a['category']) { $bad[] = $a['action']; } }
ok($bad === [] && count($appr) === $withCat, 'approvals[] is the manifest\'s ' . $withCat . ' pauses, each an action of the registry with that category');
ok((int) shell_exec('cd ' . escapeshellarg($root) . ' && php bin/sync_approvals.php --check >/dev/null 2>&1; echo $?') === 0, 'and bin/sync_approvals.php --check agrees');
foreach ($manifest['skills'] as $s) { ok(is_file($root . '/' . $s . '/SKILL.md'), "skill $s has its SKILL.md"); }
$known = array_merge($S['records'], $S['activity'], array_keys($actions), ['message_send', 'inbox_read']);
$unknown = [];
foreach (array_merge(glob($root . '/skills/*/*.md'), glob($root . '/os/*.md')) as $f) {
    preg_match_all('/`([a-z]+(?:_[a-z]+)+)`/', (string) file_get_contents($f), $m);
    foreach (array_unique($m[1]) as $name) { if (!in_array($name, $known, true) && !in_array($name, ['start_item_id', 'end_item_id', 'context_item_ids', 'parent_id'], true) && !preg_match('/^(sticky_note|light_|text_|start_view|shape_|board_id|item_id|member_id|data_|fill|border)/', $name)) { $unknown[] = basename(dirname($f)) . '/' . basename($f) . ': ' . $name; } }
}
ok($unknown === [], 'every tool or action a skill or a job description names exists' . ($unknown ? ' — ' . implode('; ', $unknown) : ''));
finish();
