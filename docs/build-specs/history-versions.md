# Build spec: history, versions and restore (slice 7)

What exists at the end: a board's **history page** — what changed, by whom (a person, an agent, a restore), where (click to fly there), a version's
preview as its outline, named versions saved by an editor, a restore by an owner that is never destructive (the board as it is now is saved first,
then ONE batch turns it into the version) and that every open canvas receives live like any other batch; the visits (who opened the board); the
worker's `versions` and `compaction` passes real (an interval version at most hourly while a board changes, thinned to one a day, ops past
retention compacted, items deleted long ago purged with their files).

Exemplar to replicate: **slice 1 (boards-folders)** for the page and the two handlers (the handler order `bd_handler_begin()` → `require_right()` →
`bd_require_level()` → `bd_guard()` → `log_activity()` → `emit_action_status()` → `bd_done()`); the restore's batch reaches the canvases through
`bd_commit_ops()` and the trigger — nothing on the canvas changes in this slice (`canvas-core.md` "The op protocol": a large batch arrives as `{seq}`
and the island reads it with `GET /boards/{board}/ops?after=`).

Schema and functions used (db/007, 008, 011, 012, 013 — never modified): `board_ops`, `board_versions`, `bd_save_version()`, `bd_restore_version()`,
`bd_board_snapshot()`, `bd_version_outline()`, `bd_interval_versions()`, `bd_prune_versions()`, `bd_compact_ops()`, `bd_purge_deleted_items()`,
`mcp_board_changes`, `mcp_board_versions`, `mcp_boards` (`my_last_visit`), `mcp_activity_log`, `board_recents`. **The database is the referee**: who may
save (`edit`), who may restore (`full`), what a restore changes (the diff of the snapshot and today — PHP never computes one), the version numbers,
which versions are thinned, which ops are compacted, which items are purged.

## Divergences from the design
1. **A version's preview is its outline**, not a picture: `bd_version_outline()` rendered as headings and lines (the canvas never shows an old version;
   the outline is the 375 px reader's shape, the same Markdown `board_outline` gives). The page offers **Restore this version** beside it.
2. **The visits panel** (who opened the board, A1) reads `board.open` rows of `mcp_activity_log` for the board — the local log, never MaluDB — for the
   last 30 days.

## Who may do what
| Action | Right | Level / rule |
|---|---|---|
| (the page) | `boards.view` | `view` on the board; the visits panel to `edit` and above |
| version_save | `boards.edit` | `edit` (`bd_save_version(…, 'manual', label)`) |
| version_restore | `boards.edit` | `full` (`bd_restore_version()`); pauses for an agent (`other`) |

## Screens (375 px first)
| Screen id | Canonical URL | Purpose |
|---|---|---|
| `board-history` | `/boards/{board}/history?version=&member=&source=&since=` | three anchored sections. **Changes** (`history-changes`): `mcp_board_changes` newest first, paged 50, grouped by day; a row = the avatar and name (an agent with the **agent** chip, a restore with **restore**), what (`created 3 stickies · moved 1 · deleted 1 frame` from `summary.counts` and `summary.types` — the words in `change_words()`), when (relative, the exact time on hover), **Show** (`/boards/{board}?x={cx}&y={cy}&zoom=1` — the centre of `summary.bbox`; none when the batch had no box) and, for an undo, "undid #{seq}"; filters `member`, `source` (web, agent, command_bar, restore), `since` (a date); with no `since`, a divider **Since your last visit** at `my_last_visit`. **Versions** (`history-versions`): `mcp_board_versions` newest first — the label (named versions bold), the reason in words (interval "Autosaved", manual "Saved", before_restore "Before a restore", restore "Restored", compaction "Kept at clean-up"), items, who, when; **Preview** (`?version=N`) and, for `full`, **Restore** (an `hx-confirm` "Restore version N? The board as it is now is saved first."); the form **Save this moment** (label, ≤ 120) for `edit`. **The preview** (`history-preview`, when `?version=` is given): the version's header and its outline (`outline_html()`), **Restore this version** (full), **Back to the history**. **Visits** (`history-visits`, `edit` and above): who opened the board, last 30 days — name, times, last opened |

## Files (exactly these)
- `html/boards/history.php` (the screen and, with `?part=changes&page=`, the changes' next page partial), `html/boards/{version-save,version-restore}.php`
- `app/features/history/{queries,present,write}.php`
- `app/views/history/page.php`, `app/views/history/partials/{change-row,version-row,preview,visits,save-form}.php`
- `bin/worker.php`: `worker_pass_versions` and `worker_pass_compaction` made real (below)
- `tests/phase3/slice7/{run.sh,lib.php,history.php,restore.php,worker.php,json.php,browser.mjs}`

## Query functions (signatures fixed)
- `board_changes(PDO, string $board, array $f, int $page): array{rows, total, last_visit}` (over `mcp_board_changes`; `$f` = member, source, since) ·
  `change_words(array $summary): string` ("created 3 stickies · moved 1 · deleted 1 frame" — verbs from `counts`, nouns from `types` singular/plural:
  sticky, shape, text, connector, frame, drawing, image, file) · `change_fly_to(array $summary): ?array{x, y}`
- `board_versions(PDO, string $board, int $limit): array` (over `mcp_board_versions`) · `version_outline(PDO, string $board, int $no): ?string` (`bd_version_outline()`) ·
  `outline_html(string $markdown): string` (the outline's own Markdown only: `#`/`##` headings, `- ` lines, `**bold**` — escaped through `e()`, nothing else interpreted)
- `board_trail(PDO, string $board, ?string $since, int $limit): array` (`board.open` and item events of `mcp_activity_log` with `board_id`, grouped by member: name, kind, count, last at)
- `version_save(PDO, string $board, int $by, string $label): int` (→ `bd_save_version(board, by, 'manual', label)`, the version number) ·
  `version_restore(PDO, string $board, int $by, int $no, ?string $clientBatchId): array` (→ `bd_restore_version()`'s answer: `seq`, `restored_version`, or `noop`)
- `versions_pass(PDO, DateTimeImmutable $now): array{saved, thinned}` (`bd_interval_versions()`, then `bd_prune_versions()`) ·
  `compaction_pass(PDO, DateTimeImmutable $now): array{compacted}` (`bd_compact_ops()` only — items deleted past op_retention_days and their
  files are purged by slice 5's `thumbnails` pass through `purge_deleted_items()`, built 2026-10-11: `docs/phase1-consistency.md` 17)

## Handlers
- `version-save.php` (`version_save`): `require_right('boards.edit')`; `bd_require_level($board, 'edit')`; `label` 1–120 characters (`bd_text`, required);
  `log_activity('version.save', 'board', …, ['board_id', 'after' => ['version_no', 'label']])`; `emit_action_status(true, ['record_id' => $no,
  'location' => "/boards/{$board}/history?version={$no}"])`; `HX-Trigger: versionChanged`.
- `version-restore.php` (`version_restore`): `require_right('boards.edit')`; `bd_require_level($board, 'full')`; `version` an integer of this board
  (`bd_ref` over `mcp_board_versions`); `client_batch_id` = `md5('restore:' . request_id())` formatted as a uuid (`request_id()` honours the
  kernel's `X-Request-Id`, so the kernel's retry of the same call answers the first commit); `bd_guard()`
  around `bd_restore_version()`; `log_activity('version.restore', 'board', …, ['after' => ['version_no', 'seq', 'noop']])`; `record_id` = the batch's
  `seq`; the reply "Restored version N — {n} changes in one batch; the board as it was is version {N+1}" (or "Nothing to restore — the board already
  matches version N"); `HX-Trigger: versionChanged`. An agent's call pauses before it arrives (`other`).

## The worker's two passes (`bin/worker.php`)
- `worker_pass_versions` → `versions_pass()`: `{saved, thinned}`. Runs every pass; `bd_interval_versions()` decides what is due (a board that
  changed since its last version, at most hourly). Never a version of a shipped template (they never change).
- `worker_pass_compaction` → `compaction_pass($pdo, $now, storage_dir())`: only when the local hour is 03 (the business's time zone,
  `bd_settings.timezone`) and no `worker_passes` row of today carries `compaction` — once a night; `{compacted}`.
- Both log through the worker's `worker.pass` row; neither logs an item's text.

## MCP tools this slice makes real
`board_changes`, `board_versions`, `version_outline`, `board_trail` (the page reads the same views and functions the tools will).

## Manifest rows claimed
Screens (1): `board-history`
Actions (2): `version_save`, `version_restore`

## Activity log events
`version.save` (`version_no`, `label`), `version.restore` (`version_no`, `seq`, `noop`), `screen.view` on `board-history` with `board_id` (and
`after.version` when previewing). Every row carries `board_id`. The worker's passes are inside `worker.pass` (counts only).

## Status vocabulary
Sources: web none, agent `info` "agent", command_bar `secondary` "command bar", restore `warning` "restore". Version reasons as in the screen's
words; a named version **bold**. Ids: `history-changes`, `history-versions`, `history-preview`, `history-visits`, `change-row-{seq}`,
`version-row-{no}`, `version-form-field-label`, `version-restore-{no}`.

## Out of scope for this slice
A visual (canvas) preview of an old version; comparing two versions side by side; restoring part of a board; versions of comments; anything on the
canvas (the restore's batch is handled by slice 2's protocol); the relay.

## Proof (`tests/phase3/slice7/run.sh`: scratch database `bd_dev7`; php -S on 9101, the fake kernel on 9102, the fake MaluDB/MaluMail on 9103; Playwright from `/var/www/web/node_modules` at 375 × 740 and 1280 × 800; the registry `--check`)
1. Changes: three batches by two people and one by an agent (a fixture `board_ops` row through `bd_commit_ops()` as the agent) render in order with
   their words, chips and **Show** links at the batch's centre; `member=` and `source=agent` filter; **Since your last visit** at the right place.
2. Save a named version as an editor; a commenter's save is refused (`edit`); the version appears bold with its label.
3. Restore as an owner: the board matches the version (a moved sticky back, an added one gone, a deleted one back), ONE `board_ops` row with
   `source = restore`, versions `before_restore` and `restore` written; an editor's restore is refused; restoring the current state answers "Nothing
   to restore"; an agent's `version_restore` pauses (`other`).
4. The preview: `?version=1` renders the outline (frames as headings, stickies as lines) for a viewer; a person without access gets 404.
5. Visits: `board.open` rows of two members, counted; hidden from a commenter.
6. The worker: a changed board gets one interval version, a second pass in the same hour none (`BD_WORKER_NOW`); thinning keeps one a day past
   `interval_version_days`; at 03:xx compaction removes ops older than `op_retention_days` (rows aged in the fixture) and purges an item deleted
   long ago with its two fixture files (both unlinked); a fixture path outside the storage directory is left and logged; not at 04:xx.
7. Browser: the page at 375 (sections stacked, no horizontal scroll) and 1280; Restore's confirm; after a restore, an open canvas in a second page
   shows the restored board within 5 s (the poll path; the relay need not run).
8. JSON mode on the screen and both handlers; registry `--check`: 1 screen, 2 actions built, `version_restore` the approval.

## Decisions taken here
- A version's preview is its outline (no canvas rendering of an old state in v1).
- Compaction runs once a night at 03:xx in the business's time zone; interval versions every pass.
- The visits panel shows to `edit` and above (who looked at a board is an owner's and an editor's business, not a viewer's).
- The restore's `client_batch_id` is derived from the request id (`md5('restore:' . request_id())`), so the kernel's retry of one call never restores
  twice; a person's second click is a second request — it finds the board already matching and answers "Nothing to restore" after its own
  `before_restore` version.

## Open questions
None.
