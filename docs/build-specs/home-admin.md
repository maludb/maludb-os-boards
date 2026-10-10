# Build spec: home, admin and settings (slice 10)

What exists at the end: **the home page** — recent boards, favourites, shared with me, team folders, mentions waiting, New board and New from template,
a first-run state for someone with no board; **the admin's page** — usage at a glance, the whole trash (restore, purge now), the agents' health, the
most active boards; **usage** against the budget (idle boards, boards whose owner has left); **the settings** (a new board's everyone level, the
limits, retention, the trash's days, live updates on or off). The last slice: after it every screen of the manifest is built.

Exemplar to replicate: **slice 1 (boards-folders)** — its cards (the board card partial, `boards-folders.md` names it), its pages and its handler order
(`bd_handler_begin()` → `require_right()` → `bd_guard()` → `log_activity()` → `emit_action_status()` → `bd_done()`).

Schema and functions used (db/005–013, never modified): `bd_settings` (and its CHECKs), `boards`, `board_recents`, `board_favorites`, `mcp_boards`
(`is_favorite`, `my_last_visit`, `my_level`), `mcp_folders`, `mcp_trash`, `mcp_boards_usage`, `mcp_notifications`, `mcp_agent_dispatches`,
`mcp_activity_log`, `bd_board_purge()` (answers the files to unlink), `bd_is_boards_admin()`. **The database is the referee**: the settings' ranges and
the two cross-field rules (`item_soft_budget < item_hard_cap`, `interval_version_days <= daily_version_days`), who sees usage, what a purge removes.

## Divergences from the design
1. **The trash's purge button lives on the admin's page** (`admin`), not on slice 1's `trash` screen: slice 1's page restores; purging for good is the
   admin's and sits with the admin's other housekeeping.
2. **The settings form edits the manifest's fields only** (`settings_save`'s params). The rest of `bd_settings` — drawing points, room size, the live
   check, the poll interval, the key rates, the business's time zone — is shown read-only with "set at install".

## Who may do what
| Action / screen | Right | Rule |
|---|---|---|
| `home` | `boards.view` | every insider; an external sees only what is shared with them by name |
| `admin` | any of `boards.admin`, `settings.manage`, `agents.manage`, `trash.purge` (`require_any_right()`) | each section shows only to its right |
| `admin-usage` | `boards.admin` | `mcp_boards_usage` answers nothing to anyone else |
| `admin-settings`, settings_save | `settings.manage` | an agent's `settings_save` pauses (`other`) |
| trash_purge | `trash.purge` | people only (`require_human()`); a board in the trash |

## Screens (375 px first for home; the admin pages designed at 1280, usable at 375)
| Screen id | Canonical URL | Purpose |
|---|---|---|
| `home` | `/` | the buttons **New board** (`/boards/new`) and **New from template** (`/templates`); then sections of cards (slice 1's board card), each at most 8 with **See all**: **Recent** (`board_recents` newest, live boards I still see), **Favourites** (`is_favorite`), **Shared with me** (boards I do not own, `my_level` ≥ view, not templates, by `last_activity_at`), **Team folders** (`mcp_folders` where `is_team` — a folder card: name, board count → `folder-view`); a line **{n} mentions waiting** (unread `mention` notifications → `/notifications`) when n > 0. **First run** (no board seen at all): "Make your first board" with the two buttons and the six shipped templates as cards. A Viewer gets no **New** buttons. Replaces the kit's placeholder |
| `admin` | `/admin` | sections by right: **Usage** (`boards.admin`): boards, items in all, boards over the soft budget, idle over 90 days, whose owner has left — each a link into `admin-usage` with its filter; **The trash** (`trash.purge` or `boards.admin`): every board in the trash (title, owner, items, deleted by and when, purged after) with **Restore** (slice 1's `board_restore`) and **Purge now** (`trash_purge`, `hx-confirm` "Purge {title} for good, with its {n} items and files? This cannot be undone."); **Agents** (`agents.manage`): dispatches failed and waiting (links to slice 9's pages); **Most active this month** (`boards.admin`): five boards from `most_active_boards()`; **Settings** (`settings.manage`): the link |
| `admin-usage` | `/admin/usage?sort=&idle_days=&owner_left=` | every working board (`mcp_boards_usage`): at 1280 a table, at 375 cards — the title (→ the board), the owner (a **left** badge when `owner_active` is false), items against the soft budget as a bar (amber past the budget, red within 10 % of the hard cap), idle days, open threads, last activity; `sort` = items (default) · idle · open_threads; `idle_days` = only boards idle at least that long; `owner_left=1` = only boards whose owner has left, each with **Transfer** (slice 1's `board-share` page, its transfer section) |
| `admin-settings` | `/admin/settings` | `page-header-form`; the form below, the read-only rest ("set at install"), and the notes: lowering the hard cap never removes items (creates above it are refused); live updates off means every board polls every `poll_seconds` |

## The settings form (`settings_save` — every range is the CHECK's; checked in PHP with the same numbers, the database the referee)
| Field | Input | Rule (the error's words) |
|---|---|---|
| `business_name` | text | ≤ 120 characters |
| `default_everyone_level` | radio: Private (none) · Everyone can view · comment · edit | one of the four |
| `item_soft_budget` | number | 100–100,000, below the hard cap ("The soft budget must be below the hard cap.") |
| `item_hard_cap` | number | 100–100,000 |
| `batch_max_ops` | number | 1–5,000 |
| `max_upload_mb` | number | 1–1,024 |
| `op_retention_days` | number | 7–3,650 |
| `interval_version_days` | number | 1–3,650, at most the daily days ("Autosaves are thinned before the daily ones end.") |
| `daily_version_days` | number | 1–36,500 |
| `trash_days` | number | 1–3,650 |
| `live_enabled` | switch | yes or no |
A left-out field stays as it was (`bd_int(name, $keep, …)`); a database refusal (`23514`) is shown on the field its constraint names (`bd_settings_*_check`
→ that field; the soft/hard rule → `item_soft_budget`; the interval/daily rule → `interval_version_days`).

## Files (exactly these)
- `html/index.php` (replaces the kit's placeholder), `html/admin/{index,usage,settings,settings-save,trash-purge}.php`
- `app/features/home/{queries,present}.php`, `app/features/admin/{queries,present,write}.php`
- `app/views/home/index.php` (the kit's `home/placeholder.php` deleted), `app/views/home/partials/{section,first-run,folder-card}.php`,
  `app/views/admin/{index,usage,settings}.php`, `app/views/admin/partials/{usage-row,usage-card,trash-row,budget-bar}.php`
- `bin/worker.php`'s **`trash` pass made real** (the planning model's reconciliation — slices 1 and 5 left it to this slice): each board of `bd_trash_due()`
  → `trash_purge()` as the worker (no member; `bd_board_purge()` admits it), unlinking under the storage directory only; logs `trash.purge`
  (`source` `cron`) per board
- `tests/phase3/slice10/{run.sh,lib.php,home.php,admin.php,settings.php,purge.php,json.php,browser.mjs}`

## Query functions (signatures fixed)
- `home_sections(PDO, int $member): array{recent, favourites, shared, team_folders, mentions_waiting, first_run}` (each list ≤ 8, from `mcp_boards` /
  `board_recents` / `mcp_folders` / `mcp_notifications`)
- `usage_summary(PDO): array{boards, items, over_soft, idle_90, owner_left}` · `boards_usage(PDO, string $sort, ?int $idleDays, bool $ownerLeft, int $page): array{rows, total}`
  (over `mcp_boards_usage`; 50 a page) · `most_active_boards(PDO, int $days, int $limit): array` (`mcp_activity_log` rows with a `board_id` whose action is
  `item.*`, `comment.*` or `board.open` in the last `$days`, grouped by board: batches, distinct members, last at — joined to `mcp_boards` so only boards
  the caller sees) · `trash_all(PDO): array` (`mcp_trash`) · `failed_dispatches(PDO): array{failed, waiting}` (`mcp_agent_dispatches`)
- `settings_read(PDO): array` · `settings_save(PDO, array $fields): array` (the changed fields' old and new values) ·
  `trash_purge(PDO, string $board, string $storageDir): array{title, items, files_removed, files_left}` (`bd_board_purge()`, then `unlink()` of every
  returned path that `realpath()`s inside `$storageDir`; any other path counted in `files_left` and logged)

## Handlers
- `settings-save.php` (`settings_save`): `bd_handler_begin()`; `require_right('settings.manage')`; the fields as above (`bd_int`, `bd_choice`, `bd_yes`,
  `bd_text`), the two cross-field rules checked before the UPDATE; `bd_guard()` around `UPDATE bd_settings SET … WHERE id = 1`; `log_activity('settings.save',
  'settings', 1, ['before' => old values, 'after' => new values of the changed fields only])`; `record_id` 1; `location` `/admin/settings`;
  `HX-Trigger: settingsChanged`. An agent's call pauses before it arrives (`other`).
- `trash-purge.php` (`trash_purge`): `bd_handler_begin()`; `require_human()`; `require_right('trash.purge')`; `board` a uuid in `mcp_trash` (else 404);
  `bd_guard()` around `trash_purge()`; `log_activity('trash.purge', 'board', null, ['board_id' => $board, 'after' => ['title', 'items', 'files_removed',
  'files_left']])`; `record_id` = the board's id; `location` `/admin#trash`; `HX-Trigger: boardChanged`.

## MCP tools this slice makes real
`boards_usage`, `most_active_boards` (the pages read the same view and query the tools will).

## Manifest rows claimed
Screens (4): `home`, `admin`, `admin-usage`, `admin-settings`
Actions (2): `settings_save`, `trash_purge`

## Activity log events
`settings.save` (old and new values of the changed fields — numbers, a level, a yes/no, the business's name), `trash.purge` (the title, counts),
`screen.view` on the four screens.

## Status vocabulary
The budget bar: under the soft budget `success`, past it `warning`, within 10 % of the hard cap `danger`. The **left** badge `secondary`. Ids:
`home-section-{name}`, `home-new-board`, `home-new-from-template`, `admin-section-{name}`, `usage-row-{board}`, `usage-card-{board}`,
`trash-row-{board}`, `trash-purge-{board}`, `settings-form-field-{name}`.

## Out of scope for this slice
Editing the read-only settings (install-time); per-department defaults; an audit export
of the activity log; usage charts over time.

## Proof (`tests/phase3/slice10/run.sh`: scratch database `bd_dev10`; php -S on 9101, the fake kernel on 9102, the fake MaluDB/MaluMail on 9103; Playwright from `/var/www/web/node_modules` at 375 × 740 and 1280 × 800; the registry `--check`)
1. Home as a Member with fixtures: the four sections in order with at most 8 each; a private board of another never appears; mentions waiting counts
   unread mentions; a new Member with nothing sees the first-run state and the six templates; a Viewer sees no **New** buttons; an external sees only
   what is shared with them by name.
2. Admin as a Boards admin: every section; as a Member with `settings.manage` only (a fixture role): only Settings; as a plain Member: 403.
3. Usage: a 4,100-item fixture board shows amber, one at 9,500 red; `sort=idle`; `owner_left=1` lists the board of an inactive owner with Transfer;
   a Member gets 403 (and `mcp_boards_usage` answers nothing to them).
4. Settings: every field saved and read back; soft ≥ hard refused on `item_soft_budget`; interval > daily refused on `interval_version_days`; an
   out-of-range UPDATE run directly as `boards_rw` is refused by the database (`23514`) — the referee behind PHP's check; an agent's `settings_save`
   pauses (`other`); live off → the board page's mount says `data-live="0"` (slice 3's attribute).
5. Purge now: a trashed fixture board with two fixture files under the storage directory → gone with its items, both files unlinked, `files_left` 0; a
   fixture path outside the directory left and counted; a board not in the trash → 404; a Member → 403; an agent → refused (`people`). The worker's
   `trash` pass (`BD_WORKER_NOW` 31 days on): a board deleted 31 days ago is purged with its files, one deleted yesterday stays.
6. Most active this month: three boards with fixture activity, in order; `most_active_boards()` called as a plain Member lists only the boards that
   Member can see (a private board of another is absent).
7. Browser: home at 375 (one column of cards, no horizontal scroll) and 1280; the purge confirm; no console errors.
8. JSON mode on the four screens and both handlers; registry `--check`: 4 screens, 2 actions built — every screen and action of the manifest built.

## Decisions taken here
- The purge-now button is on the admin's page (the divergence above).
- The settings form is exactly the manifest's fields; the rest is read-only.
- Home's sections hold 8 cards each; first run shows the shipped templates.
- The budget bar's thresholds: the soft budget (amber) and within 10 % of the hard cap (red).
- `admin` opens on any one admin right and shows each section to its own right.

## Open questions
None.
