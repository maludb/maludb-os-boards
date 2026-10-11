# Build spec: comments, mentions and notifications (slice 6)

What exists at the end: comments pinned to an item or a point on the canvas, threaded one level, resolved and reopened, edited by their author,
removed by their author or an owner; `@Name` in a comment's text mentions a person, an agent or a department; a person mentioned who can see the
board is told (the bell, email unless they take the digest, a text when they opted in); a mention of an agent who can see the board becomes a
dispatch (slice 9 sends it); a mention of an agent who cannot is answered with **"Share with {agent} at edit?"** — a mention never grants; the
board's comments page and the canvas's right rail; the pins on the canvas; my notifications and my preferences; the worker's `outbox` pass real
(email through MaluMail, texts through the kernel's K6, the morning digest).

Exemplar to replicate: **slice 1 (boards-folders)** for the pages and handlers — the handler order `bd_handler_begin()` → the gate →
`bd_require_level()` → `bd_guard()` → `log_activity()` → `emit_action_status()` → `bd_done()`; **slice 2 (canvas)** for the island's tool and render
modules and the bundle (`canvas-core.md` "The island and the page"). The outbox pass is a port of Spaces' `app/features/worker/outbox.php` (its rules
verbatim, its functions renamed `sp_` → `bd_`).

Schema and functions used (db/006–013, never modified): `comments`, `comment_mentions`, `bd_comment_create()`, `bd_comment_resolve()`,
`bd_comment_delete()`, `bd_comment_edit()`, `bd_notify()`, `notifications`, `notification_prefs`, `notification_outbox`, `agent_dispatches` (read only
— slice 9 sends them), `bd_rich_text()`, `bd_rich_text_plain()`, `bd_board_level()`, `mcp_comments`, `mcp_notifications`, `mcp_members`,
`mcp_departments`, `mcp_board_items`. **The database is the referee**: who may comment (`comment`), who is told (only a person who can see the board;
never the writer), which agent is dispatched (a person's mention of an agent who can see the board), a reply hanging on its root, the digest holding
the email back, the text only for an opted-in kind.

## Divergences from the design
1. **A comment's text is plain text with `@mentions`** (v1, D5): the composer is a textarea; `@` opens slice 1's record picker filtered to people,
   agents and departments the writer may see and inserts `@Display Name`. The server turns the text into rich-text runs (`comment_text_to_rich()`):
   text runs and mention runs only — no bold, no links (a URL stays text and is linked when rendered).
2. **Pins refresh by polling**, not by the relay: comments are not ops (`board_ops`), so the canvas re-reads the pins every 15 s while visible and at
   once after the person's own comment action (`commentChanged`). The relay carries no comment (live-relay.md is unchanged by this slice).

## Who may do what
| Action | Right | Level / rule |
|---|---|---|
| comment_create, comment_reply | `boards.comment` | `comment` on the board (`bd_comment_create()` refuses below) |
| comment_resolve, comment_reopen | `boards.comment` | `comment` |
| comment_edit | — | the comment's author (`bd_comment_edit()`) |
| comment_delete | — | its author, or `full` on the board; an agent's call pauses (`deletion`) |
| prefs_save, notification_read, notifications_read_all | — | own |

## Screens (375 px first)
| Screen id | Canonical URL | Purpose |
|---|---|---|
| `board-comments` | `/boards/{board}/comments?open=&mine=` | the board's threads as cards (`comment-card-{id}`), open first then resolved (`open=0` shows resolved only, `mine=1` threads I wrote or am mentioned in): the author's avatar and name (an agent with the **agent** chip), the text with mention chips, where it is pinned ("on 'Shipped late'" — the item's first 60 characters — or "at a point"), replies count, resolved by whom; a card opens the thread inline (HTMX swap, `comment-thread-{id}`): every reply, the reply form, Resolve/Reopen, Edit (own), Delete (own or full); **Show on the board** links `/boards/{board}?comment={id}`. **The same thread partial is the canvas's rail** (`app/views/comments/partials/thread.php`) |
| `notifications` | `/notifications` | my notices, unread first, paged 30: kind icon, title, body, the board's title, when; a notice opens its board at the comment (`/boards/{board}?comment={record_uuid}`) and marks it read; **Mark all read** |
| `settings` | `/settings/` | my preferences (`page-header-form`): email on/off, the morning digest instead (one email at 07:00 in my time zone), texts on/off (K6: the kernel's verified phone and opt-out decide whether one is sent — the page says so), the kinds by email (mention, reply, comment on a board I own, share, an agent's reply) and the kinds by text; a link to `/settings/tokens/` (the shell's) |

**The canvas (slice 2's page)** gains, in this slice: the **Comment tool** (`C`; tap/click a point or an item → the rail opens the new-thread form
with the anchor and the current selection as `context[]`), **pins** (a round avatar of the thread's author at the anchor — on an item, its
top-right corner plus the stored offset; at a point, the point; resolved threads hidden unless "Show resolved"; a count badge for replies), a pin
click opens the thread in the rail (DOM event `boards:open-comment` with `{id}`, HTMX `hx-trigger="boards:open-comment from:body"` on the rail).
`?comment={id}` on the canvas URL flies to the pin and opens it.

**The needs-share prompt**: when `comment_create`'s answer carries `needs_share` (agents who cannot see the board), the rail shows one line per
agent — *"{Agent} cannot see this board. Share it at edit?"* — with **Share** (posts slice 1's `board_share` with `member={agent}&level=edit`;
only to a `full` holder; others read *"Ask an owner to share it with {Agent}"*) and **Not now**. `not_shared` (people who cannot see it) shows
*"{Name} was not told — they cannot open this board."*

## Files (exactly these)
- `html/comments/{create,reply,resolve,reopen,edit,delete}.php`, `html/boards/comments.php` (the screen; in JSON mode with `format=pins` the pins:
  `[{id, item_id, x, y, author_id, author_name, author_kind, replies, resolved}]`), `html/comments/thread.php` (GET the thread partial for the rail:
  `?id=`), `html/notifications/{index,read,read-all,bell}.php` (`bell.php` = the unread count partial the shell's header polls every 60 s),
  `html/settings/{index,prefs}.php`
- `app/features/comments/{queries,present,write,richtext}.php`, `app/features/notifications/{queries,present,write}.php`,
  `app/features/worker/outbox.php` (the pass, ported)
- `app/views/comments/page.php`, `app/views/comments/partials/{card,thread,reply,form,needs-share}.php`,
  `app/views/notifications/{index,partials/row,partials/bell}.php`, `app/views/settings/index.php`, `app/views/emails/{notice,digest}.php`
- `client/src/tools/comment.ts`, `client/src/render/pins.ts`, `client/src/sync/pins.ts` (the 15 s read) — and the rebuilt bundle with its manifest
  (`bin/check_bundle.php` passes)
- `bin/worker.php`: `worker_pass_outbox` made real (calls `outbox_pass()`)
- `tests/fake_kernel.php` is unchanged (its `/api/v1/notify/sms.php` already answers by `state.sms`)
- `tests/phase3/slice6/{run.sh,lib.php,comments.php,mentions.php,notifications.php,outbox.php,json.php,browser.mjs}`
- **The shell's header**: `#header-bell` (sso-shell.md's slot) renders `app/views/notifications/partials/bell.php` (`hx-get="/notifications/bell"
  hx-trigger="load, every 60s"`).

## Query functions (signatures fixed)
- `comment_text_to_rich(PDO $pdo, string $text): array` — splits on `@` followed by the longest display name (case-insensitive, ending at a word
  boundary) of a person or agent in `mcp_members` or a department in `mcp_departments` **as the current `app.member_id` sees them**; a name two
  members share stays plain text and is listed in the answer's `unresolved_mentions`; the result is a run list with `bd_rich_text()`'s text-run shape
  and mention runs `{"type": "mention", "mention": {"type": "member"|"agent"|"department", "id": N, "name": "…"}, "plain_text": "@Name"}`
- `rich_to_html(array $runs): string` — text escaped through `e()`, line breaks kept, `http(s)://` linked (`rel="noopener"`), a mention a chip
- `find_threads(PDO, string $board, array $f, int $page): array{rows, total}` (roots from `mcp_comments`, replies counted) · `find_thread(PDO, string $commentId): ?array{root, replies}` ·
  `board_pins(PDO, string $board, bool $resolved): array`
- `comment_create(PDO, string $board, int $by, array $runs, ?string $item, ?float $x, ?float $y, array $context, ?int $runId): array` (→ `bd_comment_create()`; the answer verbatim) ·
  `comment_reply(PDO, string $root, int $by, array $runs, ?int $runId): array` (the board read from the root) · `comment_set_resolved(PDO, string $id, int $by, bool $on): void` ·
  `comment_edit(PDO, string $id, int $by, array $runs): void` · `comment_delete(PDO, string $id, int $by): void`
- `find_my_notifications(PDO, int $member, bool $unreadOnly, int $page): array` · `unread_count(PDO, int $member): int` · `notification_mark_read(PDO, int $member, ?int $id): int` (null = all) ·
  `prefs_of(PDO, int $member): array` (defaults when no row) · `prefs_save(PDO, int $member, array $f): void` (upsert)
- `outbox_pass(PDO, DateTimeImmutable $now, int $limit): array{sent, failed, skipped, retried, digests}` · `digest_queue(PDO, DateTimeImmutable $now): int`

## The outbox pass (Spaces' rules, verbatim)
- **Email is at-most-once**: the row is marked `sent` BEFORE `malumail_send()` and `failed` after an exception (never queued again); a MaluMail
  refusal of the address is `skipped` with the code; no `MALUMAIL_API_KEY` → every queued email `skipped` "no mail key" (mail is optional).
  The body: `app/views/emails/notice.php` — the title, the body, **Open the board** `{APP_URL}/boards/{board}?comment={record_uuid}`; from
  `MAIL_FROM` / `MAIL_FROM_NAME`.
- **A text** goes through `kernel_call('POST', '/api/v1/notify/sms.php', ['member_id', 'text' (≤ 480), 'reference' => "{kind}:{id}"])`; no answer
  or a 5xx → retried (`send_after` + 2^attempts minutes, five attempts then `failed`); a K6 refusal (`no_sender`, `not_held`, `no_verified_phone`,
  `opted_out`, `rate_limited`, `invalid`) → `skipped` with the code.
- **The digest**: `digest_queue()` — for every member with `notification_prefs.digest` whose local time (`members.timezone`) has passed 07:00 today
  and who has no outbox row `dedupe_key = 'digest:{member}:{local date}'`: one email of their unread notifications of the last 24 h
  (`app/views/emails/digest.php`, at most 30, then "and N more"), none when there are none.

## MCP tools this slice makes real
`find_comments`, `comment_thread`, `my_notifications` (the screens read the same views the tools will).

## Manifest rows claimed
Screens (3): `board-comments`, `notifications`, `settings`
Actions (9): `comment_create`, `comment_reply`, `comment_resolve`, `comment_reopen`, `comment_edit`, `comment_delete`, `prefs_save`, `notification_read`, `notifications_read_all`

## Activity log events
`comment.create` (`after`: `comment_id`, `item_id`, `mentions` counts by kind, `dispatched` agent ids, `needs_share` agent ids, `not_shared` count — never
the text), `comment.reply`, `comment.resolve`, `comment.reopen`, `comment.edit` (the length only), `comment.delete`, `settings.prefs` (the field
names changed), `notification.read`, `notification.read_all` (the count), `screen.view` on the three screens with `board_id` on `board-comments`,
`outbox.send` from the worker (counts only, `source = cron`). Every row about a board carries `board_id`.

## Status vocabulary
A thread: open none, resolved `success` "Resolved". An agent author: the **agent** chip `info`. Outbox rows (admin only, not shown here). Ids:
`comment-card-{id}`, `comment-thread-{id}`, `comment-form-field-text`, `comment-reply-{id}`, `needs-share-{agent}`, `notification-row-{id}`,
`header-bell`, `settings-form-field-{name}`, `pin-{id}` (the island's DOM overlay id for the focused pin).

## Out of scope for this slice
Sending a dispatch (slice 9 — this slice only shows what `bd_comment_create()` answered); reactions, votes; rich formatting in a comment;
comments in a version's preview; notifying a share (slice 1's `board_share` handler calls `bd_notify(…, 'share', …)`); the live relay.

## Proof (`tests/phase3/slice6/run.sh`: scratch database `bd_dev6` via `BD_DEV_DB=bd_dev6 tests/setup_dev.sh`; php -S on 9101, the fake kernel on 9102, the fake MaluDB/MaluMail on 9103; Playwright from `/var/www/web/node_modules` at 375 × 740 and 1280 × 800; the registry `--check`)
1. A Member comments on an item and at a point; a root with neither is refused with the database's sentence; a reply to a reply hangs on the root.
2. `@Bea` (who can see the board) → a `mention` notification and a queued email; `@Sam` (who cannot) → `not_shared`, nothing queued; `@Sales` →
   each member of Sales who can see it; `@Facilitator` shared at edit → one `agent_dispatches` row (`conversation_id = boards:thread:{root}`);
   `@Expert` not shared → `needs_share`, the rail's prompt, **Share** as the owner posts `board_share` and a second comment now dispatches.
3. Two members named alike → the name stays text and `unresolved_mentions` names it.
4. Resolve and reopen by a commenter; edit by the author only (another's edit is the database's sentence); delete by the author and by an owner; a
   Viewer's delete of another's is refused; an agent's `comment_delete` pauses (`deletion`).
5. The bell's count; a notice opens the board at the comment and is read; Mark all read.
6. Prefs: the digest holds the email back; at a fake 07:05 local (`BD_WORKER_NOW`) one digest email; a second pass sends none (dedupe); a text for
   an opted-in kind goes to the fake K6; `no_verified_phone` → `skipped`; the fake MaluMail's 500 → `failed`, never resent.
7. No `MALUMAIL_API_KEY` → `skipped` "no mail key".
8. Browser: the pin appears after a comment (≤ 15 s, or at once for the writer), `?comment=` flies to it and opens the rail; the composer's `@` picker
   inserts a name; the comments page at 375 with no horizontal scroll; no console errors.
9. JSON mode on every handler; the three screens' JSON; registry `--check`: 3 screens, 9 actions built, `comment_delete` the one approval.

## Decisions taken here
- Plain text + `@mentions` in the composer; rich formatting is not in v1 (D5's "full rich text" read as: the stored format is the runs, the
  composer writes text and mentions).
- Pins poll every 15 s; the relay is not given a comments channel in v1.
- The morning digest is at 07:00 in the member's own time zone; at most 30 notices in it.
- The bell is polled every 60 s by the shell's header slot `#header-bell`.
- A same-named mention stays text (never guessed).

## Open questions
None.

## Built and proven (2026-10-11)
Comments, mentions and notifications — built by a Sonnet 5.5 worker on slice 1's patterns (the handler order, the whitelist presenters, HTMX partials) and slice 2's (a tool, a render
module, the bundle). **Comments**: the Comment tool (Shift+C — C is the connector's since slice 4 — and a toolbar button for anyone at level `comment`); a tap on an item or on the empty
canvas opens the rail's Comments panel at a new-thread form carrying the anchor (the item and the offset from its top-right corner, or the point) and the selection as `context[]`;
the composer is a textarea in which "@" opens slice 1's record picker (people and agents; a second picker for departments) and the pick is inserted as `@Name`;
`comment_text_to_rich()` turns the text into runs (text and mention runs only) — the longest display name at a word boundary, case-insensitive, **never a name two records share** (it stays text
and the answer lists it in `unresolved_mentions`); `bd_comment_create()` does the rest (who is told, which agent is dispatched, which one needs a share). The thread is ONE partial
(`comments/partials/thread.php`) for the comments page's inline thread and the canvas's rail: where it is pinned in words, Resolved, every comment (an agent wears the **agent** chip), Edit
(own), Delete (own or a board's owner, with `hx-confirm`), Resolve / Reopen, the reply form, **Show on the board**; the handlers answer an HTMX request with the thread again (outerHTML of
`.comment-thread`) and `HX-Trigger: commentChanged`. The prompts the answer carries sit above the thread: *"{Agent} cannot see this board. Share it at edit?"* with **Share** (slice 1's
`board_share`, which answers the prompt itself when its target is `needs-share-*`) and **Not now** — or *"Ask an owner to share it with {Agent}"* to one who is not `full` —,
*"{Name} was not told — they cannot open this board."*, and the shared-name sentence. **Pins**: a DOM layer over the stage (`render/pins.ts`), one button per thread, an item's following the
item, read every 15 s and at once after the person's own comment action (`sync/pins.ts`); `?comment=` (a reply's id too) flies to the thread's pin and opens it. **The comments page**
(`board-comments`): the threads as cards, open first, All / Open / Resolved and Mine as chips, 30 a page, a card opening its thread inline. **Notifications**: the bell (`#header-bell`, polled
every 60 s through `/notifications/bell`), `/notifications` (unread first, 30 a page, `?unread=1`, a notice opens its board at the comment and is marked read — by a POST, so a link
never writes —, Mark all read), `/settings/` (email, the digest, texts with the K6 sentence, the kinds by email and by text, a link to My tokens; `prefs_save` partial, validated, logged by field
name). **The worker's `outbox` pass is real** (`app/features/worker/outbox.php`, Spaces' rules verbatim): the digests due, then the queue — email through `malumail_send()` at most once
(marked `sent` before the send, `failed` after an exception and never again, a refused address `skipped` with its code, no key at all `skipped` "no mail key"), texts through the kernel's
K6 (a 5xx or no answer retried at 2^n minutes, five attempts then `failed`, a K6 refusal `skipped` with its code).

**Proven** by `tests/phase3/slice6/run.sh` — **279 checks under php -S** (comments 60, mentions 42, notifications 45, outbox 46, json 38, browser 48) and **278 under a real Apache** (the browser proof has no test hook there: it clicks an empty point and asserts on the database — 47), then every earlier suite
(slice 5's and within it 4's, 3's, 2's, 1's and Phase 2's): the threads — a comment on an item (with its offset) and at a point, a root with neither refused in words, half a point, an empty one,
over 4,000 characters, an item of another board, a board one cannot open 404 (Bea, Lee, Ann), a Viewer at comment level comments, a reply to a reply hangs on the root, resolve / reopen by a
commenter (a reply cannot be resolved), another person's edit — even an owner's — the database's sentence 403, delete by the author and by an owner and a Boards admin, a Viewer's delete of another's
403, a deleted comment is not there to edit, the registry (only `comment_delete` pauses — deletion), the pins (open only; `resolved=1` adds the resolved; an item's pin at its corner plus the offset; a
point's pin the point; the wall), the thread partial and its JSON, Edit and Delete shown by who may, the page and its filters (`open=0|1`, `mine=1`), the trail with no text in any payload; the
mentions — Bea told (one notice, one queued email), Lee (cannot see) `not_shared` and told nothing, the writer never told, `@Sales` tells Priya and Dana and not Lee, a person named and in the department
told once, a reply tells the thread's other author and a new thread its owner, an agent not shared `needs_share` (the prompt, the not-an-owner line, **Share** answering the prompt and the agent now at edit,
a second comment dispatching ONE `agent_dispatches` row with `conversation_id = boards:thread:{root}` acting as the writer, a mention in a reply one more in the same conversation), an agent's own
comment dispatching nobody, two members named alike staying text with `unresolved_mentions`, resolving again once the names differ, an address and "@SMOKE Beatrix" staying text; the notifications —
the bell's count and partial and its poll, unread first, 30 a page (30 then 6 of 36), `unread=1`, a notice opens its board at the comment and is read (JSON location, HX-Location, 302), another
person's notice 404, a notice for a board one cannot open lands on the list in words, Mark all read (34, then 0, never another person's), the settings page and its defaults, `prefs_save`
(partial, a comma list as an agent sends it, refused kinds, the form's `__sent` markers, "Nothing to change", logged by field name); the outbox — 19 queued emails sent in one pass (from, subject,
text and HTML with "Open the board" and the link), none twice, a 502 `failed` and never resent, a suppressed address `skipped`, no key `skipped` "no mail key", the digest held back and sent once at
07:05 (not at 06:55; at 07:00 in New York for a member there; keyed by HER date; none when nothing is unread; a second pass none), a text to the fake K6 (reference `mention:{id}`, at most 480
characters; none for a kind not opted into), `no_verified_phone` / `opted_out` / `rate_limited` / `not_held` `skipped` with the code, a 5xx retried at 2^1 minutes and `failed` after five, the full
worker pass running the real outbox; JSON on every handler (401 anonymous, 405, CSRF, the answers' shapes, an agent's run token — the run on the row —, a person's action token as the assistant, an
agent deleting another's refused) and on the three screens. The browser — the Comment tool, a click on the sticky opening the form "on 'Shipped late'" with the item and the offset, "@" opening the
picker and the pick inserted over it, the thread in the rail, the pin at once (and Bea's within the 15 s poll), a reply with its badge, `?comment=<a reply>` opening the thread and marking its pin, a pin's
click, Resolve hiding the pin and "Show resolved" bringing it back dimmed, the share prompt and Share, the comments page and a thread opened and answered inline, the notices page and a notice opening its
board, Mark all read, my settings saved, a commenter's toolbar (the Comment tool, no Sticky), at 375 the tool on the bar, the sheet, no sideways scroll on any of the three pages, no console error.

**Found and fixed:** the proof found no defect in the schema (no migration); in the build — the shell's bell was already `#bell` / `#bell-count`, which three earlier checks assert, so `#header-bell` wraps
it; `/notifications` became a directory (`notifications/index.php`) beside `bell.php`, `read.php` and `read-all.php`, the stub `notifications.php` removed and the three earlier proofs that named it (Phase 2's
shell and browser, slice 2's rail check) made to follow whether it is built; a `notice`'s `record_uuid` is the REPLY, not the thread, so `?comment=` needed the thread's root; the scratch directory sync gives every member a default
preferences row (the proof deletes Bea's to prove the defaults); the shared fake MaluMail answers no `message_id`; and — a lesson for every proof — a suite that dies on a PHP parse error shows only in its exit status
(slice 2's `doors.php` after a stray apostrophe in an edited label): `php -l` every test file you touch.

**Decisions taken here (within the spec):**
1. **`comment_text_to_rich()` answers `['runs' => …, 'unresolved' => […]]`**; the handler's answer carries `unresolved_mentions` (the spec's "the answer's"). A mention needs the person's
   FULL display name ("@SMOKE Bea"); the picker inserts it so.
2. **The pins' JSON is `{"data": {"board_id", "pins": [...]}}`** (the kit's `respond_screen()`), and an item's pin has `x, y` = the item's top-right corner plus the offset (`comments.anchor_x/y`
   are an OFFSET from that corner for a thread on an item, an absolute point otherwise). The island derives the same from the live store, so a pin follows its item; a thread on an item that
   is gone has no pin.
3. **The Comment tool is Shift+C** (C is the connector's), shown on the toolbar to anyone at level `comment`, outside "More" at 375 px.
4. **Files beyond the spec's list**: `comments/partials/{gone,rail,rail-list}.php` (a removed thread's line; the rail's Comments panel; its list), `GET /boards/{board}/rail?panel=comment-list`,
   `comments/thread.php` with no `id` answering the rail's hint, `html/notifications/index.php` replacing the stub `notifications.php` (the URL is the same), `app/features/notifications/write.php`
   holding the preferences request's parsing, `html/assets/js/comment-composer.js` (the "@" → picker → `@Name` glue; loaded by the layout).
5. **`?comment=` may name any comment of a thread** (a notice's `record_uuid` is the reply's): `canvas_mount()` resolves it to the thread's root, which the island flies to.
6. **The bell polls every 60 s and on `notificationsChanged`**, not at load (the first render is the server's).
7. **The digest is queued when the member's local time is 07:00 or later and none exists for their date** (so a person who takes the digest at 15:00 gets one then), lists the unread of
   the last 24 hours, and is the outbox row alone — no `digest` notice on the bell. Its text part ends "and N more in your bell."
8. **A comment page is 30 threads**; the notices page takes `?unread=1` and `?page=`.
9. **The settings form marks the fields it carries with `<name>__sent`** (an unchecked box posts nothing); a JSON caller sends only what it changes.
10. **The shared test fake answers no `message_id`**, so a sent row's `provider_ref` stays empty; the pass keeps it when MaluMail gives one.
