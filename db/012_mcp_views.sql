-- 012: WHO SEES WHAT through the read servers — the mcp_* views, the only thing the two read roles see (memory.md §1; design §3,
-- §7). Every WHERE clause IS the row rule; the servers never filter in Python. The caller's cheap facts are scalar subqueries tested
-- once per statement; the per-board wall is bd_my_board_levels(), joined once per statement (THE SCALE RULE — boards are few, items
-- are many). Primary keys re-aliased <entity>_id. NEVER selected: an attachment's storage_path, a token's hash, an outbox body,
-- an op's inverse.
--
-- The kit's two functions the MCP servers call (mcp/db.py, mcp/server_common.py) are here as in every sibling: mcp_admit_agent(),
-- mcp_member_kind().
BEGIN;

CREATE OR REPLACE FUNCTION mcp_admit_agent(p_member_id bigint) RETURNS boolean
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE n integer;
BEGIN
    UPDATE members SET capability = 'write', synced_at = now()
     WHERE id = p_member_id AND member_kind = 'agent' AND status = 'active' AND capability IS NULL;
    GET DIAGNOSTICS n = ROW_COUNT;
    RETURN n = 1;
END$$;
REVOKE ALL ON FUNCTION mcp_admit_agent(bigint) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION mcp_admit_agent(bigint) TO boards_records_ro, boards_activity_ro, boards_rw;

CREATE OR REPLACE FUNCTION mcp_member_kind(p_member_id bigint) RETURNS text
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT member_kind FROM members WHERE id = p_member_id AND status = 'active' AND capability IS NOT NULL;
$$;
REVOKE ALL ON FUNCTION mcp_member_kind(bigint) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION mcp_member_kind(bigint) TO boards_records_ro, boards_activity_ro, boards_rw;

-- ---------------------------------------------------------------------------------------------
-- The directory and the settings (the kit's shapes)
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE VIEW mcp_members WITH (security_barrier = true) AS
SELECT m.id AS member_id, m.display_name, m.member_kind, m.is_agent, m.business_role, m.is_external, m.status, m.capability, m.roles,
       bd_member_roles(m.id) AS roles_resolved, m.job_title, m.timezone,
       CASE WHEN NOT (SELECT m2.is_external FROM members m2 WHERE m2.id = app_current_member_id()) THEN m.email::text END AS email,
       (SELECT array_agg(dm.department_id) FROM department_members dm WHERE dm.member_id = m.id AND dm.left_at IS NULL) AS department_ids
  FROM members m
 WHERE m.status = 'active' AND m.capability IS NOT NULL AND (SELECT bd_is_member_here())
   AND (NOT (SELECT m2.is_external FROM members m2 WHERE m2.id = app_current_member_id()) OR m.id = app_current_member_id());

CREATE OR REPLACE VIEW mcp_departments WITH (security_barrier = true) AS
SELECT d.id AS department_id, d.name, d.description, d.parent_id, d.manager_member_id, d.is_system, d.system_key, d.archived_at
  FROM departments d WHERE (SELECT bd_is_member_here());

CREATE OR REPLACE VIEW mcp_settings WITH (security_barrier = true) AS
SELECT s.business_name, s.timezone, s.default_everyone_level, s.item_soft_budget, s.item_hard_cap, s.batch_max_ops, s.op_retention_days,
       s.interval_version_days, s.daily_version_days, s.trash_days, s.max_upload_mb, s.drawing_max_points, s.room_max_sockets,
       s.live_enabled, s.live_check_seconds, s.poll_seconds, s.updated_at
  FROM bd_settings s WHERE (SELECT bd_is_member_here());

-- ---------------------------------------------------------------------------------------------
-- Boards, folders, permissions, versions
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE VIEW mcp_boards WITH (security_barrier = true) AS
SELECT b.id AS board_id, b.title, b.description, b.owner_member_id, o.display_name AS owner_name, v.level AS my_level,
       b.is_template, b.template_scope, b.template_category, b.is_locked, b.item_count, b.last_seq, b.last_activity_at,
       b.start_view, b.background, b.duplicated_from, b.created_by, b.created_at, b.updated_at,
       EXISTS (SELECT 1 FROM board_favorites f WHERE f.board_id = b.id AND f.member_id = app_current_member_id()) AS is_favorite,
       (SELECT r.visited_at FROM board_recents r WHERE r.board_id = b.id AND r.member_id = app_current_member_id()) AS my_last_visit,
       (SELECT count(*) FROM comments c WHERE c.board_id = b.id AND c.parent_comment_id IS NULL AND c.resolved_at IS NULL AND c.deleted_at IS NULL) AS open_threads
  FROM boards b
  JOIN bd_my_board_levels() v ON v.board_id = b.id
  LEFT JOIN members o ON o.id = b.owner_member_id
 WHERE b.deleted_at IS NULL;

-- The trash: one's own deleted boards, every deleted board for a Boards admin.
CREATE OR REPLACE VIEW mcp_trash WITH (security_barrier = true) AS
SELECT b.id AS board_id, b.title, b.owner_member_id, b.item_count, b.deleted_at, b.deleted_by,
       b.deleted_at + make_interval(days => (SELECT s.trash_days FROM bd_settings s)) AS purge_after
  FROM boards b
 WHERE b.deleted_at IS NOT NULL
   AND (b.owner_member_id = (SELECT app_current_member_id()) OR (SELECT bd_is_boards_admin(app_current_member_id())));

CREATE OR REPLACE VIEW mcp_board_permissions WITH (security_barrier = true) AS
SELECT p.board_id, p.principal_kind, p.principal_id,
       CASE p.principal_kind WHEN 'department' THEN d.name WHEN 'everyone' THEN 'Everyone' ELSE m.display_name END AS principal_name,
       p.level, p.granted_by, p.created_at
  FROM board_permissions p
  JOIN bd_my_board_levels() v ON v.board_id = p.board_id
  LEFT JOIN members m ON p.principal_kind IN ('member', 'agent') AND m.id = p.principal_id
  LEFT JOIN departments d ON p.principal_kind = 'department' AND d.id = p.principal_id;

CREATE OR REPLACE VIEW mcp_folders WITH (security_barrier = true) AS
SELECT f.id AS folder_id, f.name, f.owner_member_id, f.is_team, f.position, f.created_at, f.updated_at,
       (SELECT count(*) FROM board_folder_entries e JOIN bd_my_board_levels() v ON v.board_id = e.board_id
          JOIN boards b ON b.id = e.board_id AND b.deleted_at IS NULL WHERE e.folder_id = f.id) AS board_count
  FROM board_folders f
 WHERE (SELECT bd_is_member_here())
   AND (f.owner_member_id = (SELECT app_current_member_id())
        OR (f.is_team AND NOT (SELECT m.is_external FROM members m WHERE m.id = app_current_member_id())));

CREATE OR REPLACE VIEW mcp_folder_entries WITH (security_barrier = true) AS
SELECT e.folder_id, e.board_id, e.position, e.added_by, e.created_at
  FROM board_folder_entries e
  JOIN mcp_folders f ON f.folder_id = e.folder_id
  JOIN bd_my_board_levels() v ON v.board_id = e.board_id;

CREATE OR REPLACE VIEW mcp_board_versions WITH (security_barrier = true) AS
SELECT bv.id AS version_id, bv.board_id, bv.version_no, bv.content_rev, bv.reason, bv.label, bv.item_count, bv.saved_by, bv.created_at
  FROM board_versions bv
  JOIN bd_my_board_levels() v ON v.board_id = bv.board_id;

-- ---------------------------------------------------------------------------------------------
-- Items and changes. bbox as four numbers (x1, y1 the top-left; x2, y2 the bottom-right).
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE VIEW mcp_board_items WITH (security_barrier = true) AS
SELECT i.id AS item_id, i.board_id, i.type, i.parent_id, i.x, i.y, i.width, i.height, i.rotation, i.z, i.style, i.data,
       i.start_item_id, i.end_item_id, i.locked, i.plain_text,
       (i.bbox[1])[0] AS x1, (i.bbox[1])[1] AS y1, (i.bbox[0])[0] AS x2, (i.bbox[0])[1] AS y2,
       i.version, i.last_seq, i.created_by, i.created_at, i.updated_by, i.updated_at, i.agent_run_id
  FROM board_items i
  JOIN bd_my_board_levels() v ON v.board_id = i.board_id
 WHERE i.deleted_at IS NULL;

CREATE OR REPLACE VIEW mcp_board_changes WITH (security_barrier = true) AS
SELECT o.seq, o.board_id, o.member_id, m.display_name AS member_name, m.member_kind, o.source, o.agent_run_id, o.request_id,
       o.summary, o.ops, o.undoes_seq, o.at
  FROM board_ops o
  JOIN bd_my_board_levels() v ON v.board_id = o.board_id
  LEFT JOIN members m ON m.id = o.member_id;

-- ---------------------------------------------------------------------------------------------
-- Comments, files, notifications, dispatches, search
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE VIEW mcp_comments WITH (security_barrier = true) AS
SELECT c.id AS comment_id, c.board_id, c.item_id, c.parent_comment_id, COALESCE(c.parent_comment_id, c.id) AS thread_id,
       c.author_member_id, a.display_name AS author_name, a.member_kind AS author_kind, c.body, c.plain_text,
       c.anchor_x, c.anchor_y, c.context_item_ids, c.resolved_at, c.resolved_by, c.edited_at, c.created_at, c.agent_run_id
  FROM comments c
  JOIN bd_my_board_levels() v ON v.board_id = c.board_id
  LEFT JOIN members a ON a.id = c.author_member_id
 WHERE c.deleted_at IS NULL;

CREATE OR REPLACE VIEW mcp_attachments WITH (security_barrier = true) AS
SELECT x.id AS attachment_id, x.board_id, x.record_type, x.record_uuid, x.filename, x.mime_type, x.byte_size, x.width, x.height,
       x.uploaded_by, x.created_at
  FROM attachments x
  JOIN bd_my_board_levels() v ON v.board_id = x.board_id;

CREATE OR REPLACE VIEW mcp_notifications WITH (security_barrier = true) AS
SELECT n.id AS notification_id, n.member_id, n.kind, n.title, n.body, n.board_id, n.record_type, n.record_uuid, n.actor_member_id,
       n.read_at, n.created_at
  FROM notifications n
 WHERE n.member_id = (SELECT app_current_member_id());

-- A Boards admin (agents.manage) sees every dispatch; anyone else those they asked for.
CREATE OR REPLACE VIEW mcp_agent_dispatches WITH (security_barrier = true) AS
SELECT d.id AS dispatch_id, d.board_id, d.record_uuid AS comment_id, d.agent_member_id, d.kind, d.via, d.acting_member_id, d.run_id,
       d.request_id, d.status, d.reply_excerpt, d.attempts, d.created_at, d.answered_at, d.conversation_id, d.reply_comment_id
  FROM agent_dispatches d
 WHERE (SELECT bd_has_right('agents.manage')) OR d.acting_member_id = (SELECT app_current_member_id());

CREATE OR REPLACE VIEW mcp_search WITH (security_barrier = true) AS
SELECT s.entity_kind, s.entity_uuid, s.board_id, s.item_type, s.author_member_id, s.title, left(s.body, 500) AS body, s.tsv,
       s.has_link, s.occurred_at
  FROM search_index s
  JOIN bd_my_board_levels() v ON v.board_id = s.board_id;

CREATE OR REPLACE VIEW mcp_activity_log WITH (security_barrier = true) AS
SELECT l.id AS log_id, l.occurred_at, l.actor_member_id, l.source, l.action, l.screen, l.entity_type, l.entity_id, l.after,
       l.request_id, l.agent_run_id, l.department_id, l.token_id, l.board_id
  FROM activity_log l
 WHERE (SELECT bd_is_member_here())
   AND (l.board_id IS NULL OR l.board_id IN (SELECT v.board_id FROM bd_my_board_levels() v));

GRANT SELECT ON mcp_members, mcp_departments, mcp_settings, mcp_boards, mcp_trash, mcp_board_permissions, mcp_folders, mcp_folder_entries,
                mcp_board_versions, mcp_board_items, mcp_board_changes, mcp_comments, mcp_attachments, mcp_notifications,
                mcp_agent_dispatches, mcp_search, mcp_activity_log
    TO boards_records_ro, boards_rw;
GRANT SELECT ON mcp_activity_log, mcp_members, mcp_departments, mcp_boards TO boards_activity_ro;

COMMIT;
