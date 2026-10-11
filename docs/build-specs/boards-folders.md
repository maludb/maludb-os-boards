# Build spec: boards, folders and sharing (slice 1 — the CRUD exemplar)

What exists at the end: every board a person can open, as **cards** (thumbnail, title, owner, my level, items, open threads, last activity,
the favourite star) with chips for Mine · Shared with me · Favourites · a folder; a new board from a full-page form; a board's **settings**
page (title, description, folder, background, the start view, the lock, delete); its **share** page (the board-wide level for everyone in the
business, people, agents and departments at view / comment / edit / full with the record picker, the owner, transfer); **folders** — mine
and team folders — that list only what the viewer may open and grant nothing; the **trash** (30 days, restore). Opening a card goes to
`/boards/{board}` (slice 2's canvas; the placeholder until then). **This slice is Boards' CRUD exemplar**: every later record screen copies
its files, its pages' shape, its handler's order (`bd_handler_begin()` → `require_right()` → the board gate (walls-and-sharing "PHP
helpers") → `bd_guard()` around the database's function → `log_activity()` → `bd_done()`) and its proof. It replicates the estate's CRUD
exemplars (Sales CRM's accounts and contacts, the Data Lake's catalog) — read `/srv/apps/datalake/docs/build-specs/catalog.md` and its built
files first.

Schema (db/006, 010, 011, 012, never modified): `boards`, `board_permissions`, `board_folders`, `board_folder_entries`, `board_favorites`,
`board_recents`, `bd_board_level()`, `bd_member_board_levels()`, `bd_my_board_levels()`, `bd_is_boards_admin()` (db/006); the search triggers
(db/010); `bd_require_level()`, `bd_board_create()`, `bd_board_update()`, `bd_board_lock()`, `bd_board_share()`, `bd_board_access_set()`,
`bd_board_transfer()`, `bd_board_delete()`, `bd_board_restore()`, `bd_folder_add()` (db/011); `mcp_boards`, `mcp_trash`, `mcp_board_permissions`,
`mcp_folders`, `mcp_folder_entries` (db/012). **The database is the referee**: the wall and the role cap, who may share and at what level,
the owner's row refused, a transfer only to someone who may make boards, the trash's owner-or-admin rule, the board-wide level never `full`.

## Divergences from the design
1. **Folders, their entries' removal and favourites are plain statements, not database functions** — the schema keeps no rule for them beyond
   ownership. `app/features/folders/write.php` checks `owner_member_id = me OR bd_is_boards_admin(me)` for `folder_update`, `folder_delete`,
   `folder_remove_board`; `folder_add_board` calls `bd_folder_add()` (which also checks the board is visible). A favourite needs `view` on the
   board (the gate), an unfavourite is own.
2. **`board_unshare` is `bd_board_share(…, 'none')`** — the function deletes the row; the handler reads `member` or `department` (exactly one).
3. **A new board is blank here.** "Start from a template →" on the form links to `/templates` (slice 8's; its placeholder until then);
   **Duplicate** on the settings page is slice 8's `board_duplicate`, shown disabled with "Built by slice 8".
4. **Thumbnails** are slice 2's (the island posts them). A card without one shows the board's initial on its background colour.
5. **The start view** is set here only as numbers (x, y, zoom) on the settings form; slice 2's canvas adds "Set the start view to this view"
   (the same `board_update` action).

## Who may do what
| Action | Right | Level / rule |
|---|---|---|
| board_create | `boards.create` | — (`bd_board_create` refuses a Viewer with its sentence) |
| board_update | — | `edit` |
| board_lock, board_delete, board_share, board_unshare, board_access_set, board_transfer | — | `full`; share/unshare/access/transfer pause for an agent (`other`), delete (`deletion`) |
| board_restore | — | the owner or `boards.admin` (`bd_board_restore`) |
| board_favorite / board_unfavorite | — | `view` / own |
| folder_create | `boards.view` | a team folder needs `boards.create` (a Viewer's folders are their own) |
| folder_update, folder_delete, folder_add_board, folder_remove_board | — | the folder's owner or `boards.admin`; adding needs `view` on the board |

## Screens (cards for the lists — the owner's rule; 375 px first)
| Screen id | Canonical URL | Purpose |
|---|---|---|
| `boards-list` | `/boards?q=&folder=&owner=&shared=&favorites=&updated=` | chips All · Mine · Shared with me · Favourites, a folder select (the `folder` picker), the search field (title, owner's name); `RecordCard`s from `find_boards()`: the thumbnail (or the initial), title, owner avatar + name, the level badge, "{n} items", "{n} open threads" when > 0, "updated {relative}", the star (toggles `board_favorite`/`board_unfavorite` inline, `hx-swap="outerHTML"` on the star); paged 24; New board (`board-add`) in the page header; an empty state with one button |
| `board-add` | `/boards/new?folder=&title=` | `page-header-form`: title (required, 200), description (2000), folder (the `folder` picker, mine and team folders); Save lands on `/boards/{board}`; the "Start from a template →" link |
| `board-settings` | `/boards/{board}/settings` | the board's header (crumbs Boards › title, the level badge); form: title, description, folder (add to / remove from my folders as chips), background (dots / grid / none + a colour from the theme's 8), start view (x, y, zoom — or none); Lock / Unlock (full); Delete (full, ✔ "Moves to the trash for 30 days"); Duplicate (disabled, slice 8). A viewer sees the values read-only |
| `board-share` | `/boards/{board}/share` | **Everyone in the business**: none / view / comment / edit (a select, `board_access_set`, ✔ when widening); **People, agents and departments**: rows of the principal (avatar, name, the "Agent" chip, a department's icon), the level select (view / comment / edit / full), Remove; **Add**: the `member` picker (people and agents) or the `department` picker + the level; the warning row "{name} cannot open Boards yet — ask a super-admin to grant it" when `needs_grant`; **Owner**: the owner's name and Transfer (the `member` picker narrowed to people, ✔); externals named as such ("external") — the page's full rules are walls-and-sharing "The share page" |
| `folders` | `/folders` | my folders and the team folders as cards (name, "Team" chip, board count — only boards I can see); New folder |
| `folder-view` | `/folders/{id}` | the folder's boards as `boards-list` cards (the same partial), Rename, Delete (✔ "The boards stay where they are"), Remove from folder on each card's menu |
| `folder-add` / `folder-edit` | `/folders/new?name=` · `/folders/{id}/edit` | name (120), team (a switch; `boards.create`) |
| `trash` | `/trash` | my deleted boards (a Boards admin: everyone's) as cards: title, deleted by, deleted {relative}, "purged on {date}", Restore |

## Files (exactly these)
- `html/boards/{index,form,save,settings,share,lock,delete,restore,share-set,share-remove,access-set,transfer,favorite,unfavorite}.php`,
  `html/folders/{index,view,form,save,delete,add,remove}.php`, `html/trash.php`
- `app/features/boards/{queries,present,write}.php`, `app/features/folders/{queries,write}.php`, `app/features/sharing/{queries,write}.php`
- `app/views/boards/{index,form,settings,share}.php` + `app/views/boards/partials/{card,cards,filters,star,share-row,share-add,owner,needs-grant}.php`,
  `app/views/folders/{index,view,form}.php`, `app/views/trash/index.php`
- `app/pickers.php` gains `board` (boards I can see, by title; param `min_level` = view | edit | full) and `folder` (mine and team, by name;
  param `own` = 1 for folders I may change)
- `tests/phase3/slice1/{run.sh,lib.php,boards.php,sharing.php,folders.php,trash.php,walls.php,json.php,browser.mjs}`

## Query functions (signatures fixed)
- `find_boards(PDO $pdo, array $filters, int $page): array{rows, total}` (over `mcp_boards`, + `mcp_folder_entries` for `folder`; newest activity first) ·
  `find_board(PDO $pdo, string $boardId): ?array` (the `mcp_boards` row + `folders` I keep it in + `everyone_level`)
- `board_permissions(PDO $pdo, string $boardId): array` (`mcp_board_permissions` + the owner, with `needs_grant` per row)
- `find_folders(PDO $pdo, ?string $q, bool $ownOnly): array` · `find_folder(PDO $pdo, int $id): ?array` · `folder_boards(PDO $pdo, int $folderId, int $page): array{rows, total}`
- `find_trash(PDO $pdo, int $page): array{rows, total}` (`mcp_trash`)
- `boards_for_pick(PDO $pdo, string $q, array $params, int $page): array` · `folders_for_pick(PDO $pdo, string $q, array $params, int $page): array`
- writes: `board_create(PDO $pdo, int $by, string $title, ?string $description, ?int $folderId): string` (`bd_board_create`) ·
  `board_update(PDO $pdo, string $boardId, int $by, array $fields): void` (`bd_board_update`, only the keys sent) ·
  `board_lock(PDO $pdo, string $boardId, int $by, bool $locked): void` · `board_delete(PDO $pdo, string $boardId, int $by): void` ·
  `board_restore(PDO $pdo, string $boardId, int $by): void` ·
  `board_share(PDO $pdo, string $boardId, int $by, string $kind, int $principalId, string $level): array` (`bd_board_share`; kind member|agent|department — a member id that is an agent is sent as `agent`; **a person shared by name is told**: the handler calls `bd_notify(person, 'share', "{sharer} shared {title} with you", level, board, 'board', board, sharer)` — no notice to a department or an agent, none on unshare) ·
  `board_access_set(PDO $pdo, string $boardId, int $by, string $level): void` · `board_transfer(PDO $pdo, string $boardId, int $by, int $newOwner): void` ·
  `board_favorite(PDO $pdo, string $boardId, int $memberId, bool $on): void` ·
  `folder_save(PDO $pdo, ?int $id, int $by, string $name, bool $team): int` · `folder_delete(PDO $pdo, int $id, int $by): void` ·
  `folder_add(PDO $pdo, int $folderId, string $boardId, int $by): void` (`bd_folder_add`) · `folder_remove(PDO $pdo, int $folderId, string $boardId, int $by): void`

## MCP tools this slice makes real
`find_boards` (the board resolver), `board_get`, `board_access`, `find_folders` (the folder resolver), `trash_list`.

## Manifest rows claimed
Screens (9): `boards-list`, `board-add`, `board-settings`, `board-share`, `folders`, `folder-view`, `folder-add`, `folder-edit`, `trash`
Actions (16): `board_create`, `board_update`, `board_lock`, `board_delete`, `board_restore`, `board_share`, `board_unshare`, `board_access_set`, `board_transfer`, `board_favorite`, `board_unfavorite`, `folder_create`, `folder_update`, `folder_delete`, `folder_add_board`, `folder_remove_board`

## Activity log events
`board.create`, `board.update` (the changed field names; a title ≤ 200 chars), `board.lock` (`locked`), `board.delete`, `board.restore`,
`board.share` / `board.unshare` (the principal's kind, id, name and the level before → after), `board.access_set` (before → after),
`board.transfer` (the old and new owner's ids), `board.favorite`, `board.unfavorite`, `folder.create|update|delete|add|remove` (the folder's
id and name, the board's id), `screen.view` on every screen. **Every row about a board carries `board_id`.** A description is logged by its
length, never its text.

## Status vocabulary
The level badge (`shared/level-badge.php`, the shell's). The trash: `text-bg-dark` "In the trash", the purge date in `text-muted`. A locked board:
the lock icon + `text-bg-warning` "Locked". "Team" `text-bg-info`. "Agent" `text-bg-light border`. "External" `text-bg-light border`.
Ids: `board-card-{id}`, `board-card-{id}-star`, `boards-filter-{chip}`, `board-form-field-{name}`, `board-settings-lock`, `board-settings-delete`,
`share-everyone-level`, `share-row-{kind}-{id}`, `share-row-{kind}-{id}-level`, `share-add-member`, `share-add-department`, `share-add-level`,
`share-owner-transfer`, `folder-card-{id}`, `folder-form-field-{name}`, `trash-card-{id}-restore`.

## Out of scope for this slice
The canvas and everything on it (2–5); comments (6); history and versions (7); templates and duplicate (8); search results (9); the home and
the admin's usage (10); emptying the trash (`trash_purge`, slice 10).

## Proof (`tests/phase3/slice1/run.sh`: scratch database `bd_dev1`; php -S on 9101, the fake kernel on 9102, the fake MaluDB/MaluMail on 9103; then the user-level Apache on 9107; Playwright; the registry `--check`)
1. Priya makes a board from the form → `/boards/{board}`; a Viewer's POST is refused with the database's sentence; a 201-character title is refused
   on the field.
2. The list per actor: Priya sees hers; Bea sees nothing until shared; the chips filter; the search finds by title and owner; the star toggles inline.
3. Share: Sales at edit (Dana, a Viewer in Sales, shows "Can comment" — the cap); everyone at view; Ann (external) by name; the Facilitator (agent)
   at edit; Sam (no grant) with the `needs_grant` row; the owner refused a row; a non-owner's share 403 with the sentence; unshare; an agent's
   `board_share` is registered `other` (pauses in the kernel); Ann, shared by name, has one `share` notification (and an outbox email), Sales' members none.
4. Transfer to Kim (the previous owner keeps edit); to Dana refused ("A board passes only to a person who may make boards").
5. Settings: update (a viewer sees read-only; a forged POST 403), lock (an editor refused), background, start view numbers.
6. Delete → the trash (the card gone from lists, in `/trash` with the purge date); restore by the owner; by another Member 404 ("There is no such
   board in your trash."); a Boards admin sees every deleted board.
7. Folders: mine and a team folder; a folder shows only boards the viewer can open (a private board in Marco's team folder is invisible to Bea);
   add, remove, rename, delete (the boards remain); another member's folder change 403.
8. The wall: a stranger's GET of any board page is 404 (never 403); JSON mode on every screen and handler answers the manifest's record_id.
9. Playwright at 375 and 1280: cards, the share page's pickers, no horizontal scroll, no console errors; JavaScript off saves every form.
10. Registry `--check`: 9 screens and 16 actions built; the 5 approvals of this slice in step.

## Decisions taken here
1. Folders, folder entries' removal and favourites are written by PHP under the ownership rule (divergence 1); no new database function.
2. The share page sends an agent's member id as principal kind `agent`, a person's as `member` (the database refuses a mismatch).
3. Widening the board-wide level asks ✔; narrowing it does not.
4. A team folder needs `boards.create`; a Viewer's folders are their own.
5. The `board` picker's `min_level` param lets later slices narrow it (slice 8's duplicate needs `view`, slice 4's nothing).

## Open questions
None.

## Built and proven (2026-10-10)
Boards, folders and sharing stand — the CRUD exemplar every later record screen copies. **The list**: every board I can open as RecordCards
(the initial on the board's background colour until slice 2's thumbnails, the title linking with `?back=`, the owner — "You" for mine —, the
level badge, Locked, items, open threads, "updated …", the star toggling inline over HTMX) with the chips All · Mine · Shared with me ·
Favourites, the search by title and owner, the folder (the `folder` picker), "updated within" 7 / 30 / 90 days; paged 24; an empty state with
one button. **New board**: the page-header form (title, description, a folder of mine), private by default, landing on `/boards/{board}`.
**Settings**: title, description, the background (dots, grid or plain, and the theme's eight colours as swatches), the start view (x, y, zoom
— or none), the folders I keep it in (add with the picker, remove), Lock / Unlock and Delete for its owners, Duplicate shown disabled (slice
8); read-only below edit. **Share**: the owner and Transfer (people only, ✔), everyone in the business (none / view / comment / edit — a
widening asks first, in the page, with and without JavaScript), people, agents and departments with their level and Remove, Add with the
`member` and `department` pickers; Agent, External and Department chips, a Viewer's cap said, a principal with no Boards grant flagged; read-only
below full. **Folders**: mine and the team folders as cards, a folder's boards as the list's cards (only those the viewer may open), New,
Rename, Delete (the boards stay), Remove from folder. **The trash**: my deleted boards (a Boards admin's: everyone's) with who deleted them and
the purge date, Restore. A person shared by name gets one `share` notice and its email by their preferences; a department, an agent, an
unshare tell nobody. Every handler keeps the exemplar's order — `bd_handler_begin()` → the right or the board gate → the fields →
`bd_guard()` around the database's function → `log_activity()` with `board_id` → `bd_done()` — and answers JSON with the manifest's
`record_id`. The registry reads **11 screens and 18 actions built, 15 stubs**.

**Proven** by `tests/phase3/slice1/run.sh` (scratch `bd_dev1`): **188 checks under php -S and the same under a real Apache** — boards 44,
sharing 41, folders 31, trash 17, walls 9, JSON 8, browser 36 (375 and 1280, the pickers through the modal, the widening question, the star,
every control ≥ 44 px, no console error; with JavaScript off every form saves, a folder pre-selected by the query string), the registry 2 —
then Phase 2's suite, which now follows the placeholders still owed (258 under php -S, 266 under Apache). The kit (87), the schema (167) and
Phase 1's checks (66) green.

**Decisions taken in the build:**
1. **Six actions of this slice pause for an agent, not five**: `board_delete` and `folder_delete` (deletion), `board_share`, `board_unshare`,
   `board_access_set`, `board_transfer` (other) — the manifest and `maludb-os.json` already said so; this spec's "5 approvals" was a miscount.
2. **A Boards admin reaches any folder by its id** (the manifest's "own or boards.admin"), although `mcp_folders` lists a person's own folder
   only to them: `find_folder()` falls back to the table for an admin, and a folder's page lists its boards through `board_folder_entries` once
   the folder is found — still only the boards the viewer may open. The list's folder filter keeps `mcp_folder_entries`.
3. **board_create checks no right in PHP**: `bd_board_create()` refuses a Viewer in its own words (403), as the Who table says; the form itself
   is behind `boards.create`.
4. **The widening question lives in the share page** (`board_access_set` without `confirm=1` from a browser renders the page with the question at
   its top) — no extra partial; a JSON caller's ✔ or pause comes before the call.
5. **An unshare of someone with no row is 404**; a share naming both a person and a department, or neither, is refused on `member`.
6. **The pickers carry a board's uuid**: `picker_label()` and the picker's `selected` accept a uuid as well as an integer id; `board` takes
   `min_level` (view | edit | full), `folder` takes `own` (1).
7. **The start view** is taken as the form's three numbers or an agent's "x y zoom" / none; zoom 0.05 to 8 (the island's range).
8. **When the business has no name**, the share page says "Everyone in the business".
