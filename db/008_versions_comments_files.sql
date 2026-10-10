-- 008: VERSIONS, COMMENTS, MENTIONS, ATTACHMENTS (docs/boards-design.md §6.3, §6.4; D6, D9).
--
-- THE ESTATE'S DATA MODEL:
--   board_versions    Spaces db/007 page_versions (page_id → board_id); reason widened with 'compaction'; appended label, item_count
--   comments          Spaces db/011 — the family (the record key follows the application: board_id for page_id, item_id for
--                     block_id); appended anchor_x, anchor_y (a canvas point, or an offset on the item), context_item_ids (the
--                     selection when it was written — what "these" means to an agent)
--   comment_mentions  Spaces db/011 verbatim
--   attachments       Spaces db/012 (the canonical + record_uuid, width, height, thumbnail_path; the agreed UUID loosening);
--                     record_type widened for Boards; appended thumbnails (the three level-of-detail sizes, D9)
BEGIN;

CREATE TABLE board_versions (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    board_id      uuid NOT NULL REFERENCES boards(id) ON DELETE CASCADE,
    version_no    integer NOT NULL,
    content_rev   bigint NOT NULL,                                                       -- the board's last_seq at the snapshot
    reason        text NOT NULL CHECK (reason IN ('interval', 'manual', 'before_restore', 'lock', 'import', 'restore', 'compaction')),
    snapshot      jsonb NOT NULL,                                                        -- {title, background, start_view, items:[...]}
    plain_text    text,
    saved_by      bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at    timestamptz NOT NULL DEFAULT now(),
    label         text CHECK (label IS NULL OR length(btrim(label)) BETWEEN 1 AND 120), -- appended: a named version's name
    item_count    integer,                                                               -- appended
    UNIQUE (board_id, version_no)
);
CREATE INDEX board_versions_board_idx ON board_versions (board_id, created_at DESC);

CREATE TABLE comments (
    id                 uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    board_id           uuid NOT NULL REFERENCES boards(id) ON DELETE CASCADE,
    item_id            uuid REFERENCES board_items(id) ON DELETE CASCADE,               -- on an item; NULL = a point on the canvas
    parent_comment_id  uuid REFERENCES comments(id) ON DELETE CASCADE,                  -- a reply in a thread
    author_member_id   bigint REFERENCES members(id) ON DELETE SET NULL,
    body               jsonb NOT NULL CHECK (bd_is_rich_text(body)),
    plain_text         text GENERATED ALWAYS AS (bd_rich_text_plain(body)) STORED,
    resolved_at        timestamptz,
    resolved_by        bigint REFERENCES members(id) ON DELETE SET NULL,
    edited_at          timestamptz,
    deleted_at         timestamptz,
    deleted_by         bigint REFERENCES members(id) ON DELETE SET NULL,
    agent_run_id       bigint,
    created_at         timestamptz NOT NULL DEFAULT now(),
    anchor_x           double precision,                                                 -- appended (design §6.4)
    anchor_y           double precision,
    context_item_ids   uuid[] NOT NULL DEFAULT '{}',
    CHECK (parent_comment_id IS NOT NULL OR item_id IS NOT NULL OR (anchor_x IS NOT NULL AND anchor_y IS NOT NULL)),
    CHECK (jsonb_array_length(body) > 0)
);
CREATE INDEX comments_board_idx  ON comments (board_id, created_at);
CREATE INDEX comments_item_idx   ON comments (item_id) WHERE item_id IS NOT NULL;
CREATE INDEX comments_parent_idx ON comments (parent_comment_id) WHERE parent_comment_id IS NOT NULL;
CREATE INDEX comments_author_idx ON comments (author_member_id, created_at DESC);
CREATE INDEX comments_open_idx   ON comments (board_id) WHERE resolved_at IS NULL AND parent_comment_id IS NULL AND deleted_at IS NULL;

CREATE TABLE comment_mentions (
    comment_id   uuid NOT NULL REFERENCES comments(id) ON DELETE CASCADE,
    kind         text NOT NULL CHECK (kind IN ('member', 'agent', 'department')),
    principal_id bigint NOT NULL,
    PRIMARY KEY (comment_id, kind, principal_id)
);
CREATE INDEX comment_mentions_principal_idx ON comment_mentions (kind, principal_id);

-- A reply sits on its root's board; a thread is one level deep (a reply to a reply hangs on the root).
CREATE OR REPLACE FUNCTION bd_comments_before() RETURNS trigger
    LANGUAGE plpgsql AS $$
DECLARE r comments%ROWTYPE;
BEGIN
    IF NEW.parent_comment_id IS NOT NULL THEN
        SELECT * INTO r FROM comments WHERE id = NEW.parent_comment_id;
        IF r.board_id IS DISTINCT FROM NEW.board_id THEN
            RAISE EXCEPTION 'A reply belongs to its thread''s board.' USING ERRCODE = 'check_violation';
        END IF;
        IF r.parent_comment_id IS NOT NULL THEN
            NEW.parent_comment_id := r.parent_comment_id;
        END IF;
    END IF;
    IF NEW.item_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM board_items i WHERE i.id = NEW.item_id AND i.board_id = NEW.board_id) THEN
        RAISE EXCEPTION 'A comment pins to an item on its own board.' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER comments_before BEFORE INSERT OR UPDATE OF board_id, item_id, parent_comment_id ON comments FOR EACH ROW EXECUTE FUNCTION bd_comments_before();

CREATE TABLE attachments (
    id             bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    record_type    text NOT NULL CHECK (record_type IN ('item', 'comment', 'board_thumbnail', 'import')),
    record_id      bigint,                                                                -- (an import)
    filename       text NOT NULL,
    mime_type      text NOT NULL,
    byte_size      bigint NOT NULL CHECK (byte_size >= 0),
    sha256         text NOT NULL,
    storage_path   text NOT NULL,
    uploaded_by    bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at     timestamptz NOT NULL DEFAULT now(),
    record_uuid    uuid,                                                                  -- an item, a comment, a board
    width          integer CHECK (width IS NULL OR width > 0),
    height         integer CHECK (height IS NULL OR height > 0),
    thumbnail_path text,
    thumbnails     jsonb NOT NULL DEFAULT '{}' CHECK (jsonb_typeof(thumbnails) = 'object'), -- appended: {"256": path, "1024": path, "2048": path}
    board_id       uuid REFERENCES boards(id) ON DELETE CASCADE,                          -- appended: the wall's key
    CHECK ((record_id IS NULL) <> (record_uuid IS NULL))                                  -- the agreed UUID loosening (Spaces)
);
CREATE INDEX attachments_record_idx      ON attachments (record_type, record_id);
CREATE INDEX attachments_record_uuid_idx ON attachments (record_type, record_uuid);
CREATE INDEX attachments_uploaded_by_idx ON attachments (uploaded_by);
CREATE INDEX attachments_sha_idx         ON attachments (sha256);
CREATE INDEX attachments_board_idx       ON attachments (board_id);
ALTER TABLE boards ADD CONSTRAINT boards_thumbnail_fk FOREIGN KEY (thumbnail_attachment_id) REFERENCES attachments(id) ON DELETE SET NULL;

GRANT SELECT, INSERT, UPDATE, DELETE ON board_versions, comments, comment_mentions, attachments TO boards_rw;

COMMIT;
