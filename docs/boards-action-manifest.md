# Boards Action Manifest

2026-10-10 · **Phase 1, for the checkpoint**

> The registry that the command bar (through the kernel's chat endpoint), the kernel's Actions MCP and the screens resolve against.
> A screen or action missing here cannot be reached by voice or by an agent — unfinished design, like a question with no tool.
> Pairs with `db/` (tables and functions), `docs/boards-mcp-tool-surface.md` (read tools) and the specs in `docs/build-specs/`.
> `bin/build_action_registry.php` generates `mcp/action_registry.json` from this file; an action whose file does not exist yet is
> registered but not exposed. **The approval categories here ARE `maludb-os.json` `approvals[]`**: `php bin/build_action_registry.php
> --approvals` (or `php bin/sync_approvals.php`) rewrites that block from this file, and `--check` (in every proof) fails when the
> registry or the approvals are stale. The installer reads the resolve block from `deploy/kernel-registry-boards.json`.

## Conventions

### Screens
- **Ids** are kebab-case: `{entity}-list`, `{entity}-add`, `{entity}-view`, `{entity}-edit`, plus named screens.
- **Canonical URLs** need the rewrites in `deploy/apache-boards.conf` (and `tests/dev_router.php`): `/boards/{board}` → `boards/view.php?board=`,
  `/boards/{board}/{page}` → `boards/{page}.php?board=`, `/templates/{board}` → `templates/view.php?board=`, and the estate's `/{x}/new` →
  `form.php`, `/{x}/{id}/edit` → `form.php?id=`, `/{x}/{id}` → `view.php?id=`. **A board is named by its uuid** (a folder by its integer id).
  The lists, the outline, the comments, the history, the templates and the settings are read at 375 px first; **the canvas is designed at
  1280 px and works at 375 px** for viewing, pan, pinch-zoom, adding a sticky, moving, commenting and opening the outline (design §9). No
  screen is a modal; the record picker is the one sanctioned modal. The canvas's right rail is an HTMX panel beside the canvas (a full-width
  sheet at 375 px), never an overlay on a desktop.
- **Prefill params** become query-string values the GET controller reads.
- **Every screen partial stamps** `data-screen`, `data-entity` and `data-record-id` (and `data-board-id` on a board's pages) on
  `#page-content`, so "share this", "who can see it", "add a sticky here", "summarise this board" resolve against the page the user is on.
  The canvas page also stamps the current selection (`data-selection`, item ids) and the view (`data-view`, x y zoom) for the command bar.
- **Doors that are not screens** have no row here:
  - `GET /boards/{board}/state` — every live item and the last seq, for the canvas's first load;
  - `GET /boards/{board}/ops?after=` — the catch-up after a reconnect, and the 3-second poll when the socket is down;
  - `POST /boards/{board}/live-ticket` — a fresh 60-second ticket, which changes nothing;
  - `POST /boards/{board}/thumbnail` — the cards' preview, posted by one editor's client at most every five minutes;
  - `GET /boards/{board}/export` (the SVG dialect as a download) and `POST` (a PNG export the client made, logged `board.export`);
  - `GET /files/{id}` and `GET /files/{id}/{size}` — an image, a file or a thumbnail behind the board's wall;
  - `wss /live` — the relay, never PHP;
  - the picker (`/pick/`), `/sso`, `/sso/logout`, `/api/v1/health`.

  The canvas's commits are the action `ops_commit` (people only); every one of its batches goes through `bd_commit_ops()`, the same
  function the agents' item actions use.

### Actions
- **Name** `{entity}_{verb}`. The **log event** `{entity}.{verb}` is what the handler writes with `log_activity()`, with `board_id` on every
  row that concerns one. It is also what the kernel's approval policies match (`approval_policies.action_pattern` = the registry's
  `log_event`), so **an action that pauses for an agent has a log event no free action shares**:
  - `item.delete` beside `item.update` and `item.move`;
  - `board.delete` beside `board.restore`;
  - `board.share` beside `board.favorite`;
  - `comment.delete` beside `comment.resolve`;
  - `version.restore` beside `version.save`;
  - `template.publish` beside `template.save`.

  The canvas's own door logs **one event per committed batch**, named by its verb: `item.create`, `item.update`, `item.move`,
  `item.delete`, `item.text` (an edited text, once per editing session), or `item.batch` for a mixed batch. It is people-only, so no
  agent's call is matched against it.
- **Endpoints** are POST to `/{base}/{file}` with `bd_handler_begin()` (`require_post()`, `require_login()`, `verify_csrf()` or the relayed
  action token), the right (`require_right()`), and the board's level (`bd_require_level($board, 'view'|'comment'|'edit'|'full')`). Below
  `view`, the answer is 404: the same nothing as a board that does not exist. Every handler wraps the database's own function in
  `bd_guard()` (`bd_commit_ops()`, `bd_board_share()`, `bd_comment_create()` …), then calls `log_activity()`, `emit_action_status()` and
  `bd_done()`.

  **PHP never re-implements a rule the database keeps:** the wall, the cap, the locks, the cascades, last-writer-wins, undo, a
  restore's ops, mentions and dispatches. A create's `location` ends in the new record's id.
- **Params:** **bold** = required; `[]` = repeated; parentheses = a hint (no comma or semicolon inside; no note after the last
  parameter). A param named for a record accepts an id or a name, resolved through the tool surface's resolvers: `board`,
  `source_board`, `template`, `folder`, `member`, `new_owner`, `department`. These are ids only: `item`, `items`, `comment`, `version`,
  `notification`, `token`, `dispatch`. `any field of X` means X's fields, all optional: a partial update.
- **Items in an action are named by id.** An agent finds them with `board_search` or `board_read_region` on the board it names (the
  records MCP). Coordinates are **the centre**, absolute on the canvas unless `frame` is given (then relative to the frame's top-left), in
  Miro's units (a sticky is about 200 × 200).
- **Who** is enforced in the endpoint:
  - **a level** on the board (`view`, `comment`, `edit`, `full`) — the caller's, from `bd_board_level()`, capped by the role;
  - **a right** held through `bd_has_right()`: `boards.create`, `boards.edit`, `templates.publish`, `boards.admin`, `trash.purge`,
    `settings.manage`, `agents.manage`, `tokens.own`;
  - **`own`**, the caller's own.

  A super-admin holds every right. An agent is granted like a person.
- **Undo** is the inverse `undo_last` applies. `ops_undo` takes back the caller's own last batches on that board (within the hour, D8).
  `—` means it cannot be undone, and the reply says so.
- **Confirm (✔)** = the command bar asks first: it destroys, or it widens or narrows who sees a board.
- **Agent approval** names the kernel's category that pauses the action when an **agent** performs it (design §5):
  - `deletion` — someone's work or a board goes;
  - `other` — who sees the business's work, a roll-back, a template for everyone, the settings.

  People are never paused. Creating, editing, arranging, connecting, framing, commenting, saving a version and undoing one's own work
  are free. The categories are exactly `maludb-os.json` `approvals[]`.
- **Agent** says whether the endpoint admits an agent's token at all: `yes`, or `people` (the handler calls `require_human()`). An action
  that pauses is still `yes`: the pause comes first, then the call.
- **record_id** is what the JSON answer's `record_id` names: the record made (a create) or the record acted on. For an item action, it
  is the committed batch's `seq`, which is what `ops_undo` takes back.
- **Refresh:** a board action returns `HX-Trigger: boardChanged`, a folder action `folderChanged`, a comment action `commentChanged`.
  Item actions need no refresh: the relay and the poll carry them to every open canvas.
- **Log payloads** (what `before`/`after` carry) never hold a file path, a token or a ticket. They do hold ids, titles, counts by verb
  and type, levels, principals and the batch's box. A text edit logs the item and the first 200 characters of the new text, never more.

### Result contract
```json
{"status": "success", "did": "Added 5 stickies in a grid in Went well", "record_id": 4182, "undo_id": "act_31", "refresh": null}
{"status": "pending_approval", "did": "Deleting 3 items waits for a person's approval", "approval_request_id": 17}
{"status": "refused", "did": "You can see this board but not change it", "errors": {"board": "your level is view"}}
{"status": "success", "did": "Shared Q4 retro with Marketing at edit", "record_id": "6ddfabfe-…", "warnings": ["This board holds 4,100 items; boards stay quick under 4,000."]}
```

## The shell (Phase 2 — sso-shell)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `activity` | `/activity` | my own trail here: what I did on which boards |
| `settings-tokens` | `/settings/tokens/` | my MCP tokens for my own AI tools: mint (shown once), revoke |

Actions (base `/settings/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who | Agent | record_id |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `token_mint` | `tokens/mint.php` | **label** (up to 80 characters), expires_in_days (blank for never) | token_revoke | | | `token.mint` | own and tokens.own | people | the token |
| `token_revoke` | `tokens/revoke.php` | **token** | — | ✔ | | `token.revoke` | own | people | the token |

## Boards, folders and sharing (slice 1 — the CRUD exemplar)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `boards-list` | `/boards` | every board I can open, as cards with thumbnails: mine, shared with me, favourites, by folder (params: `q`, `folder`, `owner`, `shared`, `favorites`, `updated`) |
| `board-add` | `/boards/new` | to make a new board: its title, a description, a folder (params: `folder`, `title`) |
| `board-settings` | `/boards/{board}/settings` | a board's settings: title, description, folder, background, the start view, the lock, duplicate, delete |
| `board-share` | `/boards/{board}/share` | who can open a board and at what level: everyone in the business, people, agents, departments, the owner, transfer |
| `folders` | `/folders` | my folders and the team folders |
| `folder-view` | `/folders/{id}` | one folder's boards, as cards |
| `folder-add` | `/folders/new` | to make a folder, mine or a team folder (params: `name`) |
| `folder-edit` | `/folders/{id}/edit` | to rename a folder or make it a team folder |
| `trash` | `/trash` | my deleted boards (an admin: everyone's), with when each is purged, to bring one back |

Actions (base `/boards/`, folders under `/folders/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who | Agent | record_id |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `board_create` | `/boards/save.php` | **title** (up to 200 characters), description, folder | board_delete | | | `board.create` | boards.create | yes | the board |
| `board_update` | `/boards/save.php` | **board**, title, description, background (dots or grid or none), start_view (x y zoom — or none) | restore prior | | | `board.update` | level edit | yes | the board |
| `board_lock` | `/boards/lock.php` | **board**, **locked** (yes or no) | board_lock | | | `board.lock` | level full | yes | the board |
| `board_delete` | `/boards/delete.php` | **board** (to the trash for 30 days) | board_restore | ✔ | deletion | `board.delete` | level full | yes | the board |
| `board_restore` | `/boards/restore.php` | **board** (from the trash) | board_delete | | | `board.restore` | owner or boards.admin | yes | the board |
| `board_share` | `/boards/share-set.php` | **board**, member (a person or an agent), department, **level** (view or comment or edit or full) | board_unshare | ✔ | other | `board.share` | level full | yes | the board |
| `board_unshare` | `/boards/share-remove.php` | **board**, member (a person or an agent), department | board_share | ✔ | other | `board.unshare` | level full | yes | the board |
| `board_access_set` | `/boards/access-set.php` | **board**, **level** (none or view or comment or edit — for everyone in the business) | restore prior | ✔ | other | `board.access_set` | level full | yes | the board |
| `board_transfer` | `/boards/transfer.php` | **board**, **new_owner** (a person who may make boards) | — | ✔ | other | `board.transfer` | level full | yes | the board |
| `board_favorite` | `/boards/favorite.php` | **board** | board_unfavorite | | | `board.favorite` | level view | yes | the board |
| `board_unfavorite` | `/boards/unfavorite.php` | **board** | board_favorite | | | `board.unfavorite` | own | yes | the board |
| `folder_create` | `/folders/save.php` | **name** (up to 120 characters), team (yes or no — seen by every Member) | folder_delete | | | `folder.create` | boards.view | yes | the folder |
| `folder_update` | `/folders/save.php` | **folder**, name, team (yes or no) | restore prior | | | `folder.update` | own or boards.admin | yes | the folder |
| `folder_delete` | `/folders/delete.php` | **folder** (its boards stay where they are) | — | ✔ | deletion | `folder.delete` | own or boards.admin | yes | the folder |
| `folder_add_board` | `/folders/add.php` | **folder**, **board** | folder_remove_board | | | `folder.add` | own or boards.admin | yes | the folder |
| `folder_remove_board` | `/folders/remove.php` | **folder**, **board** | folder_add_board | | | `folder.remove` | own or boards.admin | yes | the folder |

## The canvas (slice 2 — THE FIRST EXEMPLAR)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `board-view` | `/boards/{board}` | to open a board: the canvas, who is here, the toolbars, the right rail (params: `x`, `y`, `zoom`, `item`, `comment`) |
| `board-outline` | `/boards/{board}/outline` | a board as a list: frames in order, their items, the connectors, the open comments — the 375 px reader and the screen reader's view |

Actions (base `/boards/`, the agents' item actions under `/items/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who | Agent | record_id |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `ops_commit` | `/boards/ops.php` | **board**, **ops** (the canvas's JSON batch), client_batch_id, client_id | — | | | `item.batch` | level edit | people | the batch's seq |
| `item_create_batch` | `/items/create.php` | **board**, **type** (sticky_note or shape or text), texts[] (one item per text), frame (an item id), layout (grid or column or row — grid by default), columns (1 to 20), x (the first item's centre), y, color (a sticky colour or a fill), shape (rectangle or ellipse or diamond and the like), width, height | ops_undo | | | `item.create` | level edit | yes | the batch's seq |
| `item_update_batch` | `/items/update.php` | **board**, **items[]** (item ids), text (only with one item), color, x (only with one item), y, width, height, rotation, frame (an item id — or none for the canvas), front (yes or no — bring to front or send to back), locked (yes or no) | ops_undo | | | `item.update` | level edit | yes | the batch's seq |
| `item_delete_batch` | `/items/delete.php` | **board**, **items[]** (item ids — a frame takes its children unless keep_children) , keep_children (yes or no) | ops_undo | ✔ | deletion | `item.delete` | level edit | yes | the batch's seq |
| `ops_undo` | `/items/undo.php` | **board**, count (1 to 10 — my last batches on this board within the hour) | — | | | `ops.undo` | own | yes | the batch's seq |

## Live (slice 3 — THE SECOND EXEMPLAR)

The relay, the tickets, presence, cursors, drag previews, the edit soft-lock and the agent's cursor are doors and the island's, not screens
or actions: the ticket door (`POST /boards/{board}/live-ticket`) changes nothing, the socket (`wss /live`) is the relay's, and every
commit is still `ops_commit` or an item action. This slice claims no row.

## Connectors and frames (slice 4)

Actions (base `/items/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who | Agent | record_id |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `connector_create` | `connect.php` | **board**, **from** (an item id), **to** (an item id), shape (straight or elbowed or curved — elbowed by default), caption, arrow (end or start or both or none — end by default) | ops_undo | | | `item.connect` | level edit | yes | the batch's seq |
| `frame_create` | `frame.php` | **board**, **title**, around[] (item ids the frame wraps and takes in), x, y, width, height | ops_undo | | | `item.frame` | level edit | yes | the batch's seq |

## Pen, images and files (slice 5)

Actions (base `/files/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who | Agent | record_id |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `file_upload` | `upload.php` | **board**, **file** (png or jpg or gif or webp or svg or a document — up to 25 MB) | — | | | `file.upload` | level edit | people | the attachment |

## Comments, mentions and notifications (slice 6)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `board-comments` | `/boards/{board}/comments` | a board's threads, open first, each with where it is pinned (params: `open`, `mine`) |
| `notifications` | `/notifications` | my notices, unread first |
| `settings` | `/settings/` | my preferences: which notices by email and by text, the morning digest |

Actions (base `/comments/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who | Agent | record_id |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `comment_create` | `create.php` | **board**, **text** (@Name mentions a person or an agent or a department), item (an item id to pin it to), x (a point on the canvas when no item), y, context[] (the item ids it is about) | comment_delete | | | `comment.create` | level comment | yes | the comment |
| `comment_reply` | `reply.php` | **comment**, **text** | comment_delete | | | `comment.reply` | level comment | yes | the comment |
| `comment_resolve` | `resolve.php` | **comment** | comment_reopen | | | `comment.resolve` | level comment | yes | the comment |
| `comment_reopen` | `reopen.php` | **comment** | comment_resolve | | | `comment.reopen` | level comment | yes | the comment |
| `comment_edit` | `edit.php` | **comment**, **text** | restore prior | | | `comment.edit` | own | yes | the comment |
| `comment_delete` | `delete.php` | **comment** (its replies go with a thread) | — | ✔ | deletion | `comment.delete` | own or level full | yes | the comment |
| `prefs_save` | `/settings/prefs.php` | email_enabled (yes or no), text_enabled (yes or no), kinds[] (mention or reply or comment or share or agent_replied), text_kinds[], digest (yes or no) | restore prior | | | `settings.prefs` | own | yes | the prefs |
| `notification_read` | `/notifications/read.php` | **notification** | — | | | `notification.read` | own | yes | the notification |
| `notifications_read_all` | `/notifications/read-all.php` | all (yes — every unread notice of mine) | — | | | `notification.read_all` | own | yes | the count |

## History, versions and restore (slice 7)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `board-history` | `/boards/{board}/history` | what changed on a board — who, what, where, click to fly there — and its versions; a version's preview (params: `version`, `member`, `source`, `since`) |

Actions (base `/boards/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who | Agent | record_id |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `version_save` | `version-save.php` | **board**, **label** (a name for this moment — up to 120 characters) | — | | | `version.save` | level edit | yes | the version |
| `version_restore` | `version-restore.php` | **board**, **version** (its number — the board as it is now is saved first) | version_restore | ✔ | other | `version.restore` | level full | yes | the batch's seq |

## Templates, duplicate and export (slice 8)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `templates` | `/templates` | the templates gallery: shipped, the business's and mine, by category (params: `category`, `scope`) |
| `template-view` | `/templates/{board}` | a template's preview, and Use |

Actions (base `/templates/`, duplicate under `/boards/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who | Agent | record_id |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `board_duplicate` | `/boards/duplicate.php` | **board**, title, folder | board_delete | | | `board.duplicate` | boards.create and level view | yes | the new board |
| `template_use` | `/templates/use.php` | **template**, title, folder | board_delete | | | `template.use` | boards.create | yes | the new board |
| `template_save` | `/templates/save.php` | **board**, **title**, category (retrospective or brainstorm or strategy or planning or diagram or workshop or other) | board_delete | | | `template.save` | boards.create and level view | yes | the template |
| `template_publish` | `/templates/publish.php` | **board**, **title**, category (retrospective or brainstorm or strategy or planning or diagram or workshop or other) | board_delete | ✔ | other | `template.publish` | templates.publish | yes | the template |

## Agents, search and the siblings (slice 9)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `search` | `/search` | one search across the boards, items and comments I can see; an item opens its board there (params: `q`, `kind`, `board`) |
| `admin-agents` | `/admin/agents` | the agents on Boards: who they are, which boards they can see, what they did this week |
| `admin-dispatches` | `/admin/dispatches` | every mention of an agent and what became of it, a failed one to retry (params: `status`, `agent`) |

Actions (base `/items/`, the admin's under `/admin/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who | Agent | record_id |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `items_arrange` | `/items/arrange.php` | **board**, **items[]** (item ids), **layout** (grid or column or row or cluster), frame (an item id to arrange them inside), columns (1 to 20), gap (in canvas units) | ops_undo | | | `item.move` | level edit | yes | the batch's seq |
| `dispatch_retry` | `/admin/dispatch-retry.php` | **dispatch** | — | | | `dispatch.retry` | agents.manage | people | the dispatch |

## Home, admin and settings (slice 10)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `home` | `/` | the start: recent boards, favourites, shared with me, team folders, New board and New from template |
| `admin` | `/admin` | the admin's page: usage against the budget, the trash, the agents, the settings |
| `admin-usage` | `/admin/usage` | every board by size against the budget, idle ones, ones whose owner has left (params: `sort`, `idle_days`) |
| `admin-settings` | `/admin/settings` | the defaults: a new board's everyone level, the limits, retention, the trash's days, live updates on or off |

Actions (base `/admin/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who | Agent | record_id |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `settings_save` | `settings-save.php` | default_everyone_level (none or view or comment or edit), item_soft_budget, item_hard_cap, batch_max_ops, max_upload_mb, op_retention_days, interval_version_days, daily_version_days, trash_days, live_enabled (yes or no), business_name | restore prior | ✔ | other | `settings.save` | settings.manage | yes | the settings |
| `trash_purge` | `trash-purge.php` | **board** (gone for good with everything on it) | — | ✔ | deletion | `trash.purge` | trash.purge | people | the board |
