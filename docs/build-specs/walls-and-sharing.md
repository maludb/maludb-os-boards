# Build spec: walls and sharing — who sees a board, and how a board is shared (design §3, §4; D2, D3, D11, D12)

Who may open a board, comment on it, draw on it and share it is decided in **one place: the database** (db/006, db/011). PHP asks it; the
MCP views join it; the relay re-checks it. This spec is what every slice obeys about the wall, and what slice 1 (`boards-folders.md`, the
CRUD exemplar) builds for sharing, folders, favourites and the trash.

## The rule

- **A level** is `none`, `view`, `comment`, `edit` or `full` (Miro's viewer, commenter, editor, owner). A person's level on a board is
  `bd_board_level(board, member)` (db/006): the highest of
  - the owner (`boards.owner_member_id`) → `full`;
  - a **Boards admin** — a *person* holding `boards.admin` (the super-admin holds it) → `full`, on every board;
  - their `member` row, or an agent's `agent` row, in `board_permissions`;
  - a `department` row for a department they belong to **now** (`department_members.left_at IS NULL`);
  - the `everyone` row, **unless they are external** (`members.is_external`) — `everyone` includes agents (D11);
  - a `business` or `shipped` template → `view`, for a non-external member.

  The result is then **capped by the role**: `bd_level_cap()` (db/004) is `comment` for a Viewer (no `boards.edit`). No Boards grant
  (`members.capability IS NULL` — a super-admin included), an inactive member, or no board → `none`.
- `bd_member_board_levels(member)` is the same rule for every board in one statement. The views (`mcp_*`, db/012) join
  `bd_my_board_levels()` once per statement (the kernel's scale rule); the proof checks the two forms agree for every member on every
  board.
- **A board in the trash** (`deleted_at` set) is opened by nobody: `bd_require_level()` answers "There is no such board" for it, the
  views leave it out, `bd_live_check()` closes its sockets. It is seen only in `mcp_trash` — by its owner and the Boards admins.
- **404, never 403, for a board one cannot see**: `none` and "no such board" are the same answer everywhere (screens, doors, actions,
  JSON, MCP), so a title never leaks. **403** is for a board one *can* see but may not change at the level asked ("You can see this board
  but not change it.").
- **Folders grant nothing.** A folder lists only the boards its viewer may open.

## PHP helpers

`app/features/boards/wall.php` (built and proven by Phase 2, loaded by the bootstrap) — the only PHP that touches a level, and it decides nothing:

```php
/** The board row for the caller at least at $need ('view'|'comment'|'edit'|'full'), or a 404/403 answer. */
function bd_board_or_404(PDO $pdo, ?string $board, string $need = 'view'): array
/** The caller's level (bd_my_level), for showing and hiding controls — never as the gate. */
function bd_level(PDO $pdo, string $board): string
/** Rank comparison of two level names, for the templates' show/hide only. */
function bd_level_at_least(string $have, string $need): bool
```

`bd_board_or_404()`: a `$board` that is not a uuid → `refuse(404, 'There is no such board.')`; then
`bd_guard($pdo, fn() => one_value($pdo, 'SELECT bd_require_level(:b::uuid, :m, :need)', …))` — the database answers `P0002` (404) or
`42501` (403) with its own sentence — and returns the board's row from `mcp_boards` (with `my_level`). **Never** compute a level in PHP,
never read `board_permissions` to decide, never special-case an admin or an agent: an agent's action token is its member, and its level
is the same function's answer. The functions of db/011 (`bd_board_share()`, `bd_comment_create()`, …) check the level themselves; a
handler calling one still starts with `bd_board_or_404()` at the action's level, so a refusal is the same whichever way it comes.

## The share page

Screen `board-share` (`/boards/{board}/share`, `html/boards/share.php`; level `view` to read, `full` to change):

- **The owner**, first: avatar, name, "Owner", and for `full` a **Transfer** button → a form choosing a person through the record picker
  (source `board_new_owners`: active humans holding `boards.create`) → action `board_transfer` (✔ confirm; the previous owner keeps
  `edit`).
- **Everyone in {business name}**: a select `none` / `view` / `comment` / `edit` → `board_access_set`. Under it, the sentence "Externals
  never see a board through this — share with them by name."
- **People, agents and departments**: one list, each row a principal with a kind badge (`Agent` — `info`; `Department` — `secondary`;
  `External` — `warning`), its level select (`view`, `comment`, `edit`, `full`) → `board_share`, and Remove → `board_unshare` (✔). A
  Viewer's row adds "(a Viewer: at most comment)"; a row whose `needs_grant` was true says "{name} has no access to Boards yet — a
  super-admin grants it in the OS".
- **Add**: the record picker with two sources — `board_principals` (active members with a Boards grant, people and agents, the owner
  left out, a kind badge on agents) and `departments` (live departments) — plus a level select (default `edit` for a person or an agent,
  `view` for a department) → `board_share`. One principal at a time.
- A caller below `full` sees the same list read-only, with "Only the board's owners change who can open it."
- **The level badge** (header, cards, lists), from the locked vocabulary: `full` → `dark` ("Owner" for the owner, "Full" otherwise),
  `edit` → `success` "Can edit", `comment` → `info` "Can comment", `view` → `secondary` "Can view".
- Every change logs `board.share` / `board.unshare` / `board.access_set` / `board.transfer` with `board_id`, the principal (kind and id)
  and the level before and after; an agent's call pauses for approval (`other`) before the handler runs.

## Agents and sharing

- An agent is a member: it sees a board through its `agent` row, a department it belongs to, or `everyone` (D11). It is shared like a
  person, on the same page.
- **A mention never grants.** `bd_comment_create()` answers `needs_share` — the agents mentioned who cannot see the board — and makes no
  dispatch for them. The comment handler (slice 6) answers with a banner in the rail:
  "**{Agent} can't see this board.** Share it at edit so it can work here?" — [Share at edit] [Share at comment] [Not now].
  - The buttons post `board_share` (member = the agent). The banner shows them only to a caller at `full`.
  - A caller below `full` reads instead: "Ask the board's owner to share it with {Agent}."
  - After a share, the banner says "Shared — mention @{Agent} again to ask." Nothing re-dispatches by itself.
- `not_shared` (people mentioned who cannot see the board) shows "{names} can't see this board and were not told." with the same Share
  buttons for `full`.
- An agent's own comment dispatches nobody; an agent's `board_share`, `board_unshare`, `board_access_set`, `board_transfer` pause for a
  person (`other`).

## Folders and favourites

- `board_folders` belong to a person; `is_team` makes one visible to every non-external Member. A folder's owner (or a Boards admin)
  renames it, makes it a team folder, deletes it (`deletion` for an agent; its boards stay where they are) and adds or removes boards
  (`folder_add_board` needs `view` on the board — `bd_folder_add()` checks).
- A folder's page and `mcp_folders.board_count` show only the boards the viewer may open; a team folder holding a private board shows it
  to nobody but those who can open it.
- **Favourites** and **recents** are the person's own (`board_favorites`, `board_recents`), never a grant. Favouriting needs `view`;
  the canvas page writes the recent.

## The trash and purge

- `board_delete` (level `full`; `deletion` for an agent) → `bd_board_delete()`: the board leaves every list, screen, door, search and
  socket at once.
- Screen `trash` lists `mcp_trash` (one's own; a Boards admin everyone's) with "purged after {date}" (`trash_days`, 30).
  `board_restore` (owner or `boards.admin`) brings a board back with its permissions intact.
- `trash_purge` (`trash.purge`, people only; ✔) and the worker after `trash_days` (`bd_trash_due()`) call `bd_board_purge()`, which
  deletes the rows and answers the files to unlink. The caller unlinks each path **only when it lies under `BD_STORAGE_DIR`**
  (`realpath` prefix check) and logs `trash.purge` with the board's id, title and item count.

## What every slice must do

- [ ] Every screen, door and handler about a board starts with `bd_board_or_404($pdo, $board, <the level the manifest's Who names>)`
      — or answers through a `bd_*` function that raises the same SQLSTATEs.
- [ ] Every list reads an `mcp_*` view or a `bd_*` function joined to `bd_my_board_levels()`; no query reads `boards` or `board_items`
      for a list without the wall.
- [ ] No level is computed in PHP or JavaScript; `bd_level_at_least()` only shows or hides a control, and the server refuses anyway.
- [ ] A board the caller cannot see answers 404 in HTML, HTMX and JSON alike; a level too low answers 403 with the database's sentence.
- [ ] Agents get no special case: the action token's member, the same functions.
- [ ] Every log row about a board carries `board_id`.
- [ ] Every MCP tool of the slice reads only `mcp_*` views and `bd_*` read functions.

## Proof

Each `tests/phase3/sliceN/run.sh` includes the wall's checks for its own screens, doors and actions, with the fixture's actors (Priya a
Member owner, Bea a Member not shared, Dana an internal Viewer in Sales, Ann an external Viewer, Marco a Boards admin, the Facilitator an
agent, Sam with no Boards grant):

- every GET screen and door of the slice, for a member who cannot see the board → **404** (and the body never names the board);
- every POST action of the slice, for that member → 404, nothing written, nothing logged but the refusal;
- for a Viewer shared at `edit` → capped: every write above `comment` → **403** with the sentence; comments allowed;
- an external with `everyone` at `edit` and no row → 404; with a member row → the capped level;
- an agent's action token → the same answers as a person with the agent's rows;
- a share removed, a department left, a board trashed → the next request 404, and an open socket closed at the next check (slice 3);
- a Boards admin → `full` on a board nobody shared with them; a super-admin with no Boards grant → 404;
- slice 1 alone: the share page's every control at each level, `needs_grant`, transfer, the folders' visibility, the trash and purge
  (files unlinked only under `BD_STORAGE_DIR`).

## Decisions taken here

1. The picker sources are `board_principals` (people and agents, not the owner), `departments`, and `board_new_owners` (humans holding
   `boards.create`) — registered in `app/pickers.php` by slice 1.
2. The default level when adding a person or an agent is `edit`; when adding a department, `view`.
3. After sharing with an agent from the needs-share banner, nothing re-dispatches; the person mentions it again.
4. A purge unlinks a path only under `BD_STORAGE_DIR` (`realpath` prefix), else it logs and skips that path.
5. The level badges' colours: full `dark`, edit `success`, comment `info`, view `secondary`; kind badges Agent `info`, Department
   `secondary`, External `warning`.

## Open questions

- (none)
