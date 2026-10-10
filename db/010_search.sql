-- 010: SEARCH — Spaces db/013's search_index (the canonical, 2026-10-05), kept by triggers on boards, items and comments
-- (docs/boards-design.md §6.5, §7 Q18). entity_kind widened to board/item/comment; appended board_id and item_type. Spaces' page_id,
-- space_id and channel_id columns are kept as the canonical has them, unused here. The wall is the reader's: a row is shown only
-- for a board the caller may see (db/012's mcp_search and the screens join bd_my_board_levels()).
BEGIN;

CREATE TABLE search_index (
    entity_kind      text NOT NULL CHECK (entity_kind IN ('board', 'item', 'comment')),
    entity_uuid      uuid,
    entity_id        bigint,
    page_id          uuid,                                                    -- (Spaces'; unused)
    space_id         bigint,                                                  -- (Spaces'; unused)
    channel_id       bigint,                                                  -- (Spaces'; unused)
    author_member_id bigint,
    title            text,
    body             text,
    tsv              tsvector,
    has_link         boolean NOT NULL DEFAULT false,
    has_file         boolean NOT NULL DEFAULT false,
    occurred_at      timestamptz NOT NULL,
    updated_at       timestamptz NOT NULL DEFAULT now(),
    board_id         uuid,                                                    -- appended: the wall's key
    item_type        text,                                                    -- appended
    CHECK ((entity_uuid IS NULL) <> (entity_id IS NULL))
);
CREATE UNIQUE INDEX search_index_uuid ON search_index (entity_kind, entity_uuid) WHERE entity_uuid IS NOT NULL;
CREATE UNIQUE INDEX search_index_id   ON search_index (entity_kind, entity_id) WHERE entity_id IS NOT NULL;
CREATE INDEX search_index_tsv   ON search_index USING gin (tsv);
CREATE INDEX search_index_time  ON search_index (occurred_at DESC);
CREATE INDEX search_index_board ON search_index (board_id);

CREATE OR REPLACE FUNCTION bd_search_tsv(p_title text, p_body text) RETURNS tsvector
    LANGUAGE sql STABLE AS $$
    SELECT setweight(to_tsvector('simple', unaccent(COALESCE(p_title, ''))), 'A') || setweight(to_tsvector('simple', unaccent(left(COALESCE(p_body, ''), 200000))), 'B');
$$;

CREATE OR REPLACE FUNCTION bd_search_put(p_kind text, p_uuid uuid, p_board uuid, p_author bigint, p_title text, p_body text,
                                         p_item_type text, p_at timestamptz, p_live boolean) RETURNS void
    LANGUAGE plpgsql AS $$
BEGIN
    IF NOT p_live OR (COALESCE(p_title, '') = '' AND COALESCE(p_body, '') = '') THEN
        DELETE FROM search_index WHERE entity_kind = p_kind AND entity_uuid = p_uuid;
        RETURN;
    END IF;
    INSERT INTO search_index (entity_kind, entity_uuid, board_id, author_member_id, title, body, tsv, has_link, occurred_at, updated_at, item_type)
    VALUES (p_kind, p_uuid, p_board, p_author, p_title, p_body, bd_search_tsv(p_title, p_body), COALESCE(p_body, '') ~* 'https?://', p_at, now(), p_item_type)
    ON CONFLICT (entity_kind, entity_uuid) WHERE entity_uuid IS NOT NULL DO UPDATE
       SET title = EXCLUDED.title, body = EXCLUDED.body, tsv = EXCLUDED.tsv, has_link = EXCLUDED.has_link, occurred_at = EXCLUDED.occurred_at,
           updated_at = now(), board_id = EXCLUDED.board_id, author_member_id = EXCLUDED.author_member_id, item_type = EXCLUDED.item_type;
END$$;

CREATE OR REPLACE FUNCTION bd_search_boards() RETURNS trigger
    LANGUAGE plpgsql AS $$
BEGIN
    PERFORM bd_search_put('board', NEW.id, NEW.id, NEW.owner_member_id, NEW.title, NEW.description, NULL, NEW.last_activity_at,
                          NEW.deleted_at IS NULL AND NOT (NEW.is_template AND NEW.template_scope = 'shipped'));
    RETURN NULL;
END$$;
CREATE TRIGGER boards_search AFTER INSERT OR UPDATE OF title, description, deleted_at ON boards FOR EACH ROW EXECUTE FUNCTION bd_search_boards();

-- An item is searchable by its text, a frame by its title, an image or a file by its title.
CREATE OR REPLACE FUNCTION bd_search_items() RETURNS trigger
    LANGUAGE plpgsql AS $$
BEGIN
    PERFORM bd_search_put('item', NEW.id, NEW.board_id, COALESCE(NEW.updated_by, NEW.created_by),
                          CASE WHEN NEW.type IN ('frame', 'image', 'document') THEN NEW.data->>'title' END,
                          NULLIF(NEW.plain_text, ''), NEW.type, NEW.updated_at, NEW.deleted_at IS NULL);
    RETURN NULL;
END$$;
CREATE TRIGGER board_items_search_ins AFTER INSERT ON board_items FOR EACH ROW
    WHEN (NEW.plain_text <> '' OR NEW.data ? 'title') EXECUTE FUNCTION bd_search_items();
CREATE TRIGGER board_items_search_upd AFTER UPDATE OF data, deleted_at ON board_items FOR EACH ROW
    WHEN (OLD.plain_text IS DISTINCT FROM NEW.plain_text OR OLD.data->'title' IS DISTINCT FROM NEW.data->'title'
          OR OLD.deleted_at IS DISTINCT FROM NEW.deleted_at)
    EXECUTE FUNCTION bd_search_items();

CREATE OR REPLACE FUNCTION bd_search_comments() RETURNS trigger
    LANGUAGE plpgsql AS $$
BEGIN
    PERFORM bd_search_put('comment', NEW.id, NEW.board_id, NEW.author_member_id, NULL, NEW.plain_text, NULL, NEW.created_at, NEW.deleted_at IS NULL);
    RETURN NULL;
END$$;
CREATE TRIGGER comments_search AFTER INSERT OR UPDATE OF body, deleted_at ON comments FOR EACH ROW EXECUTE FUNCTION bd_search_comments();

GRANT SELECT, INSERT, UPDATE, DELETE ON search_index TO boards_rw;

COMMIT;
