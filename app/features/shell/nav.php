<?php
declare(strict_types=1);

/**
 * The shell's menu (docs/build-specs/sso-shell.md; design §9): ONE table — the sidebar and the phone's tab bar read it, so a menu item and
 * the right that opens its screen never disagree. An item shows only to a holder of its right (several joined by "|": any one).
 * The order is the spec's: Boards · Templates · Folders · Trash, Admin for a Boards admin; then Me. A board's own pages (its canvas, settings,
 * share, outline, comments, history) hang under Boards, never here.
 *   [nav id, url, icon, label, right, the manifest's screen id]
 */
const NAV_ITEMS = [
    'Boards' => [
        ['boards',    '/boards',    'feather-layout',        'Boards',    'boards.view',  'boards-list'],
        ['templates', '/templates', 'feather-copy',          'Templates', 'boards.view',  'templates'],
        ['folders',   '/folders',   'feather-folder',        'Folders',   'boards.view',  'folders'],
        ['trash',     '/trash',     'feather-trash-2',       'Trash',     'boards.view',  'trash'],
        ['admin',     '/admin',     'feather-sliders',       'Admin',     'boards.admin', 'admin'],
    ],
    'Me' => [
        ['notifications', '/notifications',    'feather-bell',     'Notifications', 'boards.view', 'notifications'],
        ['my-settings',   '/settings/',        'feather-settings', 'My settings',   'boards.view', 'settings'],
        ['tokens',        '/settings/tokens/', 'feather-key',      'My tokens',     'tokens.own',  'settings-tokens'],
        ['activity',      '/activity',         'feather-clock',    'My trail',      'boards.view', 'activity'],
    ],
];

/**
 * Every screen of the manifest that a later slice builds (Phase 2 ships them as placeholders): screen id → [slice, title, the manifest's
 * line, the right that opens it (any one of "a|b"), the menu item it lives under, the board level its page needs (null: not a board's page)].
 * render_nav_stub() reads it; a slice's first step removes its screen's call. The registry counts a controller that calls it as `stub`.
 */
const NAV_SLICES = [
    'home'             => [10, 'Home',            'The start: recent boards, favourites, shared with me, team folders, New board and New from template.', 'boards.view', 'home', null],
    'boards-list'      => [1,  'Boards',          'Every board you can open, as cards: yours, shared with you, favourites, by folder.', 'boards.view', 'boards', null],
    'board-add'        => [1,  'New board',       'To make a new board: its title, a description, a folder.', 'boards.create', 'boards', null],
    'board-view'       => [2,  'Board',           'The canvas: who is here, the toolbars, the right rail.', 'boards.view', 'boards', 'view'],
    'board-settings'   => [1,  'Board settings',  'A board\'s settings: title, description, folder, background, the start view, the lock, duplicate, delete.', 'boards.view', 'boards', 'view'],
    'board-share'      => [1,  'Share',           'Who can open a board and at what level: everyone in the business, people, agents, departments, the owner, transfer.', 'boards.view', 'boards', 'view'],
    'board-outline'    => [2,  'Outline',         'A board as a list: frames in order, their items, the connectors, the open comments.', 'boards.view', 'boards', 'view'],
    'board-comments'   => [6,  'Comments',        'A board\'s threads, open first, each with where it is pinned.', 'boards.view', 'boards', 'view'],
    'board-history'    => [7,  'History',         'What changed on a board — who, what, where — and its versions.', 'boards.view', 'boards', 'view'],
    'folders'          => [1,  'Folders',         'Your folders and the team folders.', 'boards.view', 'folders', null],
    'folder-view'      => [1,  'Folder',          'One folder\'s boards, as cards.', 'boards.view', 'folders', null],
    'folder-add'       => [1,  'New folder',      'To make a folder, yours or a team folder.', 'boards.view', 'folders', null],
    'folder-edit'      => [1,  'Edit folder',     'To rename a folder or make it a team folder.', 'boards.view', 'folders', null],
    'trash'            => [1,  'Trash',           'Your deleted boards, with when each is purged, to bring one back.', 'boards.view', 'trash', null],
    'templates'        => [8,  'Templates',       'The templates gallery: shipped, the business\'s and yours, by category.', 'boards.view', 'templates', null],
    'template-view'    => [8,  'Template',        'A template\'s preview, and Use.', 'boards.view', 'templates', 'view'],
    'notifications'    => [6,  'Notifications',   'Your notices, unread first.', 'boards.view', 'notifications', null],
    'settings'         => [6,  'My settings',     'Your preferences: which notices by email and by text, the morning digest.', 'boards.view', 'my-settings', null],
    'search'           => [9,  'Search',          'One search across the boards, items and comments you can see.', 'boards.view', 'search', null],
    'admin'            => [10, 'Admin',           'Usage against the budget, the trash, the agents, the settings.', 'boards.admin|settings.manage|agents.manage|trash.purge', 'admin', null],
    'admin-usage'      => [10, 'Usage',           'Every board by size against the budget, idle ones, ones whose owner has left.', 'boards.admin', 'admin', null],
    'admin-settings'   => [10, 'Settings',        'The defaults: a new board\'s everyone level, the limits, retention, the trash\'s days, live updates.', 'settings.manage', 'admin', null],
    'admin-agents'     => [9,  'Agents',          'The agents on Boards: who they are, which boards they can see, what they did this week.', 'agents.manage', 'admin', null],
    'admin-dispatches' => [9,  'Dispatches',      'Every mention of an agent and what became of it, a failed one to retry.', 'agents.manage', 'admin', null],
];

/** The groups as the sidebar shows them (the first group has no caption). */
function nav_groups(): array
{
    return NAV_ITEMS;
}

/** The phone's tabs (sso-shell decision 4): Home · Boards · Search · Me. Me opens the sidebar; without JavaScript it opens My trail. [id, url, icon, label, right, kind] */
function nav_tabs(): array
{
    return [
        ['home',   '/',         'feather-home',   'Home',   'boards.view', 'link'],
        ['boards', '/boards',   'feather-layout', 'Boards', 'boards.view', 'link'],
        ['search', '/search',   'feather-search', 'Search', 'boards.view', 'link'],
        ['me',     '/activity', 'feather-user',   'Me',     'boards.view', 'menu'],
    ];
}

/** The sidebar items this member may see, by group (the spec's nav_items_for). */
function nav_items_for(int $memberId): array
{
    $out = [];
    foreach (NAV_ITEMS as $group => $items) {
        $shown = array_values(array_filter($items, static fn (array $i): bool => nav_has_right($i[4])));
        if ($shown !== []) {
            $out[$group] = $shown;
        }
    }
    return $out;
}

/** A menu right may name several joined by "|": any one of them opens the item. */
function nav_has_right(string $spec): bool
{
    foreach (explode('|', $spec) as $right) {
        if (has_right($right)) {
            return true;
        }
    }
    return false;
}

/** The highest Boards role the member holds, in words — the header's badge (bd_member_roles(), db/004). */
function role_badge(): string
{
    $m = current_member();
    if ($m === null) {
        return '';
    }
    if (($m['business_role'] ?? '') === 'super_admin' && ($m['member_kind'] ?? 'human') === 'human') {
        return 'Super-admin';
    }
    $held = pg_text_array((string) (one_value(db(), 'SELECT bd_member_roles(:id)::text', ['id' => (int) $m['id']]) ?: '{}'));
    foreach (['admin' => 'Boards admin', 'user' => 'Member', 'viewer' => 'Viewer'] as $key => $label) {
        if (in_array($key, $held, true)) {
            return $label;
        }
    }
    return '';
}

/** A PostgreSQL text[] literal ({a,b}) as a PHP list; quoted elements are unquoted. */
function pg_text_array(string $literal): array
{
    $literal = trim($literal);
    if ($literal === '' || $literal === '{}') {
        return [];
    }
    $out = [];
    foreach (str_getcsv(substr($literal, 1, -1), ',', '"', '\\') as $v) {
        if ($v !== null && $v !== '') {
            $out[] = $v;
        }
    }
    return $out;
}

/** A link that navigates by HTMX into #page-content and still works as a plain link (progressive enhancement). $html is already escaped. */
function hx_link(string $url, string $html, string $class = '', string $extra = ''): string
{
    return '<a href="' . e($url) . '"' . ($class !== '' ? ' class="' . e($class) . '"' : '') . ($extra !== '' ? ' ' . $extra : '')
        . ' hx-get="' . e($url) . '" hx-target="#page-content" hx-swap="innerHTML" hx-push-url="' . e($url) . '">' . $html . '</a>';
}

/** "Back to …" from the ?back= a link carried (click-around rule): [url, label] or null. Later slices add their screens here. */
function back_link(): ?array
{
    $back = safe_local_path($_GET['back'] ?? null);
    if ($back === null) {
        return null;
    }
    $path = parse_url($back, PHP_URL_PATH) ?: '/';
    $labels = ['/' => 'Home', '/search' => 'Search', '/notifications' => 'Notifications', '/activity' => 'My trail', '/settings/' => 'My settings',
               '/settings/tokens/' => 'My tokens'];
    if (isset($labels[$path])) {
        return [$back, $labels[$path]];
    }
    foreach (NAV_ITEMS as $items) {
        foreach ($items as $i) {
            if (rtrim($i[1], '/') === rtrim($path, '/')) {
                return [$back, $i[3]];
            }
        }
    }
    if (preg_match('#^/boards/[0-9a-f-]{36}(/|$)#', $path)) {
        return [$back, 'the board'];
    }
    if (preg_match('#^/folders/\d+$#', $path)) {
        return [$back, 'the folder'];
    }
    if (preg_match('#^/templates/[0-9a-f-]{36}$#', $path)) {
        return [$back, 'the template'];
    }
    return null;
}

/** The URL of this same page, for a ?back= (path and query as requested). */
function here_url(): string
{
    return (string) ($_SERVER['REQUEST_URI'] ?? '/');
}

function with_back(string $url, string $here): string
{
    return $url . (str_contains($url, '?') ? '&' : '?') . 'back=' . rawurlencode($here);
}

/**
 * A screen of the manifest that its slice has not built yet (Phase 2): the right checked (and, for a board's page, the board's wall at
 * its level — the same 404 / 403 the slice will answer), then the shell with one card saying which slice builds it and what it will do —
 * 200 to a person, 501 `not_built` to JSON and to a POST. bin/build_action_registry.php reads a controller that calls this as a stub, so
 * the voice surface never offers the screen. Removing the call is the slice's first step.
 */
function render_nav_stub(string $screen, int $slice): never
{
    $def = NAV_SLICES[$screen] ?? throw new InvalidArgumentException('render_nav_stub: no screen ' . $screen);
    [$owner, $title, $line, $right, $nav, $level] = $def;
    if ($owner !== $slice) {
        throw new InvalidArgumentException("render_nav_stub: $screen is slice $owner's, not slice $slice's");
    }
    require_login();
    if (!nav_has_right($right)) {
        require_right(explode('|', $right)[0]);              // refuses in that right's sentence
    }
    $board = null;
    if ($level !== null) {
        require_once APP_ROOT . '/app/features/boards/wall.php';
        $board = bd_board_or_404(db(), isset($_GET['board']) ? (string) $_GET['board'] : null, $level);
    }
    $words = 'Built by slice ' . $slice . ' — ' . lcfirst(rtrim($line, '.')) . '.';
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        emit_action_status(false, ['error' => $words]);
        if (wants_json()) {
            json_error('not_built', $words, 501);
        }
        http_response_code(501);
        echo view('shared/message.php', ['title' => 'Not built yet', 'message' => $words]);
        exit;
    }
    if (wants_json()) {
        json_error('not_built', $words, 501);
    }
    log_activity(db(), 'screen.view', null, null, ['screen' => $screen, 'board_id' => $board['board_id'] ?? null]);
    $icon = nav_icon($nav);
    render_screen($board !== null ? $board['title'] . ' · ' . $title : $title,
        view('shared/nav-stub.php', ['screen' => $screen, 'title' => $title, 'icon' => $icon, 'slice' => $slice, 'line' => $line, 'board' => $board]),
        ['activeNav' => $nav, 'screen' => $screen, 'entity' => $board !== null ? 'board' : '', 'recordId' => $board['board_id'] ?? '']);
    exit;
}

/** A menu item's icon (the home and search have none in the sidebar). */
function nav_icon(string $nav): string
{
    foreach (NAV_ITEMS as $items) {
        foreach ($items as $i) {
            if ($i[0] === $nav) {
                return $i[2];
            }
        }
    }
    return ['home' => 'feather-home', 'search' => 'feather-search'][$nav] ?? 'feather-layout';
}
