-- 002: activity memory -- the one funnel (memory.md §2). Exists before the first feature ships; it cannot be
-- backfilled. Every row is shipped to the tenant's one MaluDB as an `activity` episode by mcp/activity_ingest.py
-- (payload key "application": "boards" first). A RECORD'S HISTORY IS THIS TABLE for people (design §6.6): ONE ROW PER COMMITTED
-- BATCH of a board's items (item.create / item.update / item.move / item.delete, item.batch for a mixed batch, item.text for a
-- coalesced text edit), never per drag frame; cursors, presence and drag previews are never logged. The op log every live client
-- follows (board_ops, db/006+) is a second, replayable history of the same batches; what a person or an agent DID is here.
-- `before`/`after` carry ids, names, counts, kinds, levels and byte sizes -- A STICKY'S TEXT, A COMMENT'S BODY, A TOKEN OR A FILE
-- PATH IS NEVER HERE (a sticky can hold anything a person wrote); a key is named by its label.
--
-- THE ESTATE'S DATA MODEL (design §6.7): the contract table (memory.md / Consultant Tracking db/002) with token_id as Knowledge db/002
-- appended it (Sales CRM and the Data Lake carry it too), and Boards' audit key APPENDED: board_id uuid (every event about a board,
-- its items, comments, versions and permissions -- a board's TRAIL is one index). Boards' records are UUID-keyed, so entity_id (bigint)
-- stays NULL for a board or an item and the uuid rides in board_id (an item's id goes in `after`). No FK: history outlives a record,
-- and boards come in db/006+. `source` widens the contract's list with 'system' (the worker's versions, compaction and the trash act on
-- no one's click) -- an enumeration widened, the shared-schema rule's allowed change.
BEGIN;

CREATE TABLE activity_log (
    id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    occurred_at     timestamptz NOT NULL DEFAULT now(),
    actor_member_id bigint REFERENCES members(id) ON DELETE SET NULL,
    source          text NOT NULL DEFAULT 'web'
                    CHECK (source IN ('web', 'assistant', 'mcp', 'cron', 'agent', 'desk', 'webhook', 'portal', 'api', 'application', 'system')),
    action          text NOT NULL,                 -- entity.verb
    screen          text,
    route           text,                          -- "METHOD /path"
    entity_type     text,
    entity_id       bigint,
    before          jsonb,
    after           jsonb,                         -- changed fields only
    request_id      text,
    session_id      text,
    ip_address      inet,
    agent_run_id    bigint,                        -- the kernel's run id when an agent acted (no FK)
    department_id   bigint,                        -- the department the event concerns (a permission given to it)
    location_id     bigint,                        -- the contract's (unused: scope none)
    kernel_request_id text,                        -- the kernel's request id for a call that crossed to it
    created_at      timestamptz NOT NULL DEFAULT now(),
    token_id        bigint,                        -- appended (Knowledge db/002): the token (mcp_access_tokens.id) an event ran under; its LABEL is in `after`, never the token
    -- appended (design §6.6): Boards' audit key (no FK — history outlives a record; boards come in db/006+)
    board_id        uuid                           -- the board the event concerns (its own event, an item batch's, a comment's, a version's, a permission's)
);
CREATE INDEX activity_log_actor_idx    ON activity_log (actor_member_id, occurred_at DESC);
CREATE INDEX activity_log_entity_idx   ON activity_log (entity_type, entity_id, occurred_at DESC);
CREATE INDEX activity_log_action_idx   ON activity_log (action, occurred_at DESC);
CREATE INDEX activity_log_time_idx     ON activity_log (occurred_at DESC);
CREATE INDEX activity_log_request_idx  ON activity_log (request_id);
CREATE INDEX activity_log_token_idx    ON activity_log (token_id, occurred_at DESC) WHERE token_id IS NOT NULL;
CREATE INDEX activity_log_board_idx    ON activity_log (board_id, occurred_at DESC) WHERE board_id IS NOT NULL;

GRANT INSERT, SELECT ON activity_log TO boards_rw;
REVOKE ALL ON activity_log FROM boards_records_ro, boards_activity_ro;

-- The ingest checkpoint: only ever moves forward.
CREATE TABLE activity_ingest_state (
    id          smallint PRIMARY KEY DEFAULT 1 CHECK (id = 1),
    last_id     bigint NOT NULL DEFAULT 0,
    updated_at  timestamptz NOT NULL DEFAULT now()
);
INSERT INTO activity_ingest_state (id, last_id) VALUES (1, 0) ON CONFLICT DO NOTHING;
GRANT SELECT, UPDATE ON activity_ingest_state TO boards_rw;

COMMIT;
