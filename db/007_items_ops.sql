-- 007: ITEMS AND THE OP LOG — board_items (Miro's REST item as a row), board_ops (one row per committed batch, with its inverse),
-- the NOTIFY that feeds the relay, and bd_commit_ops(): THE ONE WRITE PATH for the canvas, the command bar and the kernel's agents
-- (docs/boards-design.md §6.2, §6.3, §8; decision record §3.3, §6.1; D4, D7, D8).
--
-- THE ESTATE'S DATA MODEL: board_items and board_ops are NEW CANONICAL (nothing in the estate has a position, a size and a z-order;
-- nothing is an ordered, replayable batch log with inverses — the estate's first change feed for live clients).
--
-- Rules the schema keeps (each proven by db/proof/phase0_proof.sql):
--   * x, y are the CENTRE, relative to the parent frame's top-left when parent_id is set (Miro's convention); frames do not nest
--     and do not rotate (D4). bbox is the ABSOLUTE, rotation-inclusive bounding box kept by trigger — a frame's move re-boxes its
--     children, an item's move re-boxes its connectors. bbox is compared with ~= (same as), NEVER = (box = compares AREAS).
--   * Per-property last-writer-wins: an update sets only the columns it names; style and data merge by top-level key (a JSON null
--     removes a key). Nothing is refused for being stale; a text replaced after the writer's base_version is reported.
--   * bd_commit_ops() takes the BOARD's row lock first, so within a board seq order IS commit order (the spike's 406-before-405
--     happens only across boards) and a client's catch-up `ops?after=<last seq>` is exact. Items are written before the board_ops
--     row, so the seq is drawn under the locks.
--   * A retried batch (the same client_batch_id) answers the first commit's seq and does nothing twice.
--   * Deleting a frame deletes its children unless keep_children (they move to the canvas where they stand); deleting an item
--     deletes its connectors. All of it is in the batch's ops and its inverse.
--   * The acting member must be the session's (app.member_id): a handler cannot commit as someone else.
BEGIN;

CREATE TABLE board_items (
    id             uuid PRIMARY KEY,                                                   -- minted by the client (optimistic creates)
    board_id       uuid NOT NULL REFERENCES boards(id) ON DELETE CASCADE,
    type           text NOT NULL CHECK (type IN ('sticky_note', 'shape', 'text', 'connector', 'frame', 'drawing', 'image', 'document')),
    parent_id      uuid REFERENCES board_items(id) ON DELETE SET NULL,                -- the frame it sits in
    x              double precision NOT NULL DEFAULT 0,                               -- the centre (Miro), frame-relative under a frame
    y              double precision NOT NULL DEFAULT 0,
    width          double precision NOT NULL DEFAULT 0 CHECK (width >= 0 AND width <= 100000),
    height         double precision NOT NULL DEFAULT 0 CHECK (height >= 0 AND height <= 100000),
    rotation       double precision NOT NULL DEFAULT 0 CHECK (rotation > -360 AND rotation < 360),
    z              text COLLATE "C" NOT NULL DEFAULT 'a0' CHECK (z ~ '^[0-9A-Za-z]{1,64}$'),   -- a fractional-index key: byte order, never the locale's
    style          jsonb NOT NULL DEFAULT '{}' CHECK (jsonb_typeof(style) = 'object'),
    data           jsonb NOT NULL DEFAULT '{}' CHECK (jsonb_typeof(data) = 'object'),
    start_item_id  uuid REFERENCES board_items(id) ON DELETE SET NULL,                -- a connector's ends
    end_item_id    uuid REFERENCES board_items(id) ON DELETE SET NULL,
    locked         boolean NOT NULL DEFAULT false,
    bbox           box NOT NULL DEFAULT box(point(0, 0), point(0, 0)),                -- absolute; kept by trigger
    plain_text     text GENERATED ALWAYS AS (bd_rich_text_plain(data->'content')) STORED,
    version        bigint NOT NULL DEFAULT 1,
    last_seq       bigint NOT NULL DEFAULT 0,
    text_version   bigint NOT NULL DEFAULT 0,                                         -- the version at which data.content last changed
    text_by        bigint REFERENCES members(id) ON DELETE SET NULL,                  -- and by whom (the "you replaced Maria's edit" notice)
    created_by     bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at     timestamptz NOT NULL DEFAULT now(),
    updated_by     bigint REFERENCES members(id) ON DELETE SET NULL,
    updated_at     timestamptz NOT NULL DEFAULT now(),
    deleted_at     timestamptz,
    deleted_by     bigint REFERENCES members(id) ON DELETE SET NULL,
    agent_run_id   bigint,                                                            -- the last agent run that touched it
    CHECK (type <> 'frame' OR (parent_id IS NULL AND rotation = 0)),
    CHECK (type = 'connector' OR (start_item_id IS NULL AND end_item_id IS NULL)),
    CHECK (NOT (data ? 'content') OR bd_is_rich_text(data->'content'))
);
CREATE INDEX board_items_board_live_idx ON board_items (board_id) WHERE deleted_at IS NULL;
CREATE INDEX board_items_bbox_idx       ON board_items USING gist (board_id, bbox) WHERE deleted_at IS NULL;
CREATE INDEX board_items_parent_idx     ON board_items (parent_id) WHERE parent_id IS NOT NULL;
CREATE INDEX board_items_start_idx      ON board_items (start_item_id) WHERE start_item_id IS NOT NULL;
CREATE INDEX board_items_end_idx        ON board_items (end_item_id) WHERE end_item_id IS NOT NULL;
CREATE INDEX board_items_deleted_idx    ON board_items (deleted_at) WHERE deleted_at IS NOT NULL;

-- A parent is a live frame on the same board; a connector's ends are live, non-connector items on the same board.
CREATE OR REPLACE FUNCTION bd_items_refs_before() RETURNS trigger
    LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.parent_id IS NOT NULL AND NOT EXISTS (
         SELECT 1 FROM board_items f WHERE f.id = NEW.parent_id AND f.board_id = NEW.board_id AND f.type = 'frame' AND f.deleted_at IS NULL) THEN
        RAISE EXCEPTION 'An item sits only in a frame on its own board.' USING ERRCODE = 'check_violation';
    END IF;
    IF (NEW.start_item_id IS NOT NULL AND NOT EXISTS (
          SELECT 1 FROM board_items e WHERE e.id = NEW.start_item_id AND e.board_id = NEW.board_id AND e.type <> 'connector' AND e.deleted_at IS NULL))
    OR (NEW.end_item_id IS NOT NULL AND NOT EXISTS (
          SELECT 1 FROM board_items e WHERE e.id = NEW.end_item_id AND e.board_id = NEW.board_id AND e.type <> 'connector' AND e.deleted_at IS NULL)) THEN
        RAISE EXCEPTION 'A connector joins items on its own board.' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER board_items_refs BEFORE INSERT OR UPDATE OF parent_id, start_item_id, end_item_id ON board_items
    FOR EACH ROW EXECUTE FUNCTION bd_items_refs_before();

-- The absolute bounding box. Non-connectors: the frame's top-left (frames are top-level and unrotated) + the centre ± the rotated
-- half extents. Connectors: the union of the ends' boxes and any free end point (data.startPoint / data.endPoint, absolute), else
-- their own x/y/width/height.
CREATE OR REPLACE FUNCTION bd_items_bbox_before() RETURNS trigger
    LANGUAGE plpgsql AS $$
DECLARE fx double precision := 0; fy double precision := 0; c double precision; s double precision; hx double precision; hy double precision;
        acc box; b box;
BEGIN
    IF NEW.type = 'connector' THEN
        SELECT i.bbox INTO b FROM board_items i WHERE i.id = NEW.start_item_id AND i.deleted_at IS NULL;
        acc := b;
        SELECT i.bbox INTO b FROM board_items i WHERE i.id = NEW.end_item_id AND i.deleted_at IS NULL;
        IF b IS NOT NULL THEN acc := CASE WHEN acc IS NULL THEN b ELSE bound_box(acc, b) END; END IF;
        IF jsonb_typeof(NEW.data->'startPoint') = 'object' THEN
            b := box(point((NEW.data->'startPoint'->>'x')::float8, (NEW.data->'startPoint'->>'y')::float8),
                     point((NEW.data->'startPoint'->>'x')::float8, (NEW.data->'startPoint'->>'y')::float8));
            acc := CASE WHEN acc IS NULL THEN b ELSE bound_box(acc, b) END;
        END IF;
        IF jsonb_typeof(NEW.data->'endPoint') = 'object' THEN
            b := box(point((NEW.data->'endPoint'->>'x')::float8, (NEW.data->'endPoint'->>'y')::float8),
                     point((NEW.data->'endPoint'->>'x')::float8, (NEW.data->'endPoint'->>'y')::float8));
            acc := CASE WHEN acc IS NULL THEN b ELSE bound_box(acc, b) END;
        END IF;
        NEW.bbox := COALESCE(acc, box(point(NEW.x - NEW.width / 2, NEW.y - NEW.height / 2), point(NEW.x + NEW.width / 2, NEW.y + NEW.height / 2)));
        RETURN NEW;
    END IF;
    IF NEW.parent_id IS NOT NULL THEN
        SELECT f.x - f.width / 2, f.y - f.height / 2 INTO fx, fy FROM board_items f WHERE f.id = NEW.parent_id;
        fx := COALESCE(fx, 0); fy := COALESCE(fy, 0);
    END IF;
    c := abs(cos(radians(NEW.rotation))); s := abs(sin(radians(NEW.rotation)));
    hx := (NEW.width * c + NEW.height * s) / 2;
    hy := (NEW.width * s + NEW.height * c) / 2;
    NEW.bbox := box(point(fx + NEW.x - hx, fy + NEW.y - hy), point(fx + NEW.x + hx, fy + NEW.y + hy));
    RETURN NEW;
END$$;
CREATE TRIGGER board_items_bbox BEFORE INSERT OR UPDATE OF x, y, width, height, rotation, parent_id, start_item_id, end_item_id, data, deleted_at
    ON board_items FOR EACH ROW EXECUTE FUNCTION bd_items_bbox_before();

-- A frame's box moved: its children re-box. Any item's box moved or it was deleted or restored: its connectors re-box.
-- (`SET x = x` names the column, so the BEFORE trigger runs; nothing else changes.)
CREATE OR REPLACE FUNCTION bd_items_cascade_after() RETURNS trigger
    LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.type = 'frame' AND NOT (OLD.bbox ~= NEW.bbox) THEN
        UPDATE board_items SET x = x WHERE parent_id = NEW.id AND deleted_at IS NULL;
    END IF;
    IF NEW.type <> 'connector' AND (NOT (OLD.bbox ~= NEW.bbox) OR OLD.deleted_at IS DISTINCT FROM NEW.deleted_at) THEN
        UPDATE board_items SET start_item_id = start_item_id
         WHERE type = 'connector' AND deleted_at IS NULL AND (start_item_id = NEW.id OR end_item_id = NEW.id);
    END IF;
    RETURN NULL;
END$$;
CREATE TRIGGER board_items_cascade AFTER UPDATE ON board_items FOR EACH ROW EXECUTE FUNCTION bd_items_cascade_after();

-- The live count on boards.item_count, and the hard cap (D7: bd_settings.item_hard_cap), per statement with transition tables.
CREATE OR REPLACE FUNCTION bd_items_count_ins() RETURNS trigger
    LANGUAGE plpgsql AS $$
DECLARE cap integer := bd_setting_int('item_hard_cap');
BEGIN
    UPDATE boards b SET item_count = b.item_count + n.c
      FROM (SELECT board_id, count(*) AS c FROM new_rows WHERE deleted_at IS NULL GROUP BY board_id) n WHERE b.id = n.board_id;
    IF EXISTS (SELECT 1 FROM boards b WHERE b.id IN (SELECT board_id FROM new_rows) AND b.item_count > cap) THEN
        RAISE EXCEPTION 'This board is full: a board holds at most % items.', cap USING ERRCODE = 'check_violation';
    END IF;
    RETURN NULL;
END$$;
CREATE OR REPLACE FUNCTION bd_items_count_upd() RETURNS trigger
    LANGUAGE plpgsql AS $$
DECLARE cap integer := bd_setting_int('item_hard_cap');
BEGIN
    UPDATE boards b SET item_count = b.item_count + d.delta
      FROM (SELECT n.board_id, sum((n.deleted_at IS NULL)::int - (o.deleted_at IS NULL)::int) AS delta
              FROM new_rows n JOIN old_rows o ON o.id = n.id
             WHERE (n.deleted_at IS NULL) <> (o.deleted_at IS NULL) GROUP BY n.board_id) d
     WHERE b.id = d.board_id AND d.delta <> 0;
    IF EXISTS (SELECT 1 FROM new_rows n JOIN old_rows o ON o.id = n.id JOIN boards b ON b.id = n.board_id
                WHERE n.deleted_at IS NULL AND o.deleted_at IS NOT NULL AND b.item_count > cap) THEN
        RAISE EXCEPTION 'This board is full: a board holds at most % items.', cap USING ERRCODE = 'check_violation';
    END IF;
    RETURN NULL;
END$$;
CREATE OR REPLACE FUNCTION bd_items_count_del() RETURNS trigger
    LANGUAGE plpgsql AS $$
BEGIN
    UPDATE boards b SET item_count = greatest(0, b.item_count - n.c)
      FROM (SELECT board_id, count(*) AS c FROM old_rows WHERE deleted_at IS NULL GROUP BY board_id) n WHERE b.id = n.board_id;
    RETURN NULL;
END$$;
CREATE TRIGGER board_items_count_ins AFTER INSERT ON board_items REFERENCING NEW TABLE AS new_rows FOR EACH STATEMENT EXECUTE FUNCTION bd_items_count_ins();
CREATE TRIGGER board_items_count_upd AFTER UPDATE ON board_items REFERENCING OLD TABLE AS old_rows NEW TABLE AS new_rows FOR EACH STATEMENT EXECUTE FUNCTION bd_items_count_upd();
CREATE TRIGGER board_items_count_del AFTER DELETE ON board_items REFERENCING OLD TABLE AS old_rows FOR EACH STATEMENT EXECUTE FUNCTION bd_items_count_del();

-- An item as the wire and the snapshots carry it.
CREATE OR REPLACE FUNCTION bd_item_json(i board_items) RETURNS jsonb
    LANGUAGE sql STABLE AS $$
    SELECT jsonb_build_object('id', i.id, 'type', i.type, 'parent_id', i.parent_id, 'x', i.x, 'y', i.y, 'width', i.width, 'height', i.height,
                              'rotation', i.rotation, 'z', i.z, 'style', i.style, 'data', i.data, 'start_item_id', i.start_item_id,
                              'end_item_id', i.end_item_id, 'locked', i.locked, 'version', i.version, 'created_by', i.created_by,
                              'updated_by', i.updated_by);
$$;

-- Merge by top-level key; a JSON null in the patch removes the key (nested nulls — a run's "link": null — are kept).
CREATE OR REPLACE FUNCTION bd_jsonb_merge(p_base jsonb, p_patch jsonb) RETURNS jsonb
    LANGUAGE sql IMMUTABLE AS $$
    SELECT COALESCE((SELECT jsonb_object_agg(e.key, e.value) FROM jsonb_each(COALESCE(p_base, '{}') || COALESCE(p_patch, '{}')) e
                      WHERE e.value <> 'null'::jsonb), '{}'::jsonb);
$$;

-- ---------------------------------------------------------------------------------------------
-- The op log
-- ---------------------------------------------------------------------------------------------
CREATE TABLE board_ops (
    seq              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    board_id         uuid NOT NULL REFERENCES boards(id) ON DELETE CASCADE,
    member_id        bigint REFERENCES members(id) ON DELETE SET NULL,
    source           text NOT NULL CHECK (source IN ('web', 'agent', 'command_bar', 'mcp', 'system', 'restore')),
    agent_run_id     bigint,
    request_id       text,
    client_id        text CHECK (client_id IS NULL OR client_id ~ '^[A-Za-z0-9_-]{1,64}$'),     -- the tab: a client ignores its own echo
    client_batch_id  uuid,                                                                      -- idempotency
    ops              jsonb NOT NULL CHECK (jsonb_typeof(ops) = 'array'),                        -- as applied
    inverse          jsonb NOT NULL CHECK (jsonb_typeof(inverse) = 'array'),                    -- what undoes it, in order
    summary          jsonb NOT NULL DEFAULT '{}' CHECK (jsonb_typeof(summary) = 'object'),      -- counts by verb and type; the batch's box
    undoes_seq       bigint[],                                                                  -- an undo names what it undid
    at               timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX board_ops_batch_idx ON board_ops (board_id, client_batch_id) WHERE client_batch_id IS NOT NULL;
CREATE INDEX board_ops_board_seq_idx ON board_ops (board_id, seq);
CREATE INDEX board_ops_at_idx ON board_ops (at);
CREATE INDEX board_ops_member_idx ON board_ops (member_id, at DESC);

-- NOTIFY at commit, from a trigger, so every write path reaches the room. The payload carries the ops when it fits (NOTIFY's
-- limit is 8,000 bytes; 7,500 leaves room); otherwise {seq} alone and the relay reads the batch with bd_live_batch().
CREATE OR REPLACE FUNCTION bd_ops_payload(o board_ops) RETURNS jsonb
    LANGUAGE sql STABLE AS $$
    SELECT CASE WHEN octet_length(o.ops::text) + octet_length(x.p::text) < 7500 THEN x.p || jsonb_build_object('ops', o.ops) ELSE x.p END
      FROM (SELECT jsonb_build_object('room', 'board:' || o.board_id, 'seq', o.seq, 'member_id', o.member_id, 'source', o.source,
                                      'client_id', o.client_id, 'summary', o.summary) AS p) x;
$$;
CREATE OR REPLACE FUNCTION bd_ops_notify() RETURNS trigger
    LANGUAGE plpgsql AS $$
BEGIN
    PERFORM pg_notify('boards_ops', bd_ops_payload(NEW)::text);
    RETURN NULL;
END$$;
CREATE TRIGGER board_ops_notify AFTER INSERT ON board_ops FOR EACH ROW EXECUTE FUNCTION bd_ops_notify();

-- The relay's read of a batch too large for NOTIFY (it fans out only to sockets already admitted to that board's room).
CREATE OR REPLACE FUNCTION bd_live_batch(p_seq bigint) RETURNS jsonb
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT o.ops FROM board_ops o WHERE o.seq = p_seq;
$$;

-- ---------------------------------------------------------------------------------------------
-- THE ONE WRITE PATH. Ops: {op:create, item:{id, type, …}} · {op:update, id, fields:{…}, base_version} ·
-- {op:delete, id | ids:[…], keep_children, cascade} · {op:restore, ids:[…]}. Answers {seq, duplicate, versions, replaced,
-- item_count, warnings}. Refusals are SQLSTATEs the kit's write guard reads: 42501 (level), P0002 (no such board or item),
-- 23514 (a rule), 23505 (an id taken), 22023 (a malformed batch).
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION bd_commit_ops(p_board_id uuid, p_member_id bigint, p_source text, p_client_id text, p_client_batch_id uuid,
                                         p_ops jsonb, p_agent_run_id bigint DEFAULT NULL, p_request_id text DEFAULT NULL,
                                         p_undoes bigint[] DEFAULT NULL)
    RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE
    v_board      boards%ROWTYPE;
    v_rank       smallint;
    v_seq        bigint;
    v_op         jsonb;
    v_kind       text;
    v_id         uuid;
    v_ids        uuid[];
    v_all        uuid[];
    v_item       board_items%ROWTYPE;
    v_frame      board_items%ROWTYPE;
    v_fields     jsonb;
    v_old        jsonb;
    v_local      jsonb;
    v_inverse    jsonb := '[]';
    v_applied    jsonb := '[]';
    v_touched    uuid[] := '{}';
    v_replaced   jsonb := '[]';
    v_allowed    text[] := ARRAY['x', 'y', 'width', 'height', 'rotation', 'z', 'parent_id', 'style', 'data', 'start_item_id', 'end_item_id', 'locked'];
    v_box        box;
    v_summary    jsonb;
    v_max_points integer := bd_setting_int('drawing_max_points');
    v_k          text;
BEGIN
    IF p_member_id IS NULL OR p_member_id IS DISTINCT FROM app_current_member_id() THEN
        RAISE EXCEPTION 'The acting member is not the signed-in one.' USING ERRCODE = 'insufficient_privilege';
    END IF;
    IF jsonb_typeof(p_ops) IS DISTINCT FROM 'array' OR jsonb_array_length(p_ops) = 0 THEN
        RAISE EXCEPTION 'A batch holds at least one op.' USING ERRCODE = 'invalid_parameter_value';
    END IF;
    IF jsonb_array_length(p_ops) > bd_setting_int('batch_max_ops') AND p_source <> 'restore' THEN    -- a restore is one batch, however large
        RAISE EXCEPTION 'A batch holds at most % ops.', bd_setting_int('batch_max_ops') USING ERRCODE = 'invalid_parameter_value';
    END IF;

    -- The board's row lock first: within a board, seq order is commit order.
    SELECT * INTO v_board FROM boards WHERE id = p_board_id FOR NO KEY UPDATE;
    IF NOT FOUND OR v_board.deleted_at IS NOT NULL THEN
        RAISE EXCEPTION 'There is no such board.' USING ERRCODE = 'no_data_found';
    END IF;
    IF p_client_batch_id IS NOT NULL THEN
        SELECT o.seq INTO v_seq FROM board_ops o WHERE o.board_id = p_board_id AND o.client_batch_id = p_client_batch_id;
        IF FOUND THEN
            RETURN jsonb_build_object('seq', v_seq, 'duplicate', true);
        END IF;
    END IF;
    v_rank := bd_level_rank(bd_board_level(p_board_id, p_member_id));
    IF v_rank = 0 THEN
        RAISE EXCEPTION 'There is no such board.' USING ERRCODE = 'no_data_found';
    ELSIF v_rank < 3 THEN
        RAISE EXCEPTION 'You can see this board but not change it.' USING ERRCODE = 'insufficient_privilege';
    ELSIF v_board.is_locked AND v_rank < 4 THEN
        RAISE EXCEPTION 'This board is locked; only its owners can change it.' USING ERRCODE = 'insufficient_privilege';
    END IF;

    FOR v_op IN SELECT e FROM jsonb_array_elements(p_ops) e LOOP
        v_kind := v_op->>'op';
        v_local := '[]';

        IF v_kind = 'create' THEN
            v_id := NULLIF(v_op->'item'->>'id', '')::uuid;
            IF v_id IS NULL THEN
                RAISE EXCEPTION 'A new item names its id.' USING ERRCODE = 'invalid_parameter_value';
            END IF;
            IF EXISTS (SELECT 1 FROM board_items WHERE id = v_id) THEN
                RAISE EXCEPTION 'Item % exists already.', v_id USING ERRCODE = 'unique_violation';
            END IF;
            INSERT INTO board_items (id, board_id, type, parent_id, x, y, width, height, rotation, z, style, data, start_item_id, end_item_id,
                                     locked, text_version, text_by, created_by, updated_by, agent_run_id)
            VALUES (v_id, p_board_id, v_op->'item'->>'type', NULLIF(v_op->'item'->>'parent_id', '')::uuid,
                    COALESCE((v_op->'item'->>'x')::float8, 0), COALESCE((v_op->'item'->>'y')::float8, 0),
                    COALESCE((v_op->'item'->>'width')::float8, 0), COALESCE((v_op->'item'->>'height')::float8, 0),
                    COALESCE((v_op->'item'->>'rotation')::float8, 0), COALESCE(v_op->'item'->>'z', 'a0'),
                    COALESCE(v_op->'item'->'style', '{}'), COALESCE(v_op->'item'->'data', '{}'),
                    NULLIF(v_op->'item'->>'start_item_id', '')::uuid, NULLIF(v_op->'item'->>'end_item_id', '')::uuid,
                    COALESCE((v_op->'item'->>'locked')::boolean, false),
                    CASE WHEN v_op->'item'->'data' ? 'content' THEN 1 ELSE 0 END,
                    CASE WHEN v_op->'item'->'data' ? 'content' THEN p_member_id END,
                    p_member_id, p_member_id, p_agent_run_id)
            RETURNING * INTO v_item;
            v_applied := v_applied || jsonb_build_array(jsonb_build_object('op', 'create', 'item', bd_item_json(v_item)));
            v_local := jsonb_build_array(jsonb_build_object('op', 'delete', 'ids', jsonb_build_array(v_id), 'cascade', false));
            v_touched := v_touched || v_id;

        ELSIF v_kind = 'update' THEN
            v_id := (v_op->>'id')::uuid;
            v_fields := v_op->'fields';
            IF jsonb_typeof(v_fields) IS DISTINCT FROM 'object' OR v_fields = '{}' THEN
                RAISE EXCEPTION 'An update names the fields it changes.' USING ERRCODE = 'invalid_parameter_value';
            END IF;
            SELECT k INTO v_k FROM jsonb_object_keys(v_fields) k WHERE k <> ALL (v_allowed) LIMIT 1;
            IF FOUND THEN
                RAISE EXCEPTION 'An item has no field %.', v_k USING ERRCODE = 'invalid_parameter_value';
            END IF;
            SELECT * INTO v_item FROM board_items WHERE id = v_id AND board_id = p_board_id AND deleted_at IS NULL FOR UPDATE;
            IF NOT FOUND THEN
                RAISE EXCEPTION 'Item % is not on this board.', v_id USING ERRCODE = 'no_data_found';
            END IF;
            IF v_item.locked AND COALESCE((v_fields->>'locked')::boolean, true) AND (v_fields - 'locked') <> '{}' THEN   -- unlocking may change the rest
                RAISE EXCEPTION 'Item % is locked.', v_id USING ERRCODE = 'check_violation';
            END IF;
            -- The old values of exactly what changes: whole columns, or the named keys of style and data.
            SELECT jsonb_object_agg(k, CASE
                     WHEN k = 'style' THEN (SELECT COALESCE(jsonb_object_agg(sk, COALESCE(v_item.style->sk, 'null'::jsonb)), '{}') FROM jsonb_object_keys(v_fields->'style') sk)
                     WHEN k = 'data'  THEN (SELECT COALESCE(jsonb_object_agg(dk, COALESCE(v_item.data->dk, 'null'::jsonb)), '{}') FROM jsonb_object_keys(v_fields->'data') dk)
                     ELSE to_jsonb(v_item) -> k END)
              INTO v_old FROM jsonb_object_keys(v_fields) k;
            IF v_fields->'data' ? 'content' AND v_op ? 'base_version' AND v_item.text_by IS DISTINCT FROM p_member_id
               AND v_item.text_version > (v_op->>'base_version')::bigint THEN
                v_replaced := v_replaced || jsonb_build_array(jsonb_build_object('id', v_id, 'by', v_item.text_by));
            END IF;
            UPDATE board_items SET
                   x             = CASE WHEN v_fields ? 'x' THEN (v_fields->>'x')::float8 ELSE x END,
                   y             = CASE WHEN v_fields ? 'y' THEN (v_fields->>'y')::float8 ELSE y END,
                   width         = CASE WHEN v_fields ? 'width' THEN (v_fields->>'width')::float8 ELSE width END,
                   height        = CASE WHEN v_fields ? 'height' THEN (v_fields->>'height')::float8 ELSE height END,
                   rotation      = CASE WHEN v_fields ? 'rotation' THEN (v_fields->>'rotation')::float8 ELSE rotation END,
                   z             = CASE WHEN v_fields ? 'z' THEN v_fields->>'z' ELSE z END,
                   parent_id     = CASE WHEN v_fields ? 'parent_id' THEN NULLIF(v_fields->>'parent_id', '')::uuid ELSE parent_id END,
                   start_item_id = CASE WHEN v_fields ? 'start_item_id' THEN NULLIF(v_fields->>'start_item_id', '')::uuid ELSE start_item_id END,
                   end_item_id   = CASE WHEN v_fields ? 'end_item_id' THEN NULLIF(v_fields->>'end_item_id', '')::uuid ELSE end_item_id END,
                   locked        = CASE WHEN v_fields ? 'locked' THEN (v_fields->>'locked')::boolean ELSE locked END,
                   style         = CASE WHEN v_fields ? 'style' THEN bd_jsonb_merge(style, v_fields->'style') ELSE style END,
                   data          = CASE WHEN v_fields ? 'data' THEN bd_jsonb_merge(data, v_fields->'data') ELSE data END,
                   text_version  = CASE WHEN v_fields->'data' ? 'content' THEN version + 1 ELSE text_version END,
                   text_by       = CASE WHEN v_fields->'data' ? 'content' THEN p_member_id ELSE text_by END,
                   version       = version + 1,
                   updated_by    = p_member_id,
                   updated_at    = now(),
                   agent_run_id  = COALESCE(p_agent_run_id, agent_run_id)
             WHERE id = v_id
            RETURNING * INTO v_item;
            v_applied := v_applied || jsonb_build_array(jsonb_build_object('op', 'update', 'id', v_id, 'fields', v_fields, 'version', v_item.version));
            v_local := jsonb_build_array(jsonb_build_object('op', 'update', 'id', v_id, 'fields', v_old));
            v_touched := v_touched || v_id;

        ELSIF v_kind = 'delete' THEN
            v_ids := CASE WHEN v_op ? 'ids' THEN ARRAY(SELECT (e)::uuid FROM jsonb_array_elements_text(v_op->'ids') e)
                          ELSE ARRAY[(v_op->>'id')::uuid] END;
            IF EXISTS (SELECT 1 FROM unnest(v_ids) u(id) WHERE NOT EXISTS (SELECT 1 FROM board_items i WHERE i.id = u.id AND i.board_id = p_board_id)) THEN
                RAISE EXCEPTION 'An item named is not on this board.' USING ERRCODE = 'no_data_found';
            END IF;
            IF EXISTS (SELECT 1 FROM board_items WHERE id = ANY (v_ids) AND deleted_at IS NULL AND locked) THEN
                RAISE EXCEPTION 'A locked item is not deleted; unlock it first.' USING ERRCODE = 'check_violation';
            END IF;
            v_ids := ARRAY(SELECT id FROM board_items WHERE id = ANY (v_ids) AND deleted_at IS NULL);   -- already gone: nothing to do
            IF COALESCE((v_op->>'keep_children')::boolean, false) THEN
                -- The children stay where they stand, on the canvas.
                FOR v_frame IN SELECT * FROM board_items WHERE id = ANY (v_ids) AND type = 'frame' LOOP
                    FOR v_item IN SELECT * FROM board_items WHERE parent_id = v_frame.id AND deleted_at IS NULL LOOP
                        UPDATE board_items SET parent_id = NULL, x = v_item.x + v_frame.x - v_frame.width / 2,
                               y = v_item.y + v_frame.y - v_frame.height / 2, version = version + 1, updated_by = p_member_id, updated_at = now()
                         WHERE id = v_item.id;
                        v_local := v_local || jsonb_build_array(jsonb_build_object('op', 'update', 'id', v_item.id,
                                   'fields', jsonb_build_object('parent_id', v_frame.id, 'x', v_item.x, 'y', v_item.y)));
                        v_touched := v_touched || v_item.id;
                    END LOOP;
                END LOOP;
            END IF;
            IF COALESCE((v_op->>'cascade')::boolean, true) THEN
                v_all := v_ids || ARRAY(SELECT id FROM board_items WHERE parent_id = ANY (v_ids) AND deleted_at IS NULL);
                v_all := v_all || ARRAY(SELECT id FROM board_items WHERE type = 'connector' AND deleted_at IS NULL
                                          AND (start_item_id = ANY (v_all) OR end_item_id = ANY (v_all)));
            ELSE
                v_all := v_ids;
            END IF;
            v_all := ARRAY(SELECT DISTINCT u FROM unnest(v_all) u);
            -- Connectors first, then the items, then frames: each soft delete re-boxes what depends on it.
            UPDATE board_items SET deleted_at = now(), deleted_by = p_member_id, version = version + 1, updated_by = p_member_id, updated_at = now(),
                                   agent_run_id = COALESCE(p_agent_run_id, agent_run_id)
             WHERE id = ANY (v_all) AND deleted_at IS NULL;
            IF cardinality(v_all) > 0 THEN
                v_applied := v_applied || jsonb_build_array(jsonb_build_object('op', 'delete', 'ids', to_jsonb(v_all)));
                -- Undo: restore first, then put the kept children back in their frame.
                v_local := jsonb_build_array(jsonb_build_object('op', 'restore', 'ids', to_jsonb(v_all))) || v_local;
            END IF;
            v_touched := v_touched || v_all;

        ELSIF v_kind = 'restore' THEN
            v_ids := ARRAY(SELECT (e)::uuid FROM jsonb_array_elements_text(v_op->'ids') e);
            -- A restored item's deleted frame comes back with it.
            v_ids := v_ids || ARRAY(SELECT i.parent_id FROM board_items i JOIN board_items f ON f.id = i.parent_id
                                     WHERE i.id = ANY (v_ids) AND f.deleted_at IS NOT NULL);
            v_all := ARRAY(SELECT id FROM board_items WHERE id = ANY (v_ids) AND board_id = p_board_id AND deleted_at IS NOT NULL);
            -- Frames, then items, then connectors (each needs what it stands on to be live).
            UPDATE board_items SET deleted_at = NULL, deleted_by = NULL, version = version + 1, updated_by = p_member_id, updated_at = now()
             WHERE id = ANY (v_all) AND type = 'frame';
            UPDATE board_items SET deleted_at = NULL, deleted_by = NULL, version = version + 1, updated_by = p_member_id, updated_at = now()
             WHERE id = ANY (v_all) AND type NOT IN ('frame', 'connector');
            UPDATE board_items SET deleted_at = NULL, deleted_by = NULL, version = version + 1, updated_by = p_member_id, updated_at = now(),
                                   start_item_id = CASE WHEN start_item_id IN (SELECT id FROM board_items WHERE deleted_at IS NULL) THEN start_item_id END,
                                   end_item_id = CASE WHEN end_item_id IN (SELECT id FROM board_items WHERE deleted_at IS NULL) THEN end_item_id END
             WHERE id = ANY (v_all) AND type = 'connector';
            IF cardinality(v_all) > 0 THEN
                v_applied := v_applied || jsonb_build_array(jsonb_build_object('op', 'restore', 'ids', to_jsonb(v_all),
                             'items', (SELECT jsonb_agg(bd_item_json(i)) FROM board_items i WHERE i.id = ANY (v_all))));
                v_local := jsonb_build_array(jsonb_build_object('op', 'delete', 'ids', to_jsonb(v_all), 'cascade', false));
            END IF;
            v_touched := v_touched || v_all;

        ELSE
            RAISE EXCEPTION 'There is no op called %.', COALESCE(v_kind, '(none)') USING ERRCODE = 'invalid_parameter_value';
        END IF;

        v_inverse := v_local || v_inverse;      -- the inverse runs the last op's undo first
    END LOOP;

    IF EXISTS (SELECT 1 FROM board_items WHERE id = ANY (v_touched) AND type = 'drawing' AND deleted_at IS NULL
                 AND jsonb_typeof(data->'points') = 'array' AND jsonb_array_length(data->'points') > 2 * v_max_points) THEN
        RAISE EXCEPTION 'A drawing holds at most % points.', v_max_points USING ERRCODE = 'check_violation';
    END IF;
    IF jsonb_array_length(v_applied) = 0 THEN
        RETURN jsonb_build_object('seq', v_board.last_seq, 'duplicate', false, 'noop', true);
    END IF;

    SELECT box(point(min((i.bbox[1])[0]), min((i.bbox[1])[1])), point(max((i.bbox[0])[0]), max((i.bbox[0])[1])))
      INTO v_box FROM board_items i WHERE i.id = ANY (v_touched);
    SELECT jsonb_build_object(
             'counts', (SELECT jsonb_object_agg(c.verb, c.n) FROM (SELECT a->>'op' AS verb, count(*) AS n FROM jsonb_array_elements(v_applied) a GROUP BY 1) c),
             'types', (SELECT jsonb_object_agg(t.type, t.n) FROM (SELECT i.type, count(*) AS n FROM board_items i WHERE i.id = ANY (v_touched) GROUP BY 1) t),
             'items', cardinality(ARRAY(SELECT DISTINCT u FROM unnest(v_touched) u)),
             'bbox', CASE WHEN v_box IS NULL THEN NULL ELSE jsonb_build_array((v_box[1])[0], (v_box[1])[1], (v_box[0])[0], (v_box[0])[1]) END)
      INTO v_summary;

    INSERT INTO board_ops (board_id, member_id, source, agent_run_id, request_id, client_id, client_batch_id, ops, inverse, summary, undoes_seq)
    VALUES (p_board_id, p_member_id, p_source, p_agent_run_id, p_request_id, p_client_id, p_client_batch_id, v_applied, v_inverse, v_summary, p_undoes)
    RETURNING seq INTO v_seq;
    UPDATE board_items SET last_seq = v_seq WHERE id = ANY (v_touched);
    UPDATE boards SET last_seq = v_seq, last_activity_at = now() WHERE id = p_board_id
    RETURNING * INTO v_board;

    RETURN jsonb_build_object(
        'seq', v_seq, 'duplicate', false,
        'versions', (SELECT COALESCE(jsonb_object_agg(i.id, i.version), '{}') FROM board_items i WHERE i.id = ANY (v_touched)),
        'replaced', v_replaced,
        'item_count', v_board.item_count,
        'warnings', CASE WHEN v_board.item_count > bd_setting_int('item_soft_budget')
                         THEN jsonb_build_array(format('This board holds %s items; boards stay quick under %s.', v_board.item_count, bd_setting_int('item_soft_budget')))
                         ELSE '[]'::jsonb END);
END$$;

-- An agent (or a person) takes back their OWN batches from the last hour (D8): the inverses, newest first, as one new batch.
CREATE OR REPLACE FUNCTION bd_undo_ops(p_board_id uuid, p_member_id bigint, p_seqs bigint[], p_source text,
                                       p_agent_run_id bigint DEFAULT NULL, p_request_id text DEFAULT NULL)
    RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE v_inverse jsonb; n integer;
BEGIN
    SELECT count(*), jsonb_agg(x ORDER BY o.seq DESC, ord)
      INTO n, v_inverse
      FROM board_ops o CROSS JOIN LATERAL jsonb_array_elements(o.inverse) WITH ORDINALITY e(x, ord)
     WHERE o.board_id = p_board_id AND o.seq = ANY (p_seqs) AND o.member_id = p_member_id AND o.at > now() - interval '1 hour';
    IF (SELECT count(*) FROM board_ops o WHERE o.board_id = p_board_id AND o.seq = ANY (p_seqs) AND o.member_id = p_member_id
                                          AND o.at > now() - interval '1 hour') <> cardinality(p_seqs) THEN
        RAISE EXCEPTION 'Only your own changes from the last hour can be undone this way.' USING ERRCODE = 'insufficient_privilege';
    END IF;
    RETURN bd_commit_ops(p_board_id, p_member_id, p_source, NULL, NULL, v_inverse, p_agent_run_id, p_request_id, p_seqs);
END$$;

-- The region read the records MCP's board_read_region and board_search use (design §7): live items whose box meets the box,
-- through the GiST index, for a caller who may see the board. Frames come first, then by z.
CREATE OR REPLACE FUNCTION bd_region_items(p_board_id uuid, p_x1 float8, p_y1 float8, p_x2 float8, p_y2 float8, p_limit integer DEFAULT 400)
    RETURNS SETOF board_items LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF bd_level_rank(bd_my_level(p_board_id)) = 0 THEN
        RETURN;
    END IF;
    RETURN QUERY
        SELECT i.* FROM board_items i
         WHERE i.board_id = p_board_id AND i.deleted_at IS NULL AND i.bbox && box(point(p_x1, p_y1), point(p_x2, p_y2))
         ORDER BY (i.type = 'frame') DESC, i.z, i.id
         LIMIT greatest(1, least(p_limit, 2000));
END$$;

REVOKE ALL ON FUNCTION bd_commit_ops(uuid, bigint, text, text, uuid, jsonb, bigint, text, bigint[]),
                       bd_undo_ops(uuid, bigint, bigint[], text, bigint, text), bd_live_batch(bigint),
                       bd_region_items(uuid, float8, float8, float8, float8, integer) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION bd_commit_ops(uuid, bigint, text, text, uuid, jsonb, bigint, text, bigint[]),
                          bd_undo_ops(uuid, bigint, bigint[], text, bigint, text) TO boards_rw;
GRANT EXECUTE ON FUNCTION bd_live_batch(bigint) TO boards_records_ro;
GRANT EXECUTE ON FUNCTION bd_region_items(uuid, float8, float8, float8, float8, integer) TO boards_rw, boards_records_ro;
GRANT EXECUTE ON FUNCTION bd_item_json(board_items), bd_jsonb_merge(jsonb, jsonb) TO boards_rw, boards_records_ro;

GRANT SELECT, INSERT, UPDATE, DELETE ON board_items TO boards_rw;
GRANT SELECT, INSERT, DELETE ON board_ops TO boards_rw;

COMMIT;
