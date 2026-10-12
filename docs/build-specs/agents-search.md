# Build spec: agents, search and the siblings (slice 9)

What exists at the end: **a mention of an agent is answered** — the worker turns each dispatch into ONE turn of the kernel's chat endpoint as that
agent, the asker acting; "{Agent} is working…" stands in the thread meanwhile; the reply lands as the agent's reply comment; a run still going is
polled, an approval pause is said in the placeholder, a refusal is shown in the kernel's words, a failure is retried with backoff and the asker told
once when it gives up; **`items_arrange`** (grid, column, row, cluster — inside a frame or where the items stand) for agents and people; **one search**
across boards, items and comments; the admin's agents and dispatches pages (Retry); the search index's catch-up; the two K7 shares' SQL proven.
Nothing here calls a model or holds a key (design §5, D10, D11).

Exemplar to replicate: **slice 1 (boards-folders)** for the pages and the people's handler; **slice 2 (canvas)** for `items_arrange` (an item action:
the board's level, one `bd_commit_ops()` batch, `record_id` = the seq, `canvas-core.md` "The op protocol"); **Spaces' slice 7** for the dispatch loop
(`/srv/apps/spaces/app/features/agents/dispatch.php` and `docs/build-specs/agents-in-spaces.md` — port it: `sp_` → `bd_`, messages → comments,
channels → boards).

Schema and functions used (db/006–013, never modified, and this slice's **`db/018_dispatch_loop.sql`** — additive functions only, below):
`agent_dispatches`, `comments`, `comment_mentions`, `bd_notify()`, `bd_comment_create()` (slice 6 already makes the dispatches), `search_index`,
`bd_search_put()`, `mcp_search`, `mcp_boards`, `mcp_members`, `mcp_agent_dispatches`, `mcp_activity_log`, `mcp_folders`, `mcp_folder_entries`,
`bd_member_board_levels()`, `bd_commit_ops()`, `bd_board_outline()`. **The database is the referee**: which agent is dispatched (slice 6 did that),
the backoff and the five attempts, the placeholder and the reply as one comment, who is notified, the arrangement's batch (the cap, the locks, the
frame being a frame) — PHP never decides whether an agent should answer.

## Divergences from the design
1. **The agent's reply is plain text with `@mentions`** (slice 6's `comment_text_to_rich()`, run as the AGENT sees people): Markdown stays literal in
   v1 (comments have no Markdown converter).
2. **Search leaves templates out** (a template's items are not the business's work); a template is found in the gallery.

## Who may do what
| Action | Right | Level / rule |
|---|---|---|
| items_arrange | `boards.edit` | `edit` on the board; the items live, on the board, none a frame or a connector, none locked (`bd_commit_ops()` refuses) |
| dispatch_retry | `agents.manage` | people only (`require_human()`); a `failed` or `awaiting_approval` dispatch |
| (search) | `boards.view` | rows of boards the caller may see (`mcp_search` joins the wall) |
| (admin-agents, admin-dispatches) | `agents.manage` | — |

## Screens (375 px first for search; the admin pages designed at 1280, usable at 375)
| Screen id | Canonical URL | Purpose |
|---|---|---|
| `search` | `/search?q=&kind=&board=` | the box (≥ 2 characters), kind chips (All · Boards · Items · Comments), a board filter (the record picker over `mcp_boards`); results as cards (`search-result-{kind}-{id}`), best first, paged 20: kind icon, the board's title, the title or the excerpt with the matched words marked (`<mark>`), the author, when; a board opens `/boards/{id}`, an item `/boards/{board}?item={id}` (slice 2 flies there and selects it), a comment `/boards/{board}?comment={id}`; "Nothing found" with the tip "Try fewer words" |
| `admin-agents` | `/admin/agents` | one card per agent granted Boards (`mcp_members` where `member_kind = agent`): name, roles, the boards it can see (count, from `bd_member_board_levels()`) and those shared with it by name (links), this week's batches and comments (`agent_activity()`), its dispatches answered / failed / waiting; a note that hiring, grants, models and duties are the kernel's, with a link to the OS (`OS_LAUNCHER_URL`'s Agent HR) |
| `admin-dispatches` | `/admin/dispatches?status=&agent=` | every dispatch, newest first, paged 50 (`dispatch-row-{id}`): the agent, the asker, the board, the comment's first 80 characters (→ the board at the comment), status chip, attempts, the run id, the reply's excerpt or the detail, when; **Retry** on `failed` and `awaiting_approval` (`dispatch_retry`) |

## The dispatch loop (`bin/worker.php` `worker_pass_dispatches` → `dispatches_pass()`)
1. **Poll what is running**: `bd_dispatches_running(50, now)` — each `GET /api/v1/agents/chat.php?run={run_id}` with `X-Acting-Member: {acting}`
   (20 s): finished → `dispatch_finish()`; a 4xx → `failed` "The kernel does not know that run ({status})."; older than 10 minutes (the placeholder's
   `created_at`) → `failed` "The run did not finish."; else still running (the record moves `next_attempt_at` on).
2. **Poll what waits for approval**: `bd_dispatches_waiting(50, now)` — the same GET: finished → answered; a 4xx or a declined status (`declined`,
   `rejected`, `refused`, `denied`, `cancelled`) → `refused` with the kernel's sentence; else `awaiting_approval` again.
3. **Send what is due**: `bd_dispatches_due(10, now)` — `kernel_call('POST', '/api/v1/agents/chat.php?agent={agent_member_id}', ['utterance' =>
   the comment's text (≤ 4000), 'conversation_id' => 'boards:thread:{root}', 'context' => dispatch_context($d), 'wait' => 25], ['X-Acting-Member:
   {acting_member_id}'], 40)`. **`dispatch_context()`** = "You are {agent}, a member of Boards. {asker} mentioned you in a comment on the board
   "{title}" (board {uuid}). The comment is pinned {to the item {id} ("{excerpt}") | at the point ({x}, {y})}. "These" means the items selected
   when it was written: {id (type): excerpt; …} {| none were selected}. The thread so far:\n{thread}\nRead before you change anything (board_overview,
   then board_read_region around those items), do the work on the board with your tools, then answer in this thread once, as yourself, saying in a
   sentence or two what you changed. Never mention an agent."
4. **The answer** (Spaces' rules, verbatim): no answer, a 401 or a 5xx → `failed`; a 409 → `failed` (busy — the backoff retries); another 4xx →
   `refused` with the kernel's `error.message`; `status = pending_approval` (or not finished with an `approval_request_id`) → `awaiting_approval`
   with "Waiting for a person's approval (request {n})"; not finished with a `run_id` → `running`; not finished without one → `failed` "The kernel
   started no run."; finished → `dispatch_finish()`: a run status `failed|error|errored|cancelled|canceled|timeout` with no reply → `failed` with its
   error; else the reply (≤ 8000 characters; empty → "{Agent} finished without a reply.") through `comment_text_to_rich()` with `app.member_id` set
   to the agent for the call and restored after → `bd_dispatch_record(id, 'answered', run, request, runs, NULL, now)`.
5. Log `agent.dispatch` (sent / awaiting), `agent.reply` (`run_id`, `request_id`, `reply_length`, `cost`, `currency` from the kernel's body) and
   `agent.fail` (`status`, `attempts`, `detail`) — the actor the asker for a call, the agent for its reply or failure; `entity_type` `comment`,
   `board_id`; never the words.
**K8** (the kernel waking an agent) is owed: when it lands, step 3's call becomes the wake call; nothing else changes.

## `db/018_dispatch_loop.sql` (additive; `SECURITY DEFINER`, `search_path = public`; `REVOKE … FROM PUBLIC`, `GRANT EXECUTE … TO boards_rw`)
- `bd_thread_text(p_root uuid, p_before timestamptz, p_n integer) RETURNS text` — the root and its live replies created before `p_before`, the last
  `p_n` (12), oldest first, one line each: `{display_name}: {plain_text ≤ 300}`.
- `bd_dispatches_due(p_limit integer, p_now timestamptz)` RETURNS TABLE (`dispatch_id bigint, agent_member_id bigint, agent_name text,
  acting_member_id bigint, acting_name text, board_id uuid, board_title text, comment_id uuid, root_id uuid, conversation_id text, utterance text,
  anchor_item_id uuid, anchor_excerpt text, anchor_x float8, anchor_y float8, context_items jsonb, thread text, attempts integer`) — `status = 'sent'`
  AND `run_id IS NULL` AND `next_attempt_at <= p_now`, the board live, oldest first; the anchor is the ROOT's (`item_id`, its first 80 characters, or
  `anchor_x/y`); `context_items` = `[{item_id, type, excerpt ≤ 80}]` of the comment's live `context_item_ids`; `thread` = `bd_thread_text(root,
  comment.created_at, 12)`.
- `bd_dispatches_running(p_limit, p_now)` — the same columns plus `run_id bigint, started_at timestamptz` (the placeholder's `created_at`, else the
  dispatch's): `status = 'sent'` AND `run_id IS NOT NULL` AND `next_attempt_at <= p_now`. `bd_dispatches_waiting(p_limit, p_now)` — likewise for
  `status = 'awaiting_approval'`.
- `bd_dispatch_record(p_dispatch_id bigint, p_status text, p_run_id bigint, p_request_id text, p_reply jsonb, p_detail text, p_now timestamptz)
  RETURNS uuid` — the dispatch row `FOR UPDATE`; then by `p_status`:
  - `running`: `run_id`, `request_id` set, status stays `sent`, `next_attempt_at = p_now + 20 s`; when `pending_comment_id` is NULL, INSERT the
    placeholder — a reply on the thread's root, authored by the agent, body `bd_rich_text('{agent} is working…')`, `agent_run_id` = the run — and
    keep its id. Answers the placeholder's id.
  - `awaiting_approval`: status, `run_id`, `detail`, `next_attempt_at = p_now + 1 minute`; the placeholder made or its body set to `'{agent} is
    waiting for a person''s approval…'`.
  - `answered`: `p_reply` must be non-empty rich text (else `22023`); the placeholder's body becomes the reply (else a new reply as the agent is
    INSERTed); `reply_comment_id` = that comment, `pending_comment_id` NULL, `answered_at = p_now`, `reply_excerpt = left(plain, 200)`, `run_id`,
    `request_id`, status `answered`; each `member` mention in the reply → `comment_mentions` and `bd_notify(member, 'mention', '{agent} mentioned
    you', excerpt, board, 'comment', reply, agent)`; an `agent` mention is kept as a mention and dispatches nobody; then `bd_notify(acting,
    'agent_replied', '{agent} replied on {board title}', excerpt, board, 'comment', reply, agent)`. Answers the reply's id.
  - `refused`: status `refused`, `detail`, `answered_at = p_now`; the placeholder's body (or a new reply) = `'{agent} could not answer: {detail}'`.
  - `failed`: `attempts + 1`; below 5 → status `sent`, `run_id` NULL, `next_attempt_at = p_now + 2^attempts minutes`, `detail`; at 5 → status
    `failed`, the placeholder's `deleted_at = p_now`, `bd_notify(acting, 'agent_replied', '{agent} could not answer', detail, board, 'comment',
    the asking comment, agent)`. Answers NULL.
  - anything else → `22023`.

## Files (exactly these)
- `db/018_dispatch_loop.sql`
- `html/items/arrange.php`, `html/search.php`, `html/admin/{agents,dispatches,dispatch-retry}.php`
- `app/features/agents/{dispatch,queries,present}.php` (`dispatches_pass()`, `dispatch_agent()`, `dispatch_finish()`, `dispatch_fail()`, `poll_running()`,
  `poll_waiting()`, `dispatch_context()`; the pages' queries), `app/features/search/{queries,present,write}.php` (the search and the catch-up),
  `app/features/items/arrange.php` (`arrange_items()`)
- `app/views/search/index.php`, `app/views/search/partials/result.php`, `app/views/admin/{agents,dispatches}.php`, `app/views/admin/partials/{agent-card,dispatch-row}.php`
- `bin/worker.php`: `worker_pass_dispatches` and `worker_pass_search_catch_up` made real
- `tests/fake_kernel.php`: the chat case answers `GET ?run={n}` from `state.runs[n]` (else `{"run_id": n, "finished": false}`) and appends the run asked
  to `<state>.chat`; POST unchanged (`state.chat` — e.g. `{"run_id": 77, "finished": false}` for a running turn, `{"status": "pending_approval",
  "approval_request_id": 5, "run_id": 78, "finished": false}` for a pause; `state.chat_status` 403 / 409 / 500 for the others)
- `tests/phase3/slice9/{run.sh,lib.php,dispatch.php,arrange.php,search.php,admin.php,shares.php,json.php,browser.mjs}`

## Query functions (signatures fixed)
- `dispatches_pass(PDO, DateTimeImmutable $now, int $limit): array{called, answered, running, awaiting, refused, failed, polled}` and the helpers named above
- `arrange_items(PDO, string $board, int $by, array $itemIds, string $layout, ?string $frame, ?int $columns, float $gap, string $source, ?int $runId): array`
  (the `bd_commit_ops()` answer) — **the arrangement**: the items read live (≤ 500; a frame or a connector among them → 422 "Arrange moves items,
  not frames or connectors"); cells sized by the largest width and height among them; `grid` = `columns` (default `ceil(sqrt(n))`) columns in the
  given order, `column` = one column, `row` = one row, `cluster` = the items grouped by `style.fillColor` (no colour = its own group) in order of first
  appearance, each group a grid of `ceil(sqrt(k))` columns, groups side by side `2 × gap` apart; **with `frame`**: positions frame-relative from
  (40, 40), `parent_id` = the frame, and the frame grown in the same batch to the block plus 40 on every side when it does not hold it; **without**:
  every item must share one parent (else 422 "Name a frame, or arrange items that share one") and the block starts at the items' present top-left;
  one batch of `update` ops (`x`, `y`, and `parent_id` when it changes) — **`bd_layout()`** (canvas-core "The doors (PHP)", `app/features/items/layout.php`,
  slice 2's) gives the centres of the grid, column and row; `cluster` is composed here from it (the update ops are built here, not by
  `items_layout()`, which makes create ops)
- `search(PDO, string $q, ?string $kind, ?string $board, int $page): array{rows, total}` — `mcp_search` joined to `mcp_boards` (`NOT is_template`),
  `websearch_to_tsquery('simple', unaccent($q))`, ordered by `ts_rank_cd`; the excerpt by `ts_headline` with `StartSel=\x01, StopSel=\x02`, then
  escaped through `e()` and the markers turned into `<mark>`/`</mark>` · `search_catch_up(PDO, int $limit): array{indexed, removed}` — live boards
  (not shipped templates), items with text or a title, and comments missing an index row → `bd_search_put()`; index rows whose entity is gone or
  deleted → removed; at most `$limit` each
- `agents_here(PDO): array` · `agent_activity(PDO, ?int $agent, ?string $board, ?string $since, int $limit): array` (`mcp_activity_log` rows whose actor
  is an agent: at, agent, action, the board's title, `after.counts`, the run id) · `find_dispatches(PDO, array $f, int $page): array` · `retry_dispatch(PDO, int $id): void`
  (`failed` → `sent`, `run_id` NULL, `next_attempt_at` now; `awaiting_approval` → `next_attempt_at` now; `attempts` kept; anything else → 422)

## The two K7 shares (Phase 4's tools — their SQL fixed here and proven as the consumer's agent)
- `boards_index` (`app.member_id` = the agent named by `X-OS-Consumer-Agent`): `SELECT b.board_id, b.title, b.owner_name, b.item_count,
  b.last_activity_at AS updated_at, (SELECT COALESCE(array_agg(f.name ORDER BY f.name), '{}') FROM mcp_folder_entries e JOIN mcp_folders f ON
  f.folder_id = e.folder_id WHERE e.board_id = b.board_id) AS folder_names FROM mcp_boards b WHERE NOT b.is_template AND (:q IS NULL OR b.title ILIKE
  '%' || :q || '%') ORDER BY b.last_activity_at DESC LIMIT :limit` — the tool adds `url` = `APP_URL/boards/{id}` and `schema: os.boards-index/1`.
- `board_outline`: `SELECT bd_board_outline(:board)` as the same agent; NULL → `not_found`; over 256 kB → `too_large`.

## MCP tools this slice makes real
`records_search`, `agent_dispatches`, `boards_index`, `agent_activity`.

## Manifest rows claimed
Screens (3): `search`, `admin-agents`, `admin-dispatches`
Actions (2): `items_arrange`, `dispatch_retry`

## Activity log events
`item.move` (`items_arrange`: `seq`, counts, `layout`, `frame`), `dispatch.retry`, `agent.dispatch`, `agent.reply`, `agent.fail`, `screen.view` on the
three screens (`after.q_length` on search — never the words searched). Every row about a board carries `board_id`.

## Status vocabulary
Dispatch: sent `info` "Sending" (with a `run_id`: "Working"), awaiting_approval `warning` "Waiting for approval", answered `success` "Answered",
refused `secondary` "Refused", failed `danger` "Failed". The **agent** chip `info`. Ids: `search-result-{kind}-{id}`, `search-form-field-q`,
`agent-card-{id}`, `dispatch-row-{id}`, `dispatch-retry-{id}`.

## Out of scope for this slice
The MCP servers themselves (Phase 4 — this slice proves the shares' SQL only); K8; agents' duties; a model call of any kind; searching inside images
or files; the agents' skills and job descriptions (Phase 0's).

## Proof (`tests/phase3/slice9/run.sh`: scratch database `bd_dev9`; php -S on 9101, the fake kernel on 9102, the fake MaluDB/MaluMail on 9103; the worker run with `BD_WORKER_NOW`; Playwright from `/var/www/web/node_modules` at 375 × 740 and 1280 × 800; the registry `--check`)
1. A Member's comment mentioning the Facilitator (shared at edit) → one pass posts ONE chat turn: the fake's `.chat` log shows `agent=42`, the acting
   member, the utterance; the context names the board, the anchor and the selected items; the reply lands as the agent's reply comment, the asker gets
   `agent_replied`, a member the reply mentions gets `mention`, the reply dispatches nobody.
2. Running: the fake answers `{run_id: 77, finished: false}` → "Facilitator is working…" in the thread; the next pass polls `?run=77`, the fake's
   `state.runs.77 = {finished: true, reply: …}` → the placeholder becomes the reply (one comment, not two); a run older than 10 minutes → failed.
3. Approval: `pending_approval` → the placeholder says so; the poll with a declined status → refused with the sentence.
4. Refusals and failures: 403 → refused with the kernel's sentence; 500 five times (the clock moved 2, 4, 8, 16 minutes) → failed for good, the
   placeholder gone, the asker told once; 409 → retried.
5. Retry from the admin page puts a failed one back; a Member's retry is 403; an agent's is refused (`people`).
6. `items_arrange`: a grid of 6 inside a frame (frame-relative, the frame grown), a column where they stand, a cluster by colour (two colours → two
   blocks); a frame or a connector in the list → 422; items of two frames without `frame` → 422; one batch, `record_id` = its seq, `ops_undo` takes it back.
7. Search: a sticky's word finds its item (the link selects it), a comment's word its comment, a board's title its board; a private board's rows never
   reach another Member; a shipped template's items never appear; `kind=` and `board=` filter; the catch-up re-indexes a row deleted from
   `search_index` by the fixture and removes a stale one.
8. The shares' SQL as the Facilitator: `boards_index` lists exactly the boards shared with it, no template; `board_outline` of a board it cannot see is NULL.
9. The admin pages render for `agents.manage`, 403 for a Member; JSON mode on all three screens and both handlers; 375 and 1280 with no console
   errors; registry `--check`: 3 screens, 2 actions built, no approval among them.

## Decisions taken here
- The dispatch's context sentence above, and "Never mention an agent" (an agent's reply cannot start another agent's turn).
- An empty reply posts "{Agent} finished without a reply."; a refusal's sentence stays in the thread as the agent's reply.
- The backoff 2, 4, 8, 16 minutes, five attempts; a run polled every 20 s for 10 minutes; an approval wait polled every minute.
- `items_arrange`'s padding 40 and default gap 40; `cluster` groups by fill colour; without a frame the items must share one parent.
- Search leaves out templates' boards and items.
- The slice writes `db/018_dispatch_loop.sql` (functions only; numbered by the planning model: db/014 is slice 3's, db/015 slice 7's, db/016–017 slice 8's).
- (Reconciled by the planning model:) the centres come from canvas-core's `bd_layout()`; `items_layout()` is slice 2's create-ops wrapper over it.

## Open questions
None.

## Built and proven (2026-10-11)

**Built** (a Sonnet 5.5 worker): **`db/018_dispatch_loop.sql`** — `bd_thread_text()`, `bd_dispatches_due()`, `bd_dispatches_running()`, `bd_dispatches_waiting()` (all three over one reader, `bd_dispatch_rows()`) and `bd_dispatch_record()`, each the worker's alone (`bd_require_worker()`), `SECURITY DEFINER`, granted to `boards_rw`; **the dispatch loop** (`app/features/agents/dispatch.php`, the worker's `dispatches` pass real — `dispatches_pass()`, `dispatch_agent()`, `dispatch_finish()`, `dispatch_fail()`, `poll_running()`, `poll_waiting()`, `dispatch_context()`) — Spaces' port, one chat turn through the kit's `kernel_call()`; **`items_arrange`** (`app/features/items/arrange.php`, `html/items/arrange.php`: grid, column, row, cluster, in a frame or where the items stand, one `bd_commit_ops()` batch over `bd_layout()`); **search** (`html/search.php`, `app/features/search/{queries,present,write}.php`, `app/views/search/`: kind chips, the board filter through the record picker, results as cards with the matches marked, 20 a page) and the worker's `search_catch_up` pass real; **the admin's agents and dispatches pages** (`html/admin/{agents,dispatches,dispatch-retry}.php`, `app/features/agents/{queries,present}.php`, `app/views/admin/`); the fake kernel answers `GET ?run=` from `state.runs` and logs every call to `<state>.chat`. The registry reads 3 screens and 2 actions built, no approval among them.

**Proven** by `tests/phase3/slice9/run.sh` — **226 checks under php -S** (dispatch 58, arrange 31, search 30, shares 15, admin 27, json 27, browser 38) and the same under a real Apache, then every earlier suite on both: ONE chat turn per mention (the fake saw one post: agent 42, acting 26, the comment's words, `boards:thread:{root}`, a context naming the board, where it is pinned, the items selected in order, the thread, the manners), the reply as ONE comment by the agent with its run, the asker's `agent_replied`, a member the reply names told, the reply dispatching nobody, no word of the comment or the reply in a log row; a running turn ("… is working…" in the thread, polled every 20 s, the placeholder BECOMING the reply, a run older than ten minutes failed and retried); an approval pause (said in the placeholder, polled once a minute, declined → refused in the kernel's words, approved → answered, a run the kernel forgot → refused); a 403 refused and not retried, a 409 retried, a 500 five times failed for good over the 2, 4, 8 and 16 minute pauses with the asker told once and the placeholder deleted; Retry from the admin page (a Member 403, an agent refused, an answered one 422, a waiting one polled at once); `items_arrange` (a grid of six in a frame — frame-relative, the frame grown —, a column and a row where they stand, a cluster by colour, the refusals, one batch whose `record_id` is its seq, `ops_undo` taking it back, an agent's retried call committing once); search (an item, a comment, a board, a frame by its title; a private board's rows, a shipped template's, a personal and a business template's never reaching anyone; `kind=` and `board=`; best first; escaped with `<mark>`; paged; the catch-up re-indexing a deleted row and removing stale ones); the shares' SQL as the Facilitator on the application role and on the records role; both admin pages for `agents.manage` and 403 for a Member; JSON mode on everything; the browser at 375 and 1280.

**Found and fixed:** a pass's clock must reach the database WITH its microseconds — `next_attempt_at` is set by `now()`, and a clock cut to the second made a dispatch created a moment ago "not yet due" (no call was made); Postgres' `ts_headline` drops the markup a person typed from an excerpt and leaves its gap (the excerpt is collapsed to single spaces; a typed `<script>` never reaches the page); the proofs' own words had to differ from the seed's (the browser seed shares the scratch database); Phase 2's browser proof asserted `/search` was slice 9's placeholder — it now follows whether `html/search.php` still calls `render_nav_stub()` (the way it follows the templates).

**Decisions taken here (within the spec):**
1. **One reader behind the three offers** (`bd_dispatch_rows(state, limit, now)`); the running and waiting functions also answer `request_id` (the finished answer's request id is recorded from it).
2. **The attempts:** `attempts` starts at 1 and counts calls made plus one; a failure retries while the attempts it had are below 5 (pause `2 ^ attempts` minutes, at most 60) — five calls in all, the pauses 2, 4, 8, 16 — and the fifth is for good with `attempts` 6. Spaces' rule. **Retry keeps the attempts (the spec's word), so a retried dispatch that fails again fails for good at once** — one more call, not five. The planning model may want Retry to reset them.
3. **The asker is not told twice:** a reply that names the asker gives them the `agent_replied` notice only, not a `mention` as well.
4. **A refusal's sentence stays in the thread as the agent's reply** (`reply_comment_id` set, the placeholder or a new comment), as the spec's decisions say; no notice (the asker sees it where they asked).
5. **A placeholder stays "… is working…" through a backoff** (it is not deleted until the fifth failure); while it stands it is in the search index, and leaves it when replaced or deleted.
6. **The agents page** counts boards the agent can open through `bd_member_board_levels()` (live, no template), lists at most ten shared by name and only those the caller can open; its "this week" is the activity log's `item.*`/`ops.undo` rows (batches) and `comment.*`/`agent.reply` rows (comments) of the agent in the last seven days; the OS link is `os_url` of the switcher's feed + `agents`, else the launcher.
7. **The dispatches page's wall:** the board's title and the comment's first 80 characters come through `mcp_boards` and `mcp_comments`, so a dispatch on a board the caller cannot open (even a Boards admin: one in the trash) shows "a board you cannot open" and no words. A dispatch is shown with its raw `attempts`.
8. **Search:** `kind` is `board`, `item` or `comment`; an unknown kind or a board that is not a uuid is ignored; a board result's title is highlighted, an item's by its words (a frame's by its title), a comment's by its words; the excerpt is up to 30 words around the first match; `screen.view` carries only `q_length`, the kind and the total.
9. **`items_arrange`:** duplicate ids are dropped; `gap` is 0–500 (default 40), `columns` 1–20; a cluster's no-colour item is a group of its own; without a frame the block starts at the items' present top-left in THEIR coordinates (frame-relative when they share a frame) and nothing is resized.
10. **`agent.dispatch` is logged when the call is made and again, with `status: awaiting_approval`, when the kernel pauses the turn** (the spec's "sent / awaiting").
11. **Retry is one more call** (ruled by the planning model at review): `dispatch_retry` keeps the attempts, so a retried dispatch that fails again
    fails for good after that one call — the same as Spaces' `retry_dispatch()` (`attempts = least(attempts, 5)`). A person's Retry is a deliberate
    try after the cause is fixed, not a fresh round of five unattended calls.
