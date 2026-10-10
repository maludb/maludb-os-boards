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
