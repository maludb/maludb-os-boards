# Build spec: Phase 2 — sign-on, the mirror, the shell (before any slice)

What exists at the end: a person clicks Boards on the launcher — or types `boards.<domain>` on a phone — and lands, signed in, in the
finished shell of an application with no screens yet: the nxl layout with the menu (Boards · Templates · Folders · Trash, and Admin for a
Boards admin), the header's search box, the application switcher, the bell, the user menu, the command bar, the bottom tab bar at 375 px;
their notification preferences exist before they look; the mirror refreshes every minute; every request is logged; the command bar answers
through the kernel; the record picker is in the shell with its `member` and `department` sources; every later screen answers as a
placeholder that names the slice that builds it; the worker runs an empty pass every minute. **The kit is in the repo already (Phase 0) and
proven without a kernel (`tests/phase0/run.sh`, 86 checks)**; this phase wires the shell around it and proves it under php -S and a
user-level Apache at 375 and 1280. It is copied from the Data Lake's Phase 2 (the newest kit, `/srv/apps/datalake/docs/build-specs/sso-shell.md`)
minus the engine; every file keeps its sibling's contract.

Schema it runs on (db/001–013, never modified): `members` (+ `is_agent`), `departments`, `department_members`, `directory_sync_state`,
`sso_nonces`, `member_sessions` (db/001); `activity_log` (+ `token_id`, `board_id`) (db/002); `mcp_access_tokens`, `key_usage`,
`mcp_resolve_token()` (db/003); `bd_rights`, `bd_roles`, `bd_role_rights`, `bd_member_roles()`, `bd_has_right()`, `bd_member_has_right()`,
`bd_level_cap()`, `bd_is_admin()`, `bd_is_member_here()`, `mcp_app_roles` (db/004); `bd_settings`, `bd_setting_int|text|bool()` (db/005);
`bd_board_level()`, `bd_my_level()`, `bd_is_boards_admin()` (db/006); `notifications`, `notification_prefs`, `worker_passes` (db/009);
`bd_require_level()` (db/011); `mcp_members`, `mcp_departments`, `mcp_settings`, `mcp_notifications`, `mcp_activity_log` (db/012).

## Divergences from the design
1. **The home, the notifications screen, the preferences and the search results are the slices'.** `home` is slice 10's, `notifications`
   and `settings` slice 6's, `search` slice 9's (the manifest's sections). The shell ships the header's bell (it counts unread
   `notifications` and links to `/notifications`) and the header's search box (it GETs `/search?q=`); both land on placeholders until their
   slices. `/` is the placeholder of `home` with one live link, **Boards** (`/boards`, itself slice 1's placeholder until slice 1).
2. **`bd_guard()` learns the schema's two other refusals.** The kit maps P0001, 23514, 23503, 23505 and P0002; db/006–013 also raise
   **42501** (`insufficient_privilege` — "You can see this board but not change it", "Only the board's owners can do that") and **22023**
   (`invalid_parameter_value` — a malformed batch). Phase 2 adds: 42501 → `refuse(403, db_message($e, 'You may not do that.'))`, 22023 →
   `refuse(422, db_message($e, 'That request is not well formed.'))`. Sales CRM's lesson (its guard read only P0001); proven here with a
   fixture route.
3. **No API keys.** Boards has no token API (design §4): the shell's tokens screen mints and revokes MCP tokens only (scope `mcp`).
4. **No live socket yet.** `boards-live.service` is slice 3's; the health endpoint already reports the relay's port for information only.

## Who may do what
`token_mint`: own + `tokens.own`, people · `token_revoke`: own, people. Every screen: `require_login()` (a person or an agent's relayed call);
the menu's Admin entry and `/admin*` placeholders: `boards.admin`.

## Screens
| Screen id | Canonical URL | Purpose |
|---|---|---|
| `activity` | `/activity` | my own trail here in sentences (`activity_sentence()`): "You created *Q4 retro*", "You moved 3 items on *Q4 retro*" — the board's title a link; paged 50, newest first; filters: since, board |
| `settings-tokens` | `/settings/tokens/` | my MCP tokens for my own AI tools: label, created, last used, expires; **Mint** (`page-header-form`: label, expires in days) shows the secret ONCE on the page that minted it with the two MCP URLs (`https://boards.<domain>/mcp/records`, `/mcp/activity`); Revoke (✔) |

**The placeholders** (one controller per later screen URL, each calling `render_nav_stub($screen, $slice)` — the registry marks them `stub`):
`/` (home, 10) · `/boards` (1) · `/boards/new` (1) · `/boards/{board}` (2) · `/boards/{board}/settings` (1) · `/boards/{board}/share` (1) ·
`/boards/{board}/outline` (2) · `/boards/{board}/comments` (6) · `/boards/{board}/history` (7) · `/folders` (1) · `/folders/{id}` (1) ·
`/folders/new` (1) · `/folders/{id}/edit` (1) · `/trash` (1) · `/templates` (8) · `/templates/{board}` (8) · `/notifications` (6) ·
`/settings/` (6) · `/search` (9) · `/admin` (10) · `/admin/usage` (10) · `/admin/settings` (10) · `/admin/agents` (9) · `/admin/dispatches` (9).
A placeholder answers 200 to a person ("Built by slice N — {what it will do}", the screen's manifest line) and 501 `not_built` to JSON.

## Files (exactly these)
**In the repo (proven by `tests/phase0/run.sh`):** `app/{bootstrap,db,http,directory,auth,handler,activity,mail,partial_update}.php`,
`app/api/{bootstrap,kernel}.php`, `bin/{directory_sync,dev_handoff,mint_mcp_token,make_brand_images,worker,build_action_registry,sync_approvals}.php`,
`bin/dev_directory.json`, `mcp/{activity_ingest.py,db.py,server_common.py,requirements.txt}`, `tests/{dev_router,fake_kernel,fake_maludb,fake_malumail}.php`,
`tests/setup_dev.sh`, `tests/phase0/`, `html/{index,logout,sso}.php`, `html/sso/logout.php`, `html/api/v1/health.php`, `html/assets/` (the theme).
**Phase 2 adds:**
- `app/features/shell/{nav,queries}.php` — `NAV_ITEMS` (Boards `/boards` · Templates `/templates` · Folders `/folders` · Trash `/trash` · Admin
  `/admin` with `boards.admin`), `NAV_SLICES` (every placeholder's screen → slice and line), `render_nav_stub(string $screen, int $slice): never`,
  `bell_count()`, `business_name()`.
- `app/features/settings/{queries,write}.php` (tokens), `app/features/activity/queries.php`, `app/switcher.php` (verbatim from
  `~/htmx-php-builder/skills/design-system/examples/php/app-switcher/switcher.php`, the kit branch), **`app/features/boards/wall.php`** — the board
  gate every slice calls (walls-and-sharing "PHP helpers" fixes its names, signatures and place; this phase builds it and proves it with a fixture route).
- The record picker verbatim from `examples/php/record-picker/`: `app/picker.php`, `app/pickers.php` with **two sources** — `member` (people and
  agents admitted here, a kind chip "Agent" on agents, param `kind` = human | agent to narrow; never an external to an external caller) and
  `department` (the mirror's live departments) — `html/pick/index.php`, `app/views/shared/picker-rows.php`, `html/assets/js/record-picker.js`, the
  one `#record-picker` modal in `layout.php` after `</main>`. Slice 1 adds `board` and `folder`.
- Views: `app/views/layout.php` (the nxl shell of `references/layout-skeleton.html`: sidebar menu, header — the search box `#header-search`,
  the switcher before the dark-mode toggle, the bell, the user menu with My trail · My tokens · Sign out —, `#page-content`, the command bar
  `#assistant-bar`, the bottom tab bar at 375: Home · Boards · Search · Me), `app/views/shared/{header,tab-bar,assistant-bar,assistant-reply,
  app-switcher,page-header,record-card,nav-stub,notice,level-badge}.php` (+ the kit's `flash.php`, `message.php`), `app/views/settings/tokens.php`,
  `app/views/activity/page.php` + `partials/rows.php`.
- Handlers: `html/activity.php`, `html/settings/tokens/{index,mint,revoke}.php`, `html/assistant/ask.php` (the command bar → the kernel's chat
  endpoint through `ask_assistant()`), the placeholders listed above (one file each, e.g. `html/boards/index.php`, `html/boards/view.php`,
  `html/boards/settings.php`, `html/folders/form.php`, `html/admin/usage.php`).
- `app/handler.php`: `bd_guard()` gains 42501 and 22023 (divergence 2). `app/views/sso/` unchanged. `html/sso.php`: ensures
  `notification_prefs` (`INSERT … ON CONFLICT DO NOTHING`) for the member; `bin/directory_sync.php` does the same for every admitted member.
- `tests/phase2/{run.sh,lib.php,shell.php,tokens.php,guard.php,browser.mjs}`, `tests/phase2/apache.conf.tmpl` (the user-level Apache).

## The receiver, every request, the worker
**The receiver (`/sso`)** — as the kit's (verify both signatures, the TTL, the audience `APP_KEY = boards`, the single-use nonce; mirror the
member and departments from the claims; refuse an unknown or ungranted member with `member.sign_on.refused`; open the hardened session) and
then: ensure `notification_prefs`; log `member.sign_on` with the roles; 302 to `/` (or the same-origin `next`).
**Every request** — `app/bootstrap.php` as the kit has it: the session re-checked against the mirror (a revoked grant → the launcher within a
minute), `app.member_id` on the connection, the action-token / relay path for the kernel's actions server, `json_mode_begin()`,
`log_screen_view()` on GET screens.
**The worker** — `bin/worker.php` as the kit has it: one pass a minute under its advisory lock, `worker_passes` written, `key_usage_prune`
real and the other seven passes stubs naming their slice (versions 7, outbox 6, dispatches 9, search catch-up 9, thumbnails 5, trash 10,
compaction 7).

## MCP tools this slice makes real (Phase 4 serves them; the shell's screens read the same SQL)
`find_members`, `find_departments` (the pickers' sources are their SQL), `app_roles`, `member_activity`, `record_history`, `recent_activity`,
`activity_search` (the trail screen is `member_activity` for oneself).

## Query functions (signatures fixed)
- `bell_count(PDO $pdo, int $memberId): int` · `business_name(PDO $pdo): string` · `nav_items_for(int $memberId): array`
- `find_my_tokens(PDO $pdo, int $memberId): array` (scope `mcp`, never the hash) · `mint_token(PDO $pdo, int $memberId, string $label, ?int $days): array{id, secret}` ·
  `revoke_token(PDO $pdo, int $memberId, int $id): bool` (own only)
- `find_my_activity(PDO $pdo, int $memberId, array $filters, int $page): array{rows, total}` (over `mcp_activity_log`) · `activity_sentence(array $row): string`
- `members_for_pick_q(PDO $pdo, string $q, array $params, int $page): array` · `departments_for_pick(PDO $pdo, string $q, int $page): array`
- `ask_assistant()` — the kit's (`app/api/kernel.php`), unchanged.

## Manifest rows claimed
Screens (2): `activity`, `settings-tokens`
Actions (2): `token_mint`, `token_revoke`

Routes, not rows: `/sso`, `/sso/logout`, `/api/v1/health`, `/pick/`, `/assistant/ask` (the command bar's post to the kernel), the switcher's
feed. The placeholders are claimed by the slices that build them, never here.

## Activity log events
`member.sign_on`, `member.sign_on.refused`, `member.sign_out`, `member.session_ended`, `screen.view` (every GET screen and placeholder: the screen
id), `token.mint`, `token.revoke` (the token's id and label, never its secret or hash), `assistant.ask` (the length and the screen, never the words).

## Status vocabulary
The bell `badge text-bg-danger` with the unread count (hidden at 0). The level badge (`shared/level-badge.php`, every later slice uses it):
view `text-bg-secondary` "Can view" · comment `text-bg-info` "Can comment" · edit `text-bg-success` "Can edit" · full `text-bg-dark` "Owner" (the
owner) or "Full" — walls-and-sharing.md's locked vocabulary (reconciled in the build).
Ids: `header-search`, `header-user-name`, `bell`, `bell-count`, `tab-bar`, `nav-{key}`, `nav-stub`, `token-list`, `token-{id}-revoke`,
`token-form-field-label`, `activity-row-{id}`, `record-picker`, `assistant-bar`.

## Out of scope for this phase
Every board, folder, template and admin screen (the slices); the worker's real passes but key-usage pruning; the relay (slice 3); the MCP
servers (Phase 4).

## Proof (`tests/phase2/run.sh`: scratch database `bd_dev_p2` via `tests/setup_dev.sh`; php -S on 9101, the fake kernel on 9102, the fake MaluDB + MaluMail on 9103; then the same under a user-level Apache on 9107 rendering `deploy/apache-boards.conf` with the scratch ports — `apache2 -f`, prefork + `libphp8.3.so`, no root; Playwright)
1. The Phase 0 refusals re-run (replay, expiry, audience, unknown member, tampered signature, no grant); sign-on for Priya, Marco, Bea, Ann, Dana, Lee
   with their resolved roles; `notification_prefs` exists for each after sign-on, and for every admitted member after one sync.
2. The menu per role: Admin only for Marco and the super-admin; an external sees Boards · Templates · Folders · Trash only.
3. Every placeholder answers 200 with its slice's line to a person and 501 to JSON; the registry `--check` counts them as `stub`.
4. The guard (a fixture route calling `bd_require_level` on a private board): 404 for a stranger, 403 with the database's sentence for a viewer asked
   for `edit`, 422 for a malformed batch (22023) — never a 500.
5. A token minted once (the secret on that page only, never in a log or a later JSON), listed, revoked; another member's revoke refused.
6. The trail lists my sign-on and my token's mint in sentences; the command bar's post reaches the fake kernel's chat endpoint and renders the reply.
7. The switcher renders the fake kernel's applications with Boards current; the picker answers `member` (agents chipped) and `department`.
8. The worker's pass is logged with eight steps; health answers with `live: down` and still 200.
9. Playwright at 375 × 740 and 1280 × 800: no horizontal scroll, the tab bar at 375 and the sidebar at 1280, the bell, HTMX navigation pushing URL
   and title, no console errors; JavaScript off still renders every placeholder.
10. The installer's `plan` clean; `php bin/build_action_registry.php --check`.

## Decisions taken here
1. `/` is the home's placeholder with one live link (Boards) until slice 10.
2. `bd_guard()` maps 42501 → 403 and 22023 → 422 with the database's sentence (divergence 2).
3. The picker's `member` source includes agents with a chip and a `kind` param; an external caller sees only themselves (the `mcp_members` rule).
4. The bottom tab bar at 375 is Home · Boards · Search · Me (Me = My trail, My tokens, Sign out).
5. The shell's proof runs under a user-level Apache rendering the real vhost template (`apache2 -f`, prefork + mod_php loaded from
   `/usr/lib/apache2/modules`), so no root is needed and the production rewrites are the ones proven.

## Open questions
None.

## Built and proven (2026-10-10)
The shell stands. A person signs on through the kernel's hand-off (their notification preferences made on the way in, and for every admitted
member at each sync) and lands on **Home** — slice 10's placeholder with one live link, **Boards**. The sidebar is `NAV_ITEMS`: Boards · Templates ·
Folders · Trash, **Admin** for `boards.admin`, then Me (Notifications · My settings · My tokens · My trail), each item gated by its right. The header
carries the business name, the highest role as a badge (Super-admin › Boards admin › Member › Viewer), the search box (a plain GET to `/search`; a
magnifier at 375), the bell (the unread count, hidden at 0), the switcher (K31) and the user menu (My trail · My tokens · Sign out). The phone's tab
bar is **Home · Boards · Search · Me** (Me opens the sidebar; My trail without JavaScript). The command bar posts to the kernel's chat endpoint as
the person and renders the reply, a paused action and a `navigate`. **My tokens** mints an MCP token shown once with the two MCP URLs and revokes
one's own; **My trail** is my rows in sentences ("You created *Q4 retro*", "You moved 3 items on *Q4 retro*" — the title a link to that board's
rows), filtered by since and by board, paged 50. The record picker carries `member` (people and agents, an agent chipped, `kind=human|agent`; an
external offered only themselves) and `department`. **The board gate** (`bd_board_or_404()`, `bd_level()`, `bd_level_at_least()`) is live, and
`bd_guard()` answers 42501 → 403 and 22023 → 422 in the database's words. **24 placeholders** in 23 controllers call `render_nav_stub($screen,
$slice)`: the right first, then — for a board's page — the board's wall at its level (a stranger 404, the page naming the board and the caller's
level badge), then "Built by slice N — what it will do" (the manifest's line); 501 `not_built` to JSON and to a POST. The worker runs one pass of
eight steps, seven of them stubs naming their slice. The registry reads **26 screens (2 built, 24 stubs), 45 actions (2 built)**.

**Proven** by `tests/phase2/run.sh` (scratch `bd_dev_p2`): **258 checks under php -S, 266 under a real Apache** — the user-level Apache of
`tests/phase2/apache.conf.tmpl` (prefork + mod_php, mod_headers loaded only there) serving `deploy/apache-boards.conf` rendered as the installer
renders it, the public vhost on 9101 and the internal one on 9107 — browser 56 (375 × 740, 1280 × 800, every placeholder with JavaScript off, the
manifest), shell 64, tokens 29, guard 19, picker 26, switcher 12, vhost 49/57, plus the schema proof (167), the registry `--check` and the kernel's
installer `plan` (40 steps, 6 notes). Phase 0's kit (87) and Phase 1's checks (66) re-run green.

**Decisions taken in the build:**
1. **The board gate lives in `app/features/boards/wall.php`**, where walls-and-sharing.md places it (this spec had said `app/gate.php`); Phase 2
   builds it, the bootstrap loads it, and `render_nav_stub()` puts it in front of every board's placeholder so the slices inherit the same 404/403.
2. **The level badge follows walls-and-sharing.md's locked vocabulary**: full `text-bg-dark` ("Owner" for the owner, "Full" otherwise), edit
   `success` "Can edit", comment `info`, view `secondary` — this spec's Status vocabulary had full `success` and edit `primary`; the build spec wins.
3. **`render_nav_stub(string $screen, int $slice)`** reads `NAV_SLICES` (screen → slice, title, the manifest's line, the right, the menu item, the
   board level when it is a board's page) and refuses a call whose slice disagrees with the table.
4. **`folders/form.php` answers two screens** (`folder-add` at `/folders/new`, `folder-edit` at `/folders/{id}/edit`), as the vhost rewrites both to it.
5. **My trail leaves screen views out** — they are the activity MCP's `member_activity`; every action I took is in. A board I can no longer open
   never names itself ("… a board").
6. **The picker's params** may be an integer filter or an enumerated one (`'kind' => ['human', 'agent']`); `/pick/` forwards only a declared value.
7. **The command bar refuses an action token** ("The command bar is for a person at the screen.", 403): the kernel runs agents itself; an agent's
   call there would be a turn inside a turn.
8. **`db_message()` shows 42501 only when a function raised it**: PostgreSQL's own "permission denied for table …" falls back to the handler's sentence.
9. **Under `/api` only `/api/v1/health` answers** — the vhost 404s the rest, `/api/v1/health.php` included; the dev router now does the same.
10. **The proofs' Apache**: the public vhost moved to 9101 and the internal one on 9107 (this spec said "Apache on 9107"); the gate's fixture route
    `/__proof/gate.php` exists only under the scratch environment (the dev router) and a proof-only Alias in the rendered vhost — never `html/` or `deploy/`.
