-- 006: BOARDS — the rich-text format, the four levels, boards, their permission rows, folders, favourites, recents, and THE WALL
-- (docs/boards-design.md §3, §6.1; D3, D11, D12).
--
-- THE ESTATE'S DATA MODEL (design §0.4, §6.7):
--   rich text           Spaces db/005 verbatim (sp_ → bd_): one JSON array of runs, the one format for item text and comments (D5)
--   board_permissions   Spaces db/007 page_permissions (page_id → board_id; principal_kind ⊂ member/department/agent/everyone;
--                       level ⊂ none/view/comment/edit/full) — the board-wide level is the `everyone` row
--   board_favorites,    Spaces db/007 page_favorites / page_recents (page_id → board_id)
--   board_recents
--   boards              NEW CANONICAL — a canvas with a start view, a lock and an op sequence; templates are is_template rows
--   board_folders,      NEW CANONICAL — folders hold boards and GRANT NOTHING (DocCloud's folders grant; these do not)
--   board_folder_entries
--
-- THE WALL lives here, in SQL: bd_board_level(board, member) — one query — and bd_member_board_levels(member), the same rule for
-- every board at once (one statement; the views join it once per statement). The proof checks the two agree for every caller.
BEGIN;

CREATE EXTENSION IF NOT EXISTS btree_gist;     -- db/007: the (board_id, bbox) GiST index
CREATE EXTENSION IF NOT EXISTS unaccent;       -- db/010: search, Spaces' tsvector

-- ---------------------------------------------------------------------------------------------
-- Rich text (Spaces db/005, verbatim but for the prefix). Item text in v1 is plain runs with whole-item style (D5); comments
-- use the full format with mentions.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION bd_rich_text_plain(p jsonb) RETURNS text
    LANGUAGE sql IMMUTABLE AS $$
    SELECT COALESCE(string_agg(
             COALESCE(r->>'plain_text',
                      CASE r->>'type'
                        WHEN 'text' THEN r->'text'->>'content'
                        WHEN 'equation' THEN r->'equation'->>'expression'
                        WHEN 'mention' THEN COALESCE(r->'mention'->>'plain_text', '@' || COALESCE(r->'mention'->>'name', r->'mention'->>'type'))
                        ELSE ''
                      END, ''), '' ORDER BY ord), '')
      FROM jsonb_array_elements(CASE WHEN jsonb_typeof(p) = 'array' THEN p ELSE '[]'::jsonb END) WITH ORDINALITY AS t(r, ord);
$$;
CREATE OR REPLACE FUNCTION bd_is_rich_text(p jsonb) RETURNS boolean
    LANGUAGE sql IMMUTABLE AS $$
    SELECT p IS NOT NULL AND jsonb_typeof(p) = 'array'
       AND NOT EXISTS (SELECT 1 FROM jsonb_array_elements(p) r
                        WHERE jsonb_typeof(r) <> 'object' OR r->>'type' NOT IN ('text', 'mention', 'equation'));
$$;
CREATE OR REPLACE FUNCTION bd_rich_text_mentions(p jsonb, p_kind text) RETURNS bigint[]
    LANGUAGE sql IMMUTABLE AS $$
    SELECT COALESCE(array_agg(DISTINCT (r->'mention'->>'id')::bigint), '{}')
      FROM jsonb_array_elements(CASE WHEN jsonb_typeof(p) = 'array' THEN p ELSE '[]'::jsonb END) r
     WHERE r->>'type' = 'mention' AND r->'mention'->>'type' = p_kind AND (r->'mention'->>'id') ~ '^[0-9]+$';
$$;
CREATE OR REPLACE FUNCTION bd_rich_text(p text) RETURNS jsonb
    LANGUAGE sql IMMUTABLE AS $$
    SELECT CASE WHEN p IS NULL OR p = '' THEN '[]'::jsonb
                ELSE jsonb_build_array(jsonb_build_object('type', 'text', 'text', jsonb_build_object('content', p, 'link', NULL),
                        'annotations', jsonb_build_object('bold', false, 'italic', false, 'strikethrough', false, 'underline', false, 'code', false, 'color', 'default'),
                        'plain_text', p, 'href', NULL)) END;
$$;

-- ---------------------------------------------------------------------------------------------
-- The four levels (Miro's viewer / commenter / editor / owner in the estate's words; Spaces' vocabulary, a subset)
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION bd_level_rank(p_level text) RETURNS smallint
    LANGUAGE sql IMMUTABLE AS $$
    SELECT CASE p_level WHEN 'view' THEN 1 WHEN 'comment' THEN 2 WHEN 'edit' THEN 3 WHEN 'full' THEN 4 ELSE 0 END::smallint;
$$;
CREATE OR REPLACE FUNCTION bd_level_of_rank(p_rank integer) RETURNS text
    LANGUAGE sql IMMUTABLE AS $$
    SELECT CASE p_rank WHEN 1 THEN 'view' WHEN 2 THEN 'comment' WHEN 3 THEN 'edit' WHEN 4 THEN 'full' ELSE 'none' END;
$$;

-- ---------------------------------------------------------------------------------------------
-- Boards
-- ---------------------------------------------------------------------------------------------
CREATE TABLE boards (
    id                      uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    title                   text NOT NULL CHECK (length(btrim(title)) BETWEEN 1 AND 200),
    description             text CHECK (description IS NULL OR length(description) <= 2000),
    owner_member_id         bigint REFERENCES members(id) ON DELETE RESTRICT,                         -- NULL only for a shipped template
    is_template             boolean NOT NULL DEFAULT false,
    template_scope          text CHECK (template_scope IN ('personal', 'business', 'shipped')),
    template_category       text CHECK (template_category IN ('retrospective', 'brainstorm', 'strategy', 'planning', 'diagram', 'workshop', 'other')),
    background              jsonb NOT NULL DEFAULT '{"pattern": "dots", "color": "#ffffff"}' CHECK (jsonb_typeof(background) = 'object'),
    start_view              jsonb CHECK (start_view IS NULL OR jsonb_typeof(start_view) = 'object'),   -- {x, y, zoom}: Miro's "start view"
    is_locked               boolean NOT NULL DEFAULT false,                                           -- read-only for all but `full`
    item_count              integer NOT NULL DEFAULT 0 CHECK (item_count >= 0),                        -- live items, kept by db/007's trigger
    last_seq                bigint NOT NULL DEFAULT 0,                                                 -- the last committed batch
    last_activity_at        timestamptz NOT NULL DEFAULT now(),
    thumbnail_attachment_id bigint,                                                                    -- FK in db/008 (attachments)
    duplicated_from         uuid REFERENCES boards(id) ON DELETE SET NULL,                              -- a duplicate's or a template use's source
    created_by              bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at              timestamptz NOT NULL DEFAULT now(),
    updated_at              timestamptz NOT NULL DEFAULT now(),
    deleted_at              timestamptz,                                                               -- the trash (D: 30 days, bd_settings.trash_days)
    deleted_by              bigint REFERENCES members(id) ON DELETE SET NULL,
    CHECK (is_template = (template_scope IS NOT NULL)),
    CHECK (owner_member_id IS NOT NULL OR template_scope = 'shipped'),
    CHECK (template_category IS NULL OR is_template)
);
CREATE INDEX boards_owner_idx    ON boards (owner_member_id) WHERE deleted_at IS NULL;
CREATE INDEX boards_recent_idx   ON boards (last_activity_at DESC) WHERE deleted_at IS NULL;
CREATE INDEX boards_template_idx ON boards (template_scope, template_category) WHERE is_template AND deleted_at IS NULL;
CREATE INDEX boards_trash_idx    ON boards (deleted_at) WHERE deleted_at IS NOT NULL;
CREATE TRIGGER boards_touch BEFORE UPDATE ON boards FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- Spaces' page_permissions with board_id. No primary key in the canonical; one row per principal by the unique index.
CREATE TABLE board_permissions (
    board_id        uuid NOT NULL REFERENCES boards(id) ON DELETE CASCADE,
    principal_kind  text NOT NULL CHECK (principal_kind IN ('member', 'department', 'agent', 'everyone')),
    principal_id    bigint,                                                              -- NULL for everyone
    level           text NOT NULL CHECK (level IN ('none', 'view', 'comment', 'edit', 'full')),
    granted_by      bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at      timestamptz NOT NULL DEFAULT now(),
    CHECK ((principal_kind = 'everyone') = (principal_id IS NULL))
);
CREATE UNIQUE INDEX board_permissions_one ON board_permissions (board_id, principal_kind, COALESCE(principal_id, 0));
CREATE INDEX board_permissions_principal_idx ON board_permissions (principal_kind, principal_id);

-- A permission row names a principal that exists and is of its kind (a member row is a person, an agent row an agent).
CREATE OR REPLACE FUNCTION bd_board_permissions_before() RETURNS trigger
    LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.principal_kind IN ('member', 'agent') AND NOT EXISTS (
         SELECT 1 FROM members m WHERE m.id = NEW.principal_id
            AND m.member_kind = CASE NEW.principal_kind WHEN 'member' THEN 'human' ELSE 'agent' END) THEN
        RAISE EXCEPTION 'There is no such %.', CASE NEW.principal_kind WHEN 'member' THEN 'person' ELSE 'agent' END USING ERRCODE = 'foreign_key_violation';
    END IF;
    IF NEW.principal_kind = 'department' AND NOT EXISTS (SELECT 1 FROM departments d WHERE d.id = NEW.principal_id AND d.archived_at IS NULL) THEN
        RAISE EXCEPTION 'There is no such department.' USING ERRCODE = 'foreign_key_violation';
    END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER board_permissions_before BEFORE INSERT OR UPDATE ON board_permissions FOR EACH ROW EXECUTE FUNCTION bd_board_permissions_before();

-- Folders grant nothing: a folder lists only what its viewer may open. A team folder is seen by every Member.
CREATE TABLE board_folders (
    id               bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name             text NOT NULL CHECK (length(btrim(name)) BETWEEN 1 AND 120),
    owner_member_id  bigint NOT NULL REFERENCES members(id) ON DELETE RESTRICT,
    is_team          boolean NOT NULL DEFAULT false,
    position         text NOT NULL DEFAULT 'a0',
    created_at       timestamptz NOT NULL DEFAULT now(),
    updated_at       timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX board_folders_owner_idx ON board_folders (owner_member_id);
CREATE TRIGGER board_folders_touch BEFORE UPDATE ON board_folders FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
CREATE TABLE board_folder_entries (
    folder_id   bigint NOT NULL REFERENCES board_folders(id) ON DELETE CASCADE,
    board_id    uuid NOT NULL REFERENCES boards(id) ON DELETE CASCADE,
    position    text NOT NULL DEFAULT 'a0',
    added_by    bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at  timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (folder_id, board_id)
);
CREATE INDEX board_folder_entries_board_idx ON board_folder_entries (board_id);

-- Spaces' page_favorites / page_recents with board_id.
CREATE TABLE board_favorites (
    member_id   bigint NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    board_id    uuid NOT NULL REFERENCES boards(id) ON DELETE CASCADE,
    position    text NOT NULL DEFAULT 'a0',
    created_at  timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (member_id, board_id)
);
CREATE TABLE board_recents (
    member_id   bigint NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    board_id    uuid NOT NULL REFERENCES boards(id) ON DELETE CASCADE,
    visited_at  timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (member_id, board_id)
);
CREATE INDEX board_recents_member_idx ON board_recents (member_id, visited_at DESC);

-- ---------------------------------------------------------------------------------------------
-- THE WALL (design §3). A person's level on a board is the highest of: owner → full; a Boards admin (a PERSON holding
-- boards.admin; the super-admin holds it) → full; their member/agent row; a department row for a department they belong to now;
-- the `everyone` row unless they are external; a shipped or business template → view. Then capped by the application role
-- (bd_level_cap: a Viewer never above `comment`). No Boards grant (a super-admin included — db/004's rule), an inactive member, or no
-- board → none.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION bd_is_boards_admin(p_member_id bigint) RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    RETURN EXISTS (SELECT 1 FROM members m WHERE m.id = p_member_id AND m.member_kind = 'human' AND m.status = 'active')
       AND bd_member_has_right(p_member_id, 'boards.admin');
END$$;

CREATE OR REPLACE FUNCTION bd_board_level(p_board_id uuid, p_member_id bigint) RETURNS text
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE r text;
BEGIN
    SELECT CASE
             WHEN b.id IS NULL OR m.status <> 'active' OR m.capability IS NULL THEN 'none'          -- no Boards grant: nothing (a super-admin too)
             ELSE bd_level_of_rank(LEAST(
                    GREATEST(
                      CASE WHEN b.owner_member_id = m.id OR bd_is_boards_admin(m.id) THEN 4 ELSE 0 END,
                      CASE WHEN b.is_template AND b.template_scope IN ('shipped', 'business') AND NOT m.is_external THEN 1 ELSE 0 END,
                      COALESCE((SELECT max(bd_level_rank(p.level)) FROM board_permissions p
                                 WHERE p.board_id = b.id
                                   AND (   (p.principal_kind IN ('member', 'agent') AND p.principal_id = m.id)
                                        OR (p.principal_kind = 'department' AND p.principal_id IN (
                                              SELECT dm.department_id FROM department_members dm WHERE dm.member_id = m.id AND dm.left_at IS NULL))
                                        OR (p.principal_kind = 'everyone' AND NOT m.is_external))), 0)),
                    bd_level_rank(bd_level_cap(m.id))))
           END
      INTO r
      FROM members m LEFT JOIN boards b ON b.id = p_board_id
     WHERE m.id = p_member_id;
    RETURN COALESCE(r, 'none');
END$$;

-- The same rule for every board at once (one statement): what the views and lists join, once per statement.
CREATE OR REPLACE FUNCTION bd_member_board_levels(p_member_id bigint) RETURNS TABLE (board_id uuid, level text)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    WITH me AS (
        SELECT m.id, m.is_external,
               (m.status = 'active' AND m.capability IS NOT NULL) AS ok,
               bd_is_boards_admin(m.id) AS is_admin,
               bd_level_rank(bd_level_cap(m.id)) AS cap
          FROM members m WHERE m.id = p_member_id),
    perm AS (
        SELECT p.board_id, max(bd_level_rank(p.level)) AS r
          FROM board_permissions p, me
         WHERE (p.principal_kind IN ('member', 'agent') AND p.principal_id = me.id)
            OR (p.principal_kind = 'department' AND p.principal_id IN (
                  SELECT dm.department_id FROM department_members dm WHERE dm.member_id = me.id AND dm.left_at IS NULL))
            OR (p.principal_kind = 'everyone' AND NOT me.is_external)
         GROUP BY p.board_id),
    lv AS (
        SELECT b.id AS board_id,
               LEAST(GREATEST(CASE WHEN b.owner_member_id = me.id OR me.is_admin THEN 4 ELSE 0 END,
                              CASE WHEN b.is_template AND b.template_scope IN ('shipped', 'business') AND NOT me.is_external THEN 1 ELSE 0 END,
                              COALESCE(perm.r, 0)), me.cap) AS r
          FROM me CROSS JOIN boards b LEFT JOIN perm ON perm.board_id = b.id
         WHERE me.ok)
    SELECT lv.board_id, bd_level_of_rank(lv.r) FROM lv WHERE lv.r > 0;
$$;

CREATE OR REPLACE FUNCTION bd_my_board_levels() RETURNS TABLE (board_id uuid, level text)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT * FROM bd_member_board_levels(app_current_member_id());
$$;
CREATE OR REPLACE FUNCTION bd_my_level(p_board_id uuid) RETURNS text
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT bd_board_level(p_board_id, app_current_member_id());
$$;

-- The relay's minute check (design §3, contract gap 5): NULL when the member has no open session any more (a kernel sign-out ends
-- them), the board is gone or in the trash, or the level fell to none; otherwise the level now. Granted to the records role, which
-- the relay connects as — the relay holds no write grant anywhere.
CREATE OR REPLACE FUNCTION bd_live_check(p_member_id bigint, p_board_id uuid) RETURNS text
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM member_sessions s WHERE s.member_id = p_member_id AND s.ended_at IS NULL)
       OR NOT EXISTS (SELECT 1 FROM boards b WHERE b.id = p_board_id AND b.deleted_at IS NULL) THEN
        RETURN NULL;
    END IF;
    RETURN NULLIF(bd_board_level(p_board_id, p_member_id), 'none');
END$$;

REVOKE ALL ON FUNCTION bd_is_boards_admin(bigint), bd_board_level(uuid, bigint), bd_member_board_levels(bigint), bd_my_board_levels(),
                       bd_my_level(uuid), bd_live_check(bigint, uuid) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION bd_is_boards_admin(bigint), bd_board_level(uuid, bigint), bd_member_board_levels(bigint), bd_my_board_levels(),
                          bd_my_level(uuid) TO boards_rw, boards_records_ro, boards_activity_ro;
GRANT EXECUTE ON FUNCTION bd_live_check(bigint, uuid) TO boards_records_ro;

GRANT SELECT, INSERT, UPDATE, DELETE ON boards, board_permissions, board_folders, board_folder_entries, board_favorites, board_recents TO boards_rw;

COMMIT;
