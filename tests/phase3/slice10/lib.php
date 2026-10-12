<?php
/**
 * Slice 10's helpers (docs/build-specs/home-admin.md "Proof"), on slice 9's (and through it 8's … Phase 2's): the home, the admin pages and the two handlers as JSON or HTML, a fixture role that holds
 * one right, a board with a given item count, a trashed board with files in the store, activity rows for "most active". `php tests/phase3/slice10/seed.php` makes the browser's boards as JSON.
 * The fixture's names: "SMOKE Priya" (26, a Member), "SMOKE Marco" (27, a Boards admin), "SMOKE Bea" (28, a Member), "SMOKE Ann" (29, an external Viewer), "SMOKE Dana" (30, a Viewer), "SMOKE Lee" (31, a Viewer),
 * and 95 "SMOKE Leaver" — a member who owns a board and has left (inactive), made by leaver_board().
 */
require dirname(__DIR__) . '/slice9/lib.php';

function home_page(string $jar, array $headers = ['Accept: application/json']): array { return page($jar, '/', ['headers' => $headers]); }
function home_data(string $jar): array { return body_json(home_page($jar))['data'] ?? []; }
function admin_data(string $jar, string $path = '/admin'): array { return body_json(page($jar, $path, ['headers' => ['Accept: application/json']]))['data'] ?? []; }
function settings_as(string $jar, array $form, array $headers = ['Accept: application/json']): array { return post_as($jar, '/admin/settings-save.php', $form, $headers); }
function purge_as(string $jar, $board, array $headers = ['Accept: application/json']): array { return post_as($jar, '/admin/trash-purge.php', ['board' => $board], $headers); }
function settings_row(): array { return q('SELECT * FROM bd_settings WHERE id = 1')[0]; }
/** The settings every proof starts from (the defaults the schema gives, live off as the scratch database has it). */
function settings_reset(): void
{
    pg("UPDATE bd_settings SET business_name = NULL, default_everyone_level = 'none', item_soft_budget = 4000, item_hard_cap = 10000, batch_max_ops = 500, max_upload_mb = 25, op_retention_days = 90,
        interval_version_days = 30, daily_version_days = 365, trash_days = 30, live_enabled = false WHERE id = 1");
}
/** A role that holds exactly the rights given (plus boards.view), given to a signed-on member. Their level cap is `comment`. */
function give_role(int $member, string $key, array $rights): void
{
    pg("INSERT INTO bd_roles (role_key, name, description, capability, is_admin, sort_order) VALUES ('$key', 'Fixture $key', 'a proof role', 'write', false, 9) ON CONFLICT DO NOTHING");
    foreach (array_unique(array_merge(['boards.view'], $rights)) as $r) {
        pg("INSERT INTO bd_role_rights (role_key, right_key) VALUES ('$key', '$r') ON CONFLICT DO NOTHING");
    }
    pg("UPDATE members SET roles = ARRAY['$key'] WHERE id = $member");
}
function set_roles(int $member, string $literal): void { pg("UPDATE members SET roles = '$literal' WHERE id = $member"); }
/** Make a board's item_count what the fixture needs (a fixture; the count is the trigger's in real use). */
function set_items(string $board, int $n): void { pg("UPDATE boards SET item_count = $n WHERE id = '$board'"); }
function set_idle(string $board, int $days): void { pg("UPDATE boards SET last_activity_at = now() - interval '$days days' WHERE id = '$board'"); }
/** A member who owned a board and has left: [member 95, the board]. Idempotent. */
function leaver_board(): string
{
    $id = pg("SELECT id FROM boards WHERE title = 'SMOKE Left behind' LIMIT 1")[0] ?? '';
    if ($id === '') {
        pg("INSERT INTO members (id, member_kind, display_name, business_role, status, capability, roles) VALUES (95, 'human', 'SMOKE Leaver', 'user', 'active', 'write', ARRAY['user']) ON CONFLICT DO NOTHING");
        pg("UPDATE members SET status = 'active' WHERE id = 95");
        $id = pg("SELECT set_config('app.member_id', '95', false) \\g /dev/null\nSELECT bd_board_create(95, 'SMOKE Left behind', 'Its owner has gone.');")[0] ?? '';
        pg("UPDATE members SET status = 'inactive' WHERE id = 95");
    }
    return $id;
}
/** A fixture file in the store under a relative path; answers the absolute path. */
function store_file(string $rel, string $body = 'x'): string
{
    $abs = rtrim(need('BD_STORAGE_DIR'), '/') . '/' . $rel;
    @mkdir(dirname($abs), 0775, true);
    file_put_contents($abs, $body);
    return $abs;
}
/** An attachment row of a board pointing at $path (relative to the store, or anything the proof wants to try). */
function attach(string $board, string $path, string $name = 'f.txt'): void
{
    pg("INSERT INTO attachments (record_type, record_uuid, board_id, filename, mime_type, byte_size, sha256, storage_path)
        VALUES ('item', gen_random_uuid(), '$board', '$name', 'text/plain', 1, 'x', '" . str_replace("'", "''", $path) . "')");
}
/** A board of Priya's with two stickies, deleted by her. */
function trashed_board(string $jp, string $title, int $files = 2): array
{
    $b = make_board($jp, $title);
    ops_post($jp, $b, [item_op(['text' => 'One', 'x' => 0, 'y' => 0, 'z' => 'a1']), item_op(['text' => 'Two', 'x' => 300, 'y' => 0, 'z' => 'a2'])]);
    $paths = [];
    for ($i = 1; $i <= $files; $i++) {
        $rel = 'boards/' . substr($b, 0, 8) . "/file$i.txt";
        store_file($rel, "file $i");
        attach($b, $rel, "file$i.txt");
        $paths[] = rtrim(need('BD_STORAGE_DIR'), '/') . '/' . $rel;
    }
    act($jp, '/boards/delete.php', ['board' => $b]);
    return [$b, $paths];
}
/** Activity rows for "most active": $n rows of $action by $members (cycled) on a board, $ageDays old. */
function fake_activity(string $board, int $n, array $members, string $action = 'item.create', int $ageDays = 0): void
{
    for ($i = 0; $i < $n; $i++) {
        $m = $members[$i % count($members)];
        pg("INSERT INTO activity_log (actor_member_id, source, action, board_id, occurred_at) VALUES ($m, 'web', '$action', '$board', now() - interval '$ageDays days' - interval '$i minutes')");
    }
}
/** The ids of the cards of a home section in the HTML. */
function section_ids(string $html, string $name): array
{
    if (!preg_match('/<section class="mb-4" id="home-section-' . preg_quote($name, '/') . '">(.*?)<\/section>/s', $html, $m)) {
        return [];
    }
    preg_match_all('/id="board-card-([0-9a-f-]{36})"/', $m[1], $ids);
    return $ids[1];
}
