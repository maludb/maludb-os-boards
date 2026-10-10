-- 011: WHAT A BOARD DOES — create, update, lock, share, transfer, trash, restore and purge; versions and restore; duplicate and
-- templates; comments with mentions, notifications and agent dispatches; and the worker's housekeeping (docs/boards-design.md §3,
-- §5, §6; D3, D6, D8, D11, D12). Every function that acts for a person requires p_member_id = the signed-in member (app.member_id),
-- so a handler cannot act as someone else; the worker's functions run with no member. Refusals are SQLSTATEs the kit's write guard
-- reads: 42501 (not allowed), P0002 (not found — never "forbidden" for a board one cannot see), 23514 (a rule), 22023 (malformed).
BEGIN;

CREATE OR REPLACE FUNCTION bd_require_member(p_member_id bigint) RETURNS void
    LANGUAGE plpgsql STABLE AS $$
BEGIN
    IF p_member_id IS NULL OR p_member_id IS DISTINCT FROM app_current_member_id() THEN
        RAISE EXCEPTION 'The acting member is not the signed-in one.' USING ERRCODE = 'insufficient_privilege';
    END IF;
END$$;

-- The caller's rank on a board, refusing below p_need: rank 0 is "no such board", never "forbidden".
CREATE OR REPLACE FUNCTION bd_require_level(p_board_id uuid, p_member_id bigint, p_need text) RETURNS smallint
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE r smallint := bd_level_rank(bd_board_level(p_board_id, p_member_id));
BEGIN
    IF r = 0 OR NOT EXISTS (SELECT 1 FROM boards WHERE id = p_board_id AND deleted_at IS NULL) THEN
        RAISE EXCEPTION 'There is no such board.' USING ERRCODE = 'no_data_found';
    END IF;
    IF r < bd_level_rank(p_need) THEN
        RAISE EXCEPTION '%', CASE p_need WHEN 'comment' THEN 'You can see this board but not comment on it.'
                                         WHEN 'edit' THEN 'You can see this board but not change it.'
                                         ELSE 'Only the board''s owners can do that.' END
              USING ERRCODE = 'insufficient_privilege';
    END IF;
    RETURN r;
END$$;

-- ---------------------------------------------------------------------------------------------
-- Boards
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION bd_board_create(p_member_id bigint, p_title text, p_description text DEFAULT NULL, p_folder_id bigint DEFAULT NULL)
    RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE v_id uuid; v_level text := bd_setting_text('default_everyone_level');
BEGIN
    PERFORM bd_require_member(p_member_id);
    IF NOT bd_member_has_right(p_member_id, 'boards.create') THEN
        RAISE EXCEPTION 'You may open boards shared with you but not make new ones.' USING ERRCODE = 'insufficient_privilege';
    END IF;
    INSERT INTO boards (title, description, owner_member_id, created_by)
    VALUES (btrim(p_title), NULLIF(btrim(p_description), ''), p_member_id, p_member_id) RETURNING id INTO v_id;
    IF v_level IS DISTINCT FROM 'none' THEN
        INSERT INTO board_permissions (board_id, principal_kind, principal_id, level, granted_by) VALUES (v_id, 'everyone', NULL, v_level, p_member_id);
    END IF;
    IF p_folder_id IS NOT NULL THEN
        PERFORM bd_folder_add(p_member_id, p_folder_id, v_id);
    END IF;
    RETURN v_id;
END$$;

-- Title, description, background, start view: an editor's. The lock: an owner's (bd_board_lock).
CREATE OR REPLACE FUNCTION bd_board_update(p_board_id uuid, p_member_id bigint, p_fields jsonb)
    RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE k text;
BEGIN
    PERFORM bd_require_member(p_member_id);
    PERFORM bd_require_level(p_board_id, p_member_id, 'edit');
    SELECT f INTO k FROM jsonb_object_keys(p_fields) f WHERE f NOT IN ('title', 'description', 'background', 'start_view') LIMIT 1;
    IF FOUND THEN
        RAISE EXCEPTION 'A board has no setting %.', k USING ERRCODE = 'invalid_parameter_value';
    END IF;
    UPDATE boards SET title       = CASE WHEN p_fields ? 'title' THEN btrim(p_fields->>'title') ELSE title END,
                      description = CASE WHEN p_fields ? 'description' THEN NULLIF(btrim(p_fields->>'description'), '') ELSE description END,
                      background  = CASE WHEN p_fields ? 'background' THEN p_fields->'background' ELSE background END,
                      start_view  = CASE WHEN p_fields ? 'start_view' THEN NULLIF(p_fields->'start_view', 'null'::jsonb) ELSE start_view END,
                      last_activity_at = now()
     WHERE id = p_board_id;
END$$;

CREATE OR REPLACE FUNCTION bd_board_lock(p_board_id uuid, p_member_id bigint, p_locked boolean)
    RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    PERFORM bd_require_member(p_member_id);
    PERFORM bd_require_level(p_board_id, p_member_id, 'full');
    UPDATE boards SET is_locked = p_locked WHERE id = p_board_id;
END$$;

-- Share with a person, an agent or a department at a level; 'none' removes the row. `everyone` is bd_board_access_set. An owner's.
-- Answers whether the principal can open Boards at all yet (a person or agent with no Boards grant sees nothing until the
-- super-admin grants it).
CREATE OR REPLACE FUNCTION bd_board_share(p_board_id uuid, p_member_id bigint, p_kind text, p_principal_id bigint, p_level text)
    RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE v_owner bigint; v_needs_grant boolean := false;
BEGIN
    PERFORM bd_require_member(p_member_id);
    PERFORM bd_require_level(p_board_id, p_member_id, 'full');
    IF p_kind NOT IN ('member', 'agent', 'department') THEN
        RAISE EXCEPTION 'Share with a person, an agent or a department.' USING ERRCODE = 'invalid_parameter_value';
    END IF;
    IF p_level NOT IN ('none', 'view', 'comment', 'edit', 'full') THEN
        RAISE EXCEPTION 'There is no level called %.', p_level USING ERRCODE = 'invalid_parameter_value';
    END IF;
    SELECT owner_member_id INTO v_owner FROM boards WHERE id = p_board_id;
    IF p_kind IN ('member', 'agent') AND p_principal_id = v_owner THEN
        RAISE EXCEPTION 'The owner already has every right on the board.' USING ERRCODE = 'check_violation';
    END IF;
    IF p_level = 'none' THEN
        DELETE FROM board_permissions WHERE board_id = p_board_id AND principal_kind = p_kind AND principal_id = p_principal_id;
    ELSE
        INSERT INTO board_permissions (board_id, principal_kind, principal_id, level, granted_by)
        VALUES (p_board_id, p_kind, p_principal_id, p_level, p_member_id)
        ON CONFLICT (board_id, principal_kind, COALESCE(principal_id, 0)) DO UPDATE SET level = EXCLUDED.level, granted_by = EXCLUDED.granted_by, created_at = now();
        IF p_kind IN ('member', 'agent') THEN
            v_needs_grant := NOT EXISTS (SELECT 1 FROM members m WHERE m.id = p_principal_id AND m.status = 'active' AND m.capability IS NOT NULL);
        END IF;
    END IF;
    RETURN jsonb_build_object('level', p_level, 'needs_grant', v_needs_grant);
END$$;

-- The board-wide level for every non-external member (and agent) granted Boards. An owner's.
CREATE OR REPLACE FUNCTION bd_board_access_set(p_board_id uuid, p_member_id bigint, p_level text)
    RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    PERFORM bd_require_member(p_member_id);
    PERFORM bd_require_level(p_board_id, p_member_id, 'full');
    IF p_level NOT IN ('none', 'view', 'comment', 'edit') THEN
        RAISE EXCEPTION 'Everyone may view, comment or edit — or nothing.' USING ERRCODE = 'invalid_parameter_value';
    END IF;
    DELETE FROM board_permissions WHERE board_id = p_board_id AND principal_kind = 'everyone';
    IF p_level <> 'none' THEN
        INSERT INTO board_permissions (board_id, principal_kind, principal_id, level, granted_by) VALUES (p_board_id, 'everyone', NULL, p_level, p_member_id);
    END IF;
END$$;

-- A new owner who may create boards; the previous owner keeps `edit`.
CREATE OR REPLACE FUNCTION bd_board_transfer(p_board_id uuid, p_member_id bigint, p_new_owner bigint)
    RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE v_old bigint;
BEGIN
    PERFORM bd_require_member(p_member_id);
    PERFORM bd_require_level(p_board_id, p_member_id, 'full');
    IF NOT EXISTS (SELECT 1 FROM members m WHERE m.id = p_new_owner AND m.member_kind = 'human' AND m.status = 'active')
       OR NOT bd_member_has_right(p_new_owner, 'boards.create') THEN
        RAISE EXCEPTION 'A board passes only to a person who may make boards.' USING ERRCODE = 'check_violation';
    END IF;
    SELECT owner_member_id INTO v_old FROM boards WHERE id = p_board_id FOR UPDATE;
    IF v_old = p_new_owner THEN RETURN; END IF;
    DELETE FROM board_permissions WHERE board_id = p_board_id AND principal_kind = 'member' AND principal_id = p_new_owner;
    UPDATE boards SET owner_member_id = p_new_owner WHERE id = p_board_id;
    IF v_old IS NOT NULL THEN
        INSERT INTO board_permissions (board_id, principal_kind, principal_id, level, granted_by) VALUES (p_board_id, 'member', v_old, 'edit', p_member_id)
        ON CONFLICT (board_id, principal_kind, COALESCE(principal_id, 0)) DO UPDATE SET level = 'edit', granted_by = EXCLUDED.granted_by;
    END IF;
END$$;

-- The trash: an owner's to send, the owner's or an admin's to bring back within trash_days; the worker purges after.
CREATE OR REPLACE FUNCTION bd_board_delete(p_board_id uuid, p_member_id bigint)
    RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    PERFORM bd_require_member(p_member_id);
    PERFORM bd_require_level(p_board_id, p_member_id, 'full');
    UPDATE boards SET deleted_at = now(), deleted_by = p_member_id WHERE id = p_board_id;
END$$;
CREATE OR REPLACE FUNCTION bd_board_restore(p_board_id uuid, p_member_id bigint)
    RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    PERFORM bd_require_member(p_member_id);
    IF NOT EXISTS (SELECT 1 FROM boards b WHERE b.id = p_board_id AND b.deleted_at IS NOT NULL
                     AND (b.owner_member_id = p_member_id OR bd_is_boards_admin(p_member_id))) THEN
        RAISE EXCEPTION 'There is no such board in your trash.' USING ERRCODE = 'no_data_found';
    END IF;
    UPDATE boards SET deleted_at = NULL, deleted_by = NULL WHERE id = p_board_id;
END$$;
-- Gone for good, with everything on it. Answers the files the caller must unlink (the worker, or an admin's purge).
CREATE OR REPLACE FUNCTION bd_board_purge(p_board_id uuid) RETURNS text[]
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE v_paths text[];
BEGIN
    IF app_current_member_id() IS NOT NULL AND NOT bd_is_boards_admin(app_current_member_id()) THEN
        RAISE EXCEPTION 'Only a Boards admin empties the trash.' USING ERRCODE = 'insufficient_privilege';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM boards WHERE id = p_board_id AND deleted_at IS NOT NULL) THEN
        RAISE EXCEPTION 'Only a board in the trash is purged.' USING ERRCODE = 'check_violation';
    END IF;
    SELECT COALESCE(array_agg(p), '{}') INTO v_paths
      FROM (SELECT a.storage_path AS p FROM attachments a WHERE a.board_id = p_board_id
            UNION ALL SELECT t.value FROM attachments a, jsonb_each_text(a.thumbnails) t WHERE a.board_id = p_board_id
            UNION ALL SELECT a.thumbnail_path FROM attachments a WHERE a.board_id = p_board_id AND a.thumbnail_path IS NOT NULL) x;
    UPDATE boards SET thumbnail_attachment_id = NULL WHERE id = p_board_id;
    DELETE FROM attachments WHERE board_id = p_board_id;
    DELETE FROM search_index WHERE board_id = p_board_id;
    DELETE FROM boards WHERE id = p_board_id;
    RETURN v_paths;
END$$;

-- ---------------------------------------------------------------------------------------------
-- Folders (grant nothing; a team folder is every Member's to see, its owner's to change)
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION bd_folder_add(p_member_id bigint, p_folder_id bigint, p_board_id uuid)
    RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    PERFORM bd_require_member(p_member_id);
    IF NOT EXISTS (SELECT 1 FROM board_folders f WHERE f.id = p_folder_id AND (f.owner_member_id = p_member_id OR bd_is_boards_admin(p_member_id))) THEN
        RAISE EXCEPTION 'There is no such folder of yours.' USING ERRCODE = 'no_data_found';
    END IF;
    PERFORM bd_require_level(p_board_id, p_member_id, 'view');
    INSERT INTO board_folder_entries (folder_id, board_id, added_by) VALUES (p_folder_id, p_board_id, p_member_id) ON CONFLICT DO NOTHING;
END$$;

-- ---------------------------------------------------------------------------------------------
-- Versions (D6). A person's named version needs `edit`; the worker's interval and compaction versions run with no member.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION bd_board_snapshot(p_board_id uuid) RETURNS jsonb
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT jsonb_build_object('title', b.title, 'background', b.background, 'start_view', b.start_view,
             'items', COALESCE((SELECT jsonb_agg(bd_item_json(i) ORDER BY (i.type = 'frame') DESC, i.z, i.id)
                                  FROM board_items i WHERE i.board_id = b.id AND i.deleted_at IS NULL), '[]'))
      FROM boards b WHERE b.id = p_board_id;
$$;

CREATE OR REPLACE FUNCTION bd_save_version(p_board_id uuid, p_member_id bigint, p_reason text, p_label text DEFAULT NULL)
    RETURNS integer LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE v_no integer; v_board boards%ROWTYPE; v_snap jsonb;
BEGIN
    IF p_reason IN ('interval', 'compaction') THEN
        IF app_current_member_id() IS NOT NULL OR p_member_id IS NOT NULL THEN
            RAISE EXCEPTION 'Interval and compaction versions are the worker''s.' USING ERRCODE = 'insufficient_privilege';
        END IF;
    ELSE
        PERFORM bd_require_member(p_member_id);
        PERFORM bd_require_level(p_board_id, p_member_id, 'edit');
    END IF;
    SELECT * INTO v_board FROM boards WHERE id = p_board_id FOR NO KEY UPDATE;
    v_snap := bd_board_snapshot(p_board_id);
    SELECT COALESCE(max(version_no), 0) + 1 INTO v_no FROM board_versions WHERE board_id = p_board_id;
    INSERT INTO board_versions (board_id, version_no, content_rev, reason, snapshot, plain_text, saved_by, label, item_count)
    VALUES (p_board_id, v_no, v_board.last_seq, p_reason, v_snap,
            left((SELECT string_agg(NULLIF(bd_rich_text_plain(x->'data'->'content'), ''), E'\n') FROM jsonb_array_elements(v_snap->'items') x), 200000),
            p_member_id, NULLIF(btrim(p_label), ''), jsonb_array_length(v_snap->'items'));
    RETURN v_no;
END$$;

-- Restore is never destructive: a before_restore version, then ONE batch that turns today's items into the snapshot's, then a
-- restore version. Locked items are unlocked for the change and locked again as the snapshot had them.
CREATE OR REPLACE FUNCTION bd_restore_version(p_board_id uuid, p_member_id bigint, p_version_no integer, p_client_batch_id uuid DEFAULT NULL)
    RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE v_snap jsonb; v_ops jsonb := '[]'; v_ids uuid[]; v_result jsonb; r record; v_fields jsonb; v_live board_items%ROWTYPE;
        v_cols text[] := ARRAY['x', 'y', 'width', 'height', 'rotation', 'z', 'parent_id', 'start_item_id', 'end_item_id'];
BEGIN
    PERFORM bd_require_member(p_member_id);
    PERFORM bd_require_level(p_board_id, p_member_id, 'full');
    SELECT snapshot INTO v_snap FROM board_versions WHERE board_id = p_board_id AND version_no = p_version_no;
    IF NOT FOUND THEN
        RAISE EXCEPTION 'This board has no version %.', p_version_no USING ERRCODE = 'no_data_found';
    END IF;
    PERFORM bd_save_version(p_board_id, p_member_id, 'before_restore', NULL);

    -- 1. what the snapshot has and today's board deleted: restore it
    v_ids := ARRAY(SELECT (s->>'id')::uuid FROM jsonb_array_elements(v_snap->'items') s
                     JOIN board_items i ON i.id = (s->>'id')::uuid AND i.board_id = p_board_id AND i.deleted_at IS NOT NULL);
    IF cardinality(v_ids) > 0 THEN
        v_ops := v_ops || jsonb_build_array(jsonb_build_object('op', 'restore', 'ids', to_jsonb(v_ids)));
    END IF;
    -- 2. what no longer exists at all (purged): create it — frames, then items, then connectors
    FOR r IN SELECT s FROM jsonb_array_elements(v_snap->'items') s
              WHERE NOT EXISTS (SELECT 1 FROM board_items i WHERE i.id = (s->>'id')::uuid)
              ORDER BY CASE s->>'type' WHEN 'frame' THEN 0 WHEN 'connector' THEN 2 ELSE 1 END LOOP
        v_ops := v_ops || jsonb_build_array(jsonb_build_object('op', 'create', 'item', r.s - 'version' - 'created_by' - 'updated_by'));
    END LOOP;
    -- 3. what both have and differs: set it as the snapshot had it (style and data exactly: keys today has and it lacked go)
    FOR r IN SELECT s FROM jsonb_array_elements(v_snap->'items') s
              WHERE EXISTS (SELECT 1 FROM board_items i WHERE i.id = (s->>'id')::uuid)
              ORDER BY CASE s->>'type' WHEN 'frame' THEN 0 WHEN 'connector' THEN 2 ELSE 1 END LOOP
        SELECT * INTO v_live FROM board_items WHERE id = (r.s->>'id')::uuid;
        SELECT COALESCE(jsonb_object_agg(c, r.s->c), '{}') INTO v_fields
          FROM unnest(v_cols) c WHERE (to_jsonb(v_live)->c) IS DISTINCT FROM (r.s->c);
        IF v_live.style IS DISTINCT FROM r.s->'style' THEN
            v_fields := v_fields || jsonb_build_object('style', (SELECT COALESCE(jsonb_object_agg(k, 'null'::jsonb), '{}') FROM jsonb_object_keys(v_live.style) k
                                                                 WHERE NOT (r.s->'style') ? k) || (r.s->'style'));
        END IF;
        IF v_live.data IS DISTINCT FROM r.s->'data' THEN
            v_fields := v_fields || jsonb_build_object('data', (SELECT COALESCE(jsonb_object_agg(k, 'null'::jsonb), '{}') FROM jsonb_object_keys(v_live.data) k
                                                                WHERE NOT (r.s->'data') ? k) || (r.s->'data'));
        END IF;
        IF v_fields <> '{}' THEN
            IF v_live.locked THEN v_fields := v_fields || '{"locked": false}'; END IF;
            v_ops := v_ops || jsonb_build_array(jsonb_build_object('op', 'update', 'id', v_live.id, 'fields', v_fields));
            IF (r.s->>'locked')::boolean THEN
                v_ops := v_ops || jsonb_build_array(jsonb_build_object('op', 'update', 'id', v_live.id, 'fields', '{"locked": true}'::jsonb));
            END IF;
        ELSIF v_live.locked IS DISTINCT FROM (r.s->>'locked')::boolean THEN
            v_ops := v_ops || jsonb_build_array(jsonb_build_object('op', 'update', 'id', v_live.id, 'fields', jsonb_build_object('locked', (r.s->>'locked')::boolean)));
        END IF;
    END LOOP;
    -- 4. what today has and the snapshot lacked: delete it (unlocked first), nothing else with it
    FOR v_live IN SELECT * FROM board_items i WHERE i.board_id = p_board_id AND i.deleted_at IS NULL
                    AND NOT EXISTS (SELECT 1 FROM jsonb_array_elements(v_snap->'items') s WHERE (s->>'id')::uuid = i.id)
                  ORDER BY CASE i.type WHEN 'connector' THEN 0 WHEN 'frame' THEN 2 ELSE 1 END LOOP
        IF v_live.locked THEN
            v_ops := v_ops || jsonb_build_array(jsonb_build_object('op', 'update', 'id', v_live.id, 'fields', '{"locked": false}'::jsonb));
        END IF;
        v_ops := v_ops || jsonb_build_array(jsonb_build_object('op', 'delete', 'ids', jsonb_build_array(v_live.id), 'cascade', false));
    END LOOP;

    IF jsonb_array_length(v_ops) = 0 THEN
        RETURN jsonb_build_object('noop', true);
    END IF;
    v_result := bd_commit_ops(p_board_id, p_member_id, 'restore', NULL, p_client_batch_id, v_ops);
    UPDATE boards SET title = COALESCE(v_snap->>'title', title), background = COALESCE(v_snap->'background', background),
                      start_view = NULLIF(v_snap->'start_view', 'null'::jsonb) WHERE id = p_board_id;   -- a JSON null is no start view
    PERFORM bd_save_version(p_board_id, p_member_id, 'restore', 'Restored version ' || p_version_no);
    RETURN v_result || jsonb_build_object('restored_version', p_version_no);
END$$;

-- ---------------------------------------------------------------------------------------------
-- Duplicate (and a template's use, and saving as a template — D12). New ids are deterministic per new board, so parents and
-- connector ends map without a table. A frame's child whose frame is gone lands on the canvas where it stood.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION bd_board_duplicate(p_board_id uuid, p_member_id bigint, p_title text DEFAULT NULL,
                                              p_template_scope text DEFAULT NULL, p_template_category text DEFAULT NULL)
    RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE v_src boards%ROWTYPE; v_new uuid := gen_random_uuid(); v_level text := bd_setting_text('default_everyone_level');
BEGIN
    PERFORM bd_require_member(p_member_id);
    PERFORM bd_require_level(p_board_id, p_member_id, 'view');
    IF NOT bd_member_has_right(p_member_id, 'boards.create') THEN
        RAISE EXCEPTION 'You may open boards shared with you but not make new ones.' USING ERRCODE = 'insufficient_privilege';
    END IF;
    IF p_template_scope = 'business' AND NOT bd_member_has_right(p_member_id, 'templates.publish') THEN
        RAISE EXCEPTION 'Only a Boards admin publishes a template for the business.' USING ERRCODE = 'insufficient_privilege';
    ELSIF p_template_scope IS NOT NULL AND p_template_scope NOT IN ('personal', 'business') THEN
        RAISE EXCEPTION 'A template is personal or the business''s.' USING ERRCODE = 'invalid_parameter_value';
    END IF;
    SELECT * INTO v_src FROM boards WHERE id = p_board_id;
    INSERT INTO boards (id, title, description, owner_member_id, is_template, template_scope, template_category, background, start_view,
                        duplicated_from, created_by)
    VALUES (v_new, COALESCE(NULLIF(btrim(p_title), ''), CASE WHEN v_src.is_template AND p_template_scope IS NULL THEN v_src.title ELSE v_src.title || ' (copy)' END),
            v_src.description, p_member_id, p_template_scope IS NOT NULL, p_template_scope,
            CASE WHEN p_template_scope IS NOT NULL THEN COALESCE(p_template_category, v_src.template_category, 'other') END,
            v_src.background, v_src.start_view, p_board_id, p_member_id);
    IF p_template_scope IS NULL AND v_level IS DISTINCT FROM 'none' THEN
        INSERT INTO board_permissions (board_id, principal_kind, principal_id, level, granted_by) VALUES (v_new, 'everyone', NULL, v_level, p_member_id);
    END IF;
    -- frames
    INSERT INTO board_items (id, board_id, type, parent_id, x, y, width, height, rotation, z, style, data, locked, text_version, text_by, created_by, updated_by)
    SELECT md5(v_new::text || i.id::text)::uuid, v_new, i.type, NULL, i.x, i.y, i.width, i.height, i.rotation, i.z, i.style, i.data, i.locked,
           CASE WHEN i.data ? 'content' THEN 1 ELSE 0 END, CASE WHEN i.data ? 'content' THEN p_member_id END, p_member_id, p_member_id
      FROM board_items i WHERE i.board_id = p_board_id AND i.deleted_at IS NULL AND i.type = 'frame';
    -- items (a child of a live frame keeps it; of a gone frame, lands where it stood)
    INSERT INTO board_items (id, board_id, type, parent_id, x, y, width, height, rotation, z, style, data, locked, text_version, text_by, created_by, updated_by)
    SELECT md5(v_new::text || i.id::text)::uuid, v_new, i.type,
           CASE WHEN f.id IS NOT NULL AND f.deleted_at IS NULL THEN md5(v_new::text || f.id::text)::uuid END,
           CASE WHEN f.id IS NOT NULL AND f.deleted_at IS NOT NULL THEN i.x + f.x - f.width / 2 ELSE i.x END,
           CASE WHEN f.id IS NOT NULL AND f.deleted_at IS NOT NULL THEN i.y + f.y - f.height / 2 ELSE i.y END,
           i.width, i.height, i.rotation, i.z, i.style, i.data, i.locked,
           CASE WHEN i.data ? 'content' THEN 1 ELSE 0 END, CASE WHEN i.data ? 'content' THEN p_member_id END, p_member_id, p_member_id
      FROM board_items i LEFT JOIN board_items f ON f.id = i.parent_id
     WHERE i.board_id = p_board_id AND i.deleted_at IS NULL AND i.type NOT IN ('frame', 'connector');
    -- connectors (an end that is gone becomes a free end where it was)
    INSERT INTO board_items (id, board_id, type, x, y, width, height, rotation, z, style, data, start_item_id, end_item_id, locked, created_by, updated_by)
    SELECT md5(v_new::text || i.id::text)::uuid, v_new, i.type, i.x, i.y, i.width, i.height, i.rotation, i.z, i.style, i.data,
           CASE WHEN s.deleted_at IS NULL AND s.id IS NOT NULL THEN md5(v_new::text || s.id::text)::uuid END,
           CASE WHEN e.deleted_at IS NULL AND e.id IS NOT NULL THEN md5(v_new::text || e.id::text)::uuid END,
           i.locked, p_member_id, p_member_id
      FROM board_items i LEFT JOIN board_items s ON s.id = i.start_item_id LEFT JOIN board_items e ON e.id = i.end_item_id
     WHERE i.board_id = p_board_id AND i.deleted_at IS NULL AND i.type = 'connector';
    RETURN v_new;
END$$;

-- ---------------------------------------------------------------------------------------------
-- Notifications (Spaces' outbox rule: email unless the person takes the digest; a text (K6) only when they opted in for the kind).
-- Only a person who can see the board is told anything about it.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION bd_notify(p_member_id bigint, p_kind text, p_title text, p_body text, p_board_id uuid,
                                     p_record_type text, p_record_uuid uuid, p_actor bigint)
    RETURNS bigint LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE v_id bigint; v_m members%ROWTYPE; v_p notification_prefs%ROWTYPE;
BEGIN
    SELECT * INTO v_m FROM members WHERE id = p_member_id AND member_kind = 'human' AND status = 'active';
    IF NOT FOUND OR p_member_id = p_actor OR bd_level_rank(bd_board_level(p_board_id, p_member_id)) = 0 THEN
        RETURN NULL;
    END IF;
    INSERT INTO notifications (member_id, kind, record_type, record_uuid, title, body, actor_member_id, board_id)
    VALUES (p_member_id, p_kind, p_record_type, p_record_uuid, left(p_title, 300), left(p_body, 2000), p_actor, p_board_id)
    RETURNING id INTO v_id;
    SELECT * INTO v_p FROM notification_prefs WHERE member_id = p_member_id;
    IF NOT FOUND THEN
        v_p.email_enabled := true; v_p.text_enabled := false; v_p.digest := false;
        v_p.kinds := '{mention,reply,comment,share,agent_replied}'; v_p.text_kinds := '{mention}';
    END IF;
    IF v_p.email_enabled AND NOT v_p.digest AND p_kind = ANY (v_p.kinds) AND v_m.email IS NOT NULL THEN
        INSERT INTO notification_outbox (channel, member_id, to_email, kind, record_type, dedupe_key, subject, body)
        VALUES ('email', p_member_id, v_m.email, p_kind, p_record_type, 'n:' || v_id || ':email', left(p_title, 300), COALESCE(left(p_body, 2000), p_title));
    END IF;
    IF v_p.text_enabled AND p_kind = ANY (v_p.text_kinds) THEN
        INSERT INTO notification_outbox (channel, member_id, kind, record_type, dedupe_key, subject, body)
        VALUES ('text', p_member_id, p_kind, p_record_type, 'n:' || v_id || ':text', NULL, left(COALESCE(p_body, p_title), 300));
    END IF;
    RETURN v_id;
END$$;

-- ---------------------------------------------------------------------------------------------
-- Comments (D11). A person's mention of an agent who can see the board becomes ONE dispatch (a chat turn of that agent, as Spaces
-- does); of an agent who cannot, a `needs_share` the screen asks the writer about — a mention never grants. An agent's own comment
-- dispatches nobody (no agent storms). Members and departments mentioned are told when they can see the board; the thread's people
-- are told of a reply, the owner of a new thread.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION bd_comment_create(p_board_id uuid, p_member_id bigint, p_body jsonb, p_item_id uuid DEFAULT NULL,
                                             p_x float8 DEFAULT NULL, p_y float8 DEFAULT NULL, p_parent_id uuid DEFAULT NULL,
                                             p_context uuid[] DEFAULT '{}', p_agent_run_id bigint DEFAULT NULL)
    RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE v_c comments%ROWTYPE; v_root uuid; v_author members%ROWTYPE; v_board boards%ROWTYPE; v_told bigint[] := '{}';
        v_dispatched bigint[] := '{}'; v_needs_share bigint[] := '{}'; v_not_shared bigint[] := '{}'; v_id bigint; v_n bigint; v_title text;
        v_excerpt text;
BEGIN
    PERFORM bd_require_member(p_member_id);
    PERFORM bd_require_level(p_board_id, p_member_id, 'comment');
    SELECT * INTO v_author FROM members WHERE id = p_member_id;
    SELECT * INTO v_board FROM boards WHERE id = p_board_id;
    INSERT INTO comments (board_id, item_id, parent_comment_id, author_member_id, body, anchor_x, anchor_y, context_item_ids, agent_run_id)
    VALUES (p_board_id, p_item_id, p_parent_id, p_member_id, p_body, p_x, p_y,
            ARRAY(SELECT u.c FROM unnest(COALESCE(p_context, '{}')) WITH ORDINALITY u(c, ord)          -- in the person's order (a semi-join
                   WHERE EXISTS (SELECT 1 FROM board_items i WHERE i.id = u.c AND i.board_id = p_board_id)    -- alone does not keep it)
                   ORDER BY u.ord),
            p_agent_run_id)
    RETURNING * INTO v_c;
    v_root := COALESCE(v_c.parent_comment_id, v_c.id);
    v_excerpt := left(v_c.plain_text, 200);
    v_title := v_author.display_name || ' on ' || v_board.title;

    -- people mentioned
    FOR v_id IN SELECT unnest(bd_rich_text_mentions(p_body, 'member')) LOOP
        IF EXISTS (SELECT 1 FROM members WHERE id = v_id AND member_kind = 'human') THEN
            INSERT INTO comment_mentions VALUES (v_c.id, 'member', v_id) ON CONFLICT DO NOTHING;
            IF bd_notify(v_id, 'mention', v_title || ' mentioned you', v_excerpt, p_board_id, 'comment', v_c.id, p_member_id) IS NOT NULL THEN
                v_told := v_told || v_id;
            ELSIF v_id <> p_member_id THEN
                v_not_shared := v_not_shared || v_id;
            END IF;
        END IF;
    END LOOP;
    -- agents mentioned
    FOR v_id IN SELECT unnest(bd_rich_text_mentions(p_body, 'agent')) LOOP
        IF EXISTS (SELECT 1 FROM members WHERE id = v_id AND member_kind = 'agent') THEN
            INSERT INTO comment_mentions VALUES (v_c.id, 'agent', v_id) ON CONFLICT DO NOTHING;
            IF v_author.member_kind = 'agent' THEN
                CONTINUE;
            ELSIF bd_level_rank(bd_board_level(p_board_id, v_id)) = 0 THEN
                v_needs_share := v_needs_share || v_id;
            ELSE
                INSERT INTO agent_dispatches (record_type, record_uuid, agent_member_id, kind, via, acting_member_id, board_id, conversation_id)
                VALUES ('comment', v_c.id, v_id, 'mention', 'chat', p_member_id, p_board_id, 'boards:thread:' || v_root);
                v_dispatched := v_dispatched || v_id;
            END IF;
        END IF;
    END LOOP;
    -- departments mentioned
    FOR v_id IN SELECT unnest(bd_rich_text_mentions(p_body, 'department')) LOOP
        IF EXISTS (SELECT 1 FROM departments WHERE id = v_id) THEN
            INSERT INTO comment_mentions VALUES (v_c.id, 'department', v_id) ON CONFLICT DO NOTHING;
            FOR v_n IN SELECT dm.member_id FROM department_members dm WHERE dm.department_id = v_id AND dm.left_at IS NULL
                         AND dm.member_id <> ALL (v_told) LOOP
                IF bd_notify(v_n, 'mention', v_title || ' mentioned your department', v_excerpt, p_board_id, 'comment', v_c.id, p_member_id) IS NOT NULL THEN
                    v_told := v_told || v_n;
                END IF;
            END LOOP;
        END IF;
    END LOOP;
    -- a reply: the thread's people; a new thread: the owner
    IF v_c.parent_comment_id IS NOT NULL THEN
        FOR v_n IN SELECT DISTINCT c.author_member_id FROM comments c
                    WHERE (c.id = v_root OR c.parent_comment_id = v_root) AND c.deleted_at IS NULL AND c.author_member_id IS NOT NULL
                      AND c.author_member_id <> ALL (v_told) LOOP
            IF bd_notify(v_n, 'reply', v_title || ' replied', v_excerpt, p_board_id, 'comment', v_c.id, p_member_id) IS NOT NULL THEN
                v_told := v_told || v_n;
            END IF;
        END LOOP;
    ELSIF v_board.owner_member_id IS NOT NULL AND v_board.owner_member_id <> ALL (v_told) THEN
        PERFORM bd_notify(v_board.owner_member_id, 'comment', v_title || ' commented', v_excerpt, p_board_id, 'comment', v_c.id, p_member_id);
    END IF;
    RETURN jsonb_build_object('id', v_c.id, 'root', v_root, 'dispatched', to_jsonb(v_dispatched), 'needs_share', to_jsonb(v_needs_share),
                              'not_shared', to_jsonb(v_not_shared));
END$$;

CREATE OR REPLACE FUNCTION bd_comment_resolve(p_comment_id uuid, p_member_id bigint, p_resolved boolean)
    RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE v_c comments%ROWTYPE;
BEGIN
    PERFORM bd_require_member(p_member_id);
    SELECT * INTO v_c FROM comments WHERE id = p_comment_id AND deleted_at IS NULL AND parent_comment_id IS NULL;
    IF NOT FOUND THEN RAISE EXCEPTION 'There is no such thread.' USING ERRCODE = 'no_data_found'; END IF;
    PERFORM bd_require_level(v_c.board_id, p_member_id, 'comment');
    UPDATE comments SET resolved_at = CASE WHEN p_resolved THEN now() END, resolved_by = CASE WHEN p_resolved THEN p_member_id END
     WHERE id = p_comment_id;
END$$;

-- One's own comment, or anyone's for the board's owners (an agent deleting another's is paused by the kernel: `deletion`).
CREATE OR REPLACE FUNCTION bd_comment_delete(p_comment_id uuid, p_member_id bigint)
    RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE v_c comments%ROWTYPE; r smallint;
BEGIN
    PERFORM bd_require_member(p_member_id);
    SELECT * INTO v_c FROM comments WHERE id = p_comment_id AND deleted_at IS NULL;
    IF NOT FOUND THEN RAISE EXCEPTION 'There is no such comment.' USING ERRCODE = 'no_data_found'; END IF;
    r := bd_require_level(v_c.board_id, p_member_id, 'comment');
    IF v_c.author_member_id IS DISTINCT FROM p_member_id AND r < 4 THEN
        RAISE EXCEPTION 'Only its author or the board''s owners remove a comment.' USING ERRCODE = 'insufficient_privilege';
    END IF;
    UPDATE comments SET deleted_at = now(), deleted_by = p_member_id WHERE id = p_comment_id OR parent_comment_id = p_comment_id;
END$$;

CREATE OR REPLACE FUNCTION bd_comment_edit(p_comment_id uuid, p_member_id bigint, p_body jsonb)
    RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    PERFORM bd_require_member(p_member_id);
    UPDATE comments SET body = p_body, edited_at = now() WHERE id = p_comment_id AND author_member_id = p_member_id AND deleted_at IS NULL;
    IF NOT FOUND THEN RAISE EXCEPTION 'Only its author edits a comment.' USING ERRCODE = 'insufficient_privilege'; END IF;
END$$;

-- ---------------------------------------------------------------------------------------------
-- The worker's housekeeping (no member; each answers what it did)
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION bd_require_worker() RETURNS void
    LANGUAGE plpgsql STABLE AS $$
BEGIN
    IF app_current_member_id() IS NOT NULL THEN
        RAISE EXCEPTION 'This is the worker''s.' USING ERRCODE = 'insufficient_privilege';
    END IF;
END$$;

-- An interval version for a board that changed since its last version, at most hourly.
CREATE OR REPLACE FUNCTION bd_interval_versions() RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE b uuid; n integer := 0;
BEGIN
    PERFORM bd_require_worker();
    FOR b IN SELECT bo.id FROM boards bo
              WHERE bo.deleted_at IS NULL AND bo.last_seq > 0
                AND bo.last_seq > COALESCE((SELECT max(v.content_rev) FROM board_versions v WHERE v.board_id = bo.id), 0)
                AND COALESCE((SELECT max(v.created_at) FROM board_versions v WHERE v.board_id = bo.id), '-infinity') < now() - interval '1 hour' LOOP
        PERFORM bd_save_version(b, NULL, 'interval', NULL);
        n := n + 1;
    END LOOP;
    RETURN n;
END$$;

-- Named versions forever; the rest kept interval_version_days, then the last of each day until daily_version_days.
CREATE OR REPLACE FUNCTION bd_prune_versions() RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE n integer;
BEGIN
    PERFORM bd_require_worker();
    DELETE FROM board_versions v
     WHERE v.reason <> 'manual'
       AND v.created_at < now() - make_interval(days => bd_setting_int('interval_version_days')::int)
       AND (v.created_at < now() - make_interval(days => bd_setting_int('daily_version_days')::int)
            OR EXISTS (SELECT 1 FROM board_versions w WHERE w.board_id = v.board_id AND w.reason <> 'manual'
                         AND w.created_at::date = v.created_at::date AND w.created_at > v.created_at));
    GET DIAGNOSTICS n = ROW_COUNT;
    RETURN n;
END$$;

-- Ops older than op_retention_days go; a board whose newest version predates the cut gets a compaction version first.
CREATE OR REPLACE FUNCTION bd_compact_ops() RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE v_cut timestamptz := now() - make_interval(days => bd_setting_int('op_retention_days')::int); b uuid; n integer;
BEGIN
    PERFORM bd_require_worker();
    FOR b IN SELECT DISTINCT o.board_id FROM board_ops o WHERE o.at < v_cut LOOP
        IF NOT EXISTS (SELECT 1 FROM board_versions v WHERE v.board_id = b
                         AND v.content_rev >= (SELECT max(o.seq) FROM board_ops o WHERE o.board_id = b AND o.at < v_cut)) THEN
            PERFORM bd_save_version(b, NULL, 'compaction', NULL);
        END IF;
    END LOOP;
    DELETE FROM board_ops WHERE at < v_cut;
    GET DIAGNOSTICS n = ROW_COUNT;
    RETURN n;
END$$;

-- Items deleted longer ago than op_retention_days are gone for good (a version restore re-creates them). Answers the files.
CREATE OR REPLACE FUNCTION bd_purge_deleted_items() RETURNS text[]
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE v_ids uuid[]; v_paths text[];
BEGIN
    PERFORM bd_require_worker();
    v_ids := ARRAY(SELECT id FROM board_items WHERE deleted_at < now() - make_interval(days => bd_setting_int('op_retention_days')::int));
    SELECT COALESCE(array_agg(p), '{}') INTO v_paths
      FROM (SELECT a.storage_path AS p FROM attachments a WHERE a.record_type = 'item' AND a.record_uuid = ANY (v_ids)
            UNION ALL SELECT t.value FROM attachments a, jsonb_each_text(a.thumbnails) t WHERE a.record_type = 'item' AND a.record_uuid = ANY (v_ids)) x;
    DELETE FROM attachments WHERE record_type = 'item' AND record_uuid = ANY (v_ids);
    DELETE FROM board_items WHERE id = ANY (v_ids);
    RETURN v_paths;
END$$;

-- Boards in the trash longer than trash_days.
CREATE OR REPLACE FUNCTION bd_trash_due() RETURNS SETOF uuid
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT id FROM boards WHERE deleted_at < now() - make_interval(days => bd_setting_int('trash_days')::int);
$$;

DO $$
DECLARE f text;
BEGIN
    FOREACH f IN ARRAY ARRAY[
        'bd_require_level(uuid, bigint, text)', 'bd_board_create(bigint, text, text, bigint)', 'bd_board_update(uuid, bigint, jsonb)',
        'bd_board_lock(uuid, bigint, boolean)', 'bd_board_share(uuid, bigint, text, bigint, text)', 'bd_board_access_set(uuid, bigint, text)',
        'bd_board_transfer(uuid, bigint, bigint)', 'bd_board_delete(uuid, bigint)', 'bd_board_restore(uuid, bigint)', 'bd_board_purge(uuid)',
        'bd_folder_add(bigint, bigint, uuid)', 'bd_board_snapshot(uuid)', 'bd_save_version(uuid, bigint, text, text)',
        'bd_restore_version(uuid, bigint, integer, uuid)', 'bd_board_duplicate(uuid, bigint, text, text, text)',
        'bd_notify(bigint, text, text, text, uuid, text, uuid, bigint)', 'bd_comment_create(uuid, bigint, jsonb, uuid, float8, float8, uuid, uuid[], bigint)',
        'bd_comment_resolve(uuid, bigint, boolean)', 'bd_comment_delete(uuid, bigint)', 'bd_comment_edit(uuid, bigint, jsonb)',
        'bd_interval_versions()', 'bd_prune_versions()', 'bd_compact_ops()', 'bd_purge_deleted_items()', 'bd_trash_due()'] LOOP
        EXECUTE format('REVOKE ALL ON FUNCTION %s FROM PUBLIC', f);
        EXECUTE format('GRANT EXECUTE ON FUNCTION %s TO boards_rw', f);
    END LOOP;
END$$;

COMMIT;
