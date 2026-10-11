# Build spec: templates, duplicate and export (slice 8)

What exists at the end: the **templates gallery** (shipped, the business's, mine — by category) and a template's page (its outline, Use, Open);
six shipped templates seeded by a migration; a board duplicated, saved as a personal template, published for the business (an admin's; paused for an
agent); a board made from a template into a folder; the board's **export**: PNG of the view, a frame or the selection (made by the island) and the
SVG dialect as a download (made by the database's one renderer), both logged.

Exemplar to replicate: **slice 1 (boards-folders)** for the pages, the cards and the handlers (`bd_handler_begin()` → `require_right()` →
`bd_require_level()` → `bd_guard()` → `log_activity()` → `emit_action_status()` → `bd_done()`); **slice 2 (canvas)** for the island's export menu
(`canvas-core.md` "Exports").

Schema and functions used (db/006–013 and this slice's `db/016_seed_templates.sql` — the only migration this slice writes): `boards`
(`is_template`, `template_scope`, `template_category`, `duplicated_from`), `bd_board_duplicate()`, `bd_folder_add()`, `bd_board_level()`,
`bd_region_svg()`, `bd_board_overview()` (`extent`), `bd_board_outline()`, `mcp_boards`, `mcp_folders`. **The database is the referee**: who may use a
template (`view` on it + `boards.create`), who may publish (`templates.publish`), the copy's ids and mapping, the copy being private, the cap.

## Divergences from the design
1. **A template's page shows its outline**, not a picture (the same as slice 7's version preview, `outline_html()` reused); **Open** takes the person
   to the template on the canvas (read-only for a viewer — slice 2's canvas at `view`).
2. **The SVG download of a whole board is the region tool's dialect over the board's extent, up to 400 items** (the renderer's cap, db/013). Past it
   the door answers 422 "This board holds {total} items — export a frame, or a PNG, instead" (canvas-core "The doors (PHP)": a file that
   passes for the whole board never holds part of it). A frame's export holds that frame; PNG has no such limit.
3. **Duplicating a frame** is the canvas's copy and paste (slice 2), not an action here.

## Who may do what
| Action | Right | Level / rule |
|---|---|---|
| (the gallery, a template's page) | `boards.view` | a shipped or business template: every insider (not an external); a personal one: its maker (`bd_board_level` ≥ `view`) |
| board_duplicate | `boards.create` | `view` on the source |
| template_use | `boards.create` | `view` on the template |
| template_save | `boards.create` | `view` on the source; the template is personal |
| template_publish | `templates.publish` | `view` on the source; pauses for an agent (`other`) |
| (export) | `boards.export` | `view` on the board |
| (delete a template) | — | slice 1's `board_delete` (`full` — its maker, or an admin for a business template) |

## Screens (375 px first)
| Screen id | Canonical URL | Purpose |
|---|---|---|
| `templates` | `/templates?category=&scope=` | category chips (All · Retrospective · Brainstorm · Strategy · Planning · Diagram · Workshop · Other) and scope chips (All · Shipped · The business's · Mine); cards (`template-card-{id}`, slice 1's board card partial with the scope chip instead of the owner): the thumbnail (`/files/{thumbnail_attachment_id}/256` when the template has one, else the category's tile — a coloured block with the category's feather icon), title, item count; **Use** opens the use form inline (`template-use-form-{id}`: title — the template's by default —, folder — the record picker over `mcp_folders`) and **Preview** → `template-view` |
| `template-view` | `/templates/{board}` | the header (title, the category and scope chips, items, by whom — "Shipped with Boards" for a shipped one), the outline (`outline_html(bd_board_outline())`), **Use** (the same inline form), **Open on the canvas** (`/boards/{board}`), **Delete** for whoever may (slice 1's `board_delete`, `hx-confirm`) |

**On the board**: slice 1's settings page (`app/views/boards/settings.php`, as `boards-folders.md` fixes it) includes this slice's
`app/views/templates/partials/board-actions.php` above its Delete section — one line, `<?= view('templates/partials/board-actions', ['board' => $board,
'level' => $level]) ?>` — rendering **Duplicate** (title, folder), **Save as template** (title, category) and, for `templates.publish`, **Publish for
the business** (title, category, `hx-confirm` "Every Member will see it"). **On the canvas** (slice 2's menu, `client/src/ui/menu.ts` as
`canvas-core.md` names it): **Export** → PNG of the view · PNG of the selection (greyed with no selection) · PNG of a frame (the selected frame, else
the one under the view's centre) · SVG of the board · SVG of the frame.

## Files (exactly these)
- `db/016_seed_templates.sql` (below)
- `html/templates/{index,view,use,save,publish}.php`, `html/boards/{duplicate,export}.php`
- `app/features/templates/{queries,present,write}.php`
- `app/views/templates/{index,view}.php`, `app/views/templates/partials/{card,use-form,board-actions,category-tile}.php`
- `client/src/ui/export.ts` (the menu's Export entries; PNG with Konva's `stage.toDataURL({x, y, width, height, pixelRatio: 2})` over the box, the
  board's background colour filled first, downloaded as `{board title} — {scope}.png` through a temporary `<a download>`; then `POST
  /boards/{board}/export` with `format=png&scope=view|selection|frame`) — and the rebuilt bundle with its manifest (`bin/check_bundle.php` passes)
- `tests/phase3/slice8/{run.sh,lib.php,templates.php,duplicate.php,export.php,seed.php,json.php,browser.mjs}`

## Query functions (signatures fixed)
- `find_templates(PDO, array $f, int $page): array{rows, total}` (over `mcp_boards` where `is_template`; `$f` = category, scope; shipped first, then by title) ·
  `find_template(PDO, string $board): ?array` (the view's row, `is_template` true, else null → 404)
- `board_duplicate(PDO, string $board, int $by, ?string $title, ?int $folder): string` (→ `bd_board_duplicate(board, by, title)`, then `bd_folder_add()` when
  a folder is given; the new board's id) · `template_use(PDO, string $template, int $by, ?string $title, ?int $folder): string` (the same, the template's
  title by default) · `template_save(PDO, string $board, int $by, string $title, ?string $category): string` (`bd_board_duplicate(…, 'personal', category)`) ·
  `template_publish(PDO, string $board, int $by, string $title, ?string $category): string` (`…, 'business', category`)
- `board_export_svg(PDO, string $board, ?string $frame): array{svg, items, total, truncated}` (the door refuses a whole board when `truncated`) (a frame: `bd_region_svg(board, NULL×4, 400, frame)`; the board:
  `bd_region_svg` over `bd_board_overview(board)->extent` padded by 50, limit 400; an empty board answers an empty `<svg>`)

## Handlers and the export door
- `duplicate.php` (`board_duplicate`), `templates/use.php` (`template_use`), `templates/save.php` (`template_save`), `templates/publish.php`
  (`template_publish`): `require_right()` as above; the source's level (`bd_require_level($board, 'view')`); `title` ≤ 200 (`bd_text`), `category` one of
  the seven (`bd_choice`), `folder` an id the caller keeps (`bd_ref` over `mcp_folders`); `bd_guard()`; `log_activity(event, 'board', $new, ['board_id'
  => $new, 'after' => ['source_board' => $board, 'title', 'scope', 'category', 'folder_id']])`; `record_id` = the new board's id; `location` =
  `/boards/{new}` (a template: `/templates/{new}`); `HX-Trigger: boardChanged`.
- `export.php` (a door, not an action): GET `?format=svg[&frame={item}]` → `bd_require_level($board, 'view')` and `require_right('boards.export')`;
  `Content-Type: image/svg+xml; charset=utf-8`, `Content-Disposition: attachment; filename="{title}.svg"`, `Content-Security-Policy: sandbox`;
  logs `board.export` (`after`: format, scope, items, truncated). POST (CSRF) `format=png&scope=` → logs `board.export` and answers 204.

## The seed — `db/016_seed_templates.sql` (shipped, `owner_member_id` NULL, `created_by` NULL; idempotent: every INSERT `ON CONFLICT (id) DO NOTHING`)
Board ids `7e000000-0000-4000-8000-00000000000N` (N = 1…6); an item `7e00000N-0000-4000-8000-0000000000KK` (KK = two hex digits, the row's number
below). Per template **three INSERT statements in this order**: its frames; its other non-connector items; its connectors (each needs what it stands
on). `z` = `a0`, `a1`, … `a9`, `aA`, `aB` in the item order below. A child's x/y are relative to its frame's top-left (Miro's convention). Stickies are
200 × 200, `style` `{"fillColor": "<colour>"}`; texts are `{"fontSize": 40}` unless said; every text is `bd_rich_text('<text>')` in `data.content`; a
frame's title is `data.title`; a shape's kind is `data.shape`. Then `COMMIT`.

| N | Board title · category | Items: KK type (parent) x, y, w × h — text · style |
|---|---|---|
| 1 | Retrospective · retrospective | 01 text 0, −520, 800×80 — "Retrospective" (fontSize 48) · 02 frame "Went well" −650, 0, 600×900 · 03 frame "To improve" 0, 0, 600×900 · 04 frame "Actions" 650, 0, 600×900 · 05 sticky (02) 130, 130 — "What went well?" light_green · 06 sticky (03) 130, 130 — "What could be better?" light_pink · 07 sticky (04) 130, 130 — "Who does what, by when?" light_yellow |
| 2 | Brainstorm · brainstorm | 01 text 0, −620, 1000×80 — "What should we brainstorm?" · 02 frame "Ideas" −300, 0, 1600×1000 · 03 frame "Parking lot" 900, 0, 600×1000 · 04 sticky (02) 130, 130 — "One idea per sticky" light_yellow · 05 sticky (02) 370, 130 — "Build on others' ideas" light_yellow · 06 sticky (02) 610, 130 — "Quantity first, judge later" light_yellow · 07 sticky (03) 130, 130 — "Off topic, worth keeping" gray |
| 3 | SWOT analysis · strategy | 01 text 0, −760, 800×80 — "SWOT analysis" (fontSize 48) · 02 frame "Strengths" −375, −325, 700×600 · 03 frame "Weaknesses" 375, −325, 700×600 · 04 frame "Opportunities" −375, 325, 700×600 · 05 frame "Threats" 375, 325, 700×600 · 06 sticky (02) 130, 130 — "What do we do well?" light_green · 07 sticky (03) 130, 130 — "Where do we fall short?" light_yellow · 08 sticky (04) 130, 130 — "What could we use?" light_blue · 09 sticky (05) 130, 130 — "What could hurt us?" light_pink |
| 4 | User story map · planning | 01 text 0, −900, 1200×80 — "Persona: who are we building for?" (fontSize 36) · 02 frame "Activities" 0, −600, 2400×300 · 03 frame "Release 1" 0, −100, 2400×500 · 04 frame "Release 2" 0, 500, 2400×500 · 05–08 stickies (02) at 300, 900, 1500, 2100 (y 150) — "Activity 1"…"Activity 4" light_blue · 09, 0A stickies (03) 300, 150 and 900, 150 — "User story" light_yellow · 0B, 0C stickies (04) 300, 150 and 900, 150 — "User story" light_yellow |
| 5 | Flowchart · diagram | 01 shape 0, 0, 200×100 — "Start" (`data.shape` ellipse) · 02 shape 0, 250, 240×120 — "Step" (rectangle) · 03 shape 0, 520, 240×160 — "Decision?" (diamond) · 04 shape −320, 800, 240×120 — "Do this" (rectangle) · 05 shape 320, 800, 240×120 — "Do that" (rectangle) · 06 shape 0, 1060, 200×100 — "End" (ellipse) · connectors (`data.shape` elbowed, `style` `{"endStrokeCap": "arrow"}`): 07 01→02 · 08 02→03 · 09 03→04 (`data.captions` `[{"content": "yes"}]`) · 0A 03→05 (`[{"content": "no"}]`) · 0B 04→06 · 0C 05→06; every shape's `style` `{"fillColor": "white", "borderColor": "#1a1a1a"}` |
| 6 | Weekly planning · planning | 01 text 0, −1050, 800×80 — "Week of …" (fontSize 48) · 02 frame "Goals this week" 0, −800, 2450×300 · 03–07 frames "Monday", "Tuesday", "Wednesday", "Thursday", "Friday" at x −1000, −500, 0, 500, 1000 (y 0), 450×1100 · 08–0A stickies (02) 130, 150 · 370, 150 · 610, 150 — "Goal" light_green · 0B sticky (03) 225, 130 — "Plan the week" light_yellow |

Each board's `description` = one sentence of what it is for (retrospective: "Look back on a piece of work: what went well, what to improve, who
does what next."; brainstorm: "Collect ideas on one question, one per sticky, and park what is off topic."; SWOT: "Strengths, weaknesses,
opportunities and threats, one quadrant each."; user story map: "Activities across the top, stories under them, sliced into releases.";
flowchart: "A start, steps, a decision and an end, joined by connectors — edit the words and add steps."; weekly planning: "This week's goals and a
column a day.").

## MCP tools this slice makes real
`find_templates` (the gallery reads the same view the resolver will).

## Manifest rows claimed
Screens (2): `templates`, `template-view`
Actions (4): `board_duplicate`, `template_use`, `template_save`, `template_publish`

## Activity log events
`board.duplicate`, `template.use`, `template.save`, `template.publish` (each with `board_id` = the new board and `after.source_board`), `board.export`
(`format`, `scope`, `items`, `truncated`), `screen.view` on both screens with `board_id` on `template-view`.

## Status vocabulary
Scope chips: shipped `primary` "Shipped", business `info` "The business's", personal `secondary` "Mine". Category chips `light`. Ids:
`template-card-{id}`, `template-use-form-{id}`, `template-form-field-{name}`, `board-actions`, `export-menu`, `export-{format}-{scope}`.

## Out of scope for this slice
A template's picture preview; editing a shipped template (it is read-only: its level is `view` for everyone); PDF export (Extended); importing a Miro
board; template sharing beyond the three scopes; duplicating a frame (slice 2's copy and paste).

## Proof (`tests/phase3/slice8/run.sh`: scratch database `bd_dev8`; php -S on 9101, the fake kernel on 9102, the fake MaluDB/MaluMail on 9103; Playwright from `/var/www/web/node_modules` at 375 × 740 and 1280 × 800; the registry `--check`)
1. The seed: six shipped templates, owner NULL, the item counts (7, 7, 9, 12, 12, 11), every child inside its frame's box, the flowchart's six
   connectors attached with their captions; re-applying `db/016` changes nothing.
2. The gallery as a Member (six shipped), filtered by category and scope; an external sees none; a Viewer sees them but has no **Use**.
3. Use the retrospective into a folder: a private board owned by the Member with 7 items and the frames' children mapped; Duplicate a board ("(copy)"
   title); Save as template (personal — another Member cannot see it); Publish as an admin (every insider sees it, an external does not); a Member's
   publish is refused with the database's sentence; an agent's `template_publish` pauses (`other`).
4. A template's page: the outline's headings; Delete for the maker of a personal template only.
5. Export: the SVG door answers `image/svg+xml` with the dialect (a frame's export frame-relative), `Content-Security-Policy: sandbox`, a 404 without
   access; a fixture board of 450 items is refused 422 with the sentence and its frame exports whole; the PNG log POST answers 204; both log `board.export`.
6. Browser: Export → PNG of the view downloads a non-empty PNG (Playwright's download event); the gallery at 375 with no horizontal scroll; no
   console errors.
7. JSON mode on the screens and the four handlers; registry `--check`: 2 screens, 4 actions built, `template_publish` the approval.

## Decisions taken here
- The six templates' exact contents (the table above) and ids `7e…`.
- A template's page shows the outline, and Open goes to the canvas.
- (Reconciled by the planning model with canvas-core:) a whole board over 400 items is refused 422 with "export a frame, or a PNG, instead" — never a truncated file.
- Slice 8 adds one include line to slice 1's settings page for Duplicate / Save as template / Publish.

## Open questions
None.

## Built and proven (2026-10-11)

**Built** (a Sonnet 5.5 worker): the gallery (`html/templates/index.php`, category and whose chips as plain links keeping each other, cards in slice 1's look with the scope chip and the category's tile or the board's preview, **Use** opening the title-and-folder form in place in a `<details>` — no JavaScript needed — and a Viewer shown a Preview only), a template's page (header, chips, the outline through `outline_html()`, the Use card, Open on the canvas, Delete for the maker of a personal or business template and never for a shipped one), the four actions each one `bd_board_duplicate()` (+ `bd_folder_add()` when a folder is given) — `board_duplicate`, `template_use`, `template_save`, `template_publish` — with `app/features/templates/{queries,present,write}.php`, **Copies and templates** on the settings page (`board-actions.php`: Duplicate, Save as template, Publish for an admin with its `hx-confirm`), the board menu's Export group (`#export-menu`), the export door (`html/boards/export.php`: GET the SVG, POST the PNG's log → 204) and the island's `ui/export.ts`; **`db/016_seed_templates.sql`** (generated, idempotent) seeds the six shipped templates. The registry reads 2 screens and 4 actions built, `template_publish` the approval.

**Proven** by `tests/phase3/slice8/run.sh` — **235 checks under php -S and 235 under a real Apache** (templates 51 — two added at review for db/017 —, duplicate 66, export 41, json 35, browser 42), then every earlier suite on both: the seed (six shipped templates, no owner, 7/7/9/12/12/11 items, all 23 children inside their frames' boxes, the flowchart's six attached connectors with "yes" and "no", re-applying changes nothing, no search rows); the gallery (shipped first then by title, `category=`/`scope=`, an external sees none, a Viewer sees six and has no Use, a stranger's personal template is neither listed nor opened); the page (outline, Open, no Delete for a shipped one); Use into a folder (a private board of the Member's, 7 items, the frames' children mapped to the new frames, the template untouched); Duplicate ("(copy)", the connector's ends mapped to the copies, nothing shared with the source, a viewer's copy hers); Save as template (personal, category or `other`); Publish (a Member refused 403 — and the database says the same in its words —, an admin's seen by every insider and not by an external; an agent holds no right); the registry (`template_publish` pauses `other`, the other three do not); the export door (image/svg+xml, attachment, `Content-Security-Policy: sandbox`, a frame's export frame-relative, 404 without access, 403 without the right, an empty board an empty `<svg>`, 450 items refused 422 with the sentence and the crowd's frame exported whole, exactly 400 allowed, the PNG log 204, each logged as `board.export`); JSON mode on everything and the log vocabulary; the browser — real downloads read back as PNGs (the view at twice the stage's pixels, the selection 3496 × 1296, a frame 1600 × 1200, pixels sampled), the SVGs, the greyed entries, the gallery and the forms at 375 and 1280, a folder chosen through the record picker, Publish's confirm dismissed then accepted, no console error.

**Found and fixed:** the spec named `db/015`; slice 7 had taken it, so the seed is **`db/016_seed_templates.sql`** (this spec, the consistency record and the design now say so). db/010 keeps a shipped template's *board* out of the search index but its items' words were indexed — the seed deletes them (the db/010 trigger stays as it is: a lookup on every item write was not worth it for a template that never changes). **Open for the planning model:** the spec says a shipped template's level is `view` for everyone, but db/006's wall gives a Boards admin `full` on every board, so an admin can edit a shipped template on its canvas and save versions of it; nothing here changes the level (a level is the schema's meaning) — the page offers an admin no Delete for one.

**Decisions taken here (within the spec):**
1. **The PNG is drawn by the island into a canvas of its own** from the store with the canvas's draw functions (`drawItem`, `drawConnector`), not `stage.toDataURL`: the stage draws what the camera shows (culled, flat below 40 %), so a box outside the view or below 40 % could not be had from it. A selection or a frame is 2 px to a canvas unit whatever the zoom (at most 4096 px a side); the view is twice what the screen shows. A selection holds the selected items, a selected frame's children and the connectors between them, 24 around; the background is the board's colour. An image not yet loaded is its placeholder.
2. **The gallery lists no one else's personal template, even to a Boards admin** (who can open one by its address): it is for choosing what to use. `scope=mine` means `personal`.
3. **The rights are asked before the board** (slice 7's rule), so a Member's publish is refused 403 "You may not publish a business template."; the database's own sentence is proven by calling the function directly.
4. **A card's picture is the board's own preview** (`/boards/{id}/thumbnail`, slice 2's door) when it has one, else the category's tile — not `/files/{id}/256`, which does not serve a board's preview.
5. **Ids with several forms on a page** take the template's id as a suffix (`template-form-field-title-{id}`); the page with one form uses the bare name. The settings page's three forms use `-save` and `-publish`.
6. **A frame's SVG is named `{board} — {frame}.svg`**, the board's `{board}.svg` (names made safe: an ASCII fallback and `filename*`). The canvas menu's Duplicate and Save as template are links to the settings page's forms.
7. **A deleted template lands on `/boards`** with slice 1's notice (the delete handler is slice 1's and unchanged).
8. **A shipped template is `view` for everyone, a Boards admin included** — decided by the planning model at review (the worker found db/006's wall giving
   an admin `full` on every board, shipped templates too, against "its level is `view` for everyone" above): **`db/017_shipped_templates_view_only.sql`**
   adds one term to the `LEAST()` of `bd_board_level()` and `bd_member_board_levels()`; an admin's edit of a shipped template is refused and writes no op,
   and both functions agree (three checks in `templates.php`). Only a copy is ever edited.
