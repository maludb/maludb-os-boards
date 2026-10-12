-- 019: PHASE 4 — what the two read servers need beyond db/012–013 (docs/build-specs/mcp-servers.md). Additive.
--  * bd_log_mcp_read() — the ONE write the read servers make: `share.read`, when the KERNEL's token asks one of the two K7 shares (boards_index,
--    board_outline) for a sibling application. The records role cannot write the trail (db/002), so a narrow SECURITY DEFINER function does; it
--    refuses any other action, and refuses a call that has a member (a share read is the kernel's: no person logs one). Source `application`, no
--    actor; the payload is the consumer, the tool, the argument KEYS, the rows answered and the agent it ran as — never a title, a word searched
--    or an answer (the writers' rule of db/002).
--  * the activity role reads mcp_folders (record_history on a folder applies the folder's own wall: the owner's, or a team folder for an insider).
BEGIN;

CREATE OR REPLACE FUNCTION bd_log_mcp_read(p_action text, p_after jsonb, p_board uuid DEFAULT NULL, p_request_id text DEFAULT NULL) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF p_action <> 'share.read' THEN
        RAISE EXCEPTION 'Not an action the read servers log: %', p_action USING ERRCODE = 'check_violation';
    END IF;
    IF app_current_member_id() IS NOT NULL THEN
        RAISE EXCEPTION 'A share read is the kernel''s: no member logs one' USING ERRCODE = 'insufficient_privilege';
    END IF;
    INSERT INTO activity_log (actor_member_id, source, action, entity_type, after, kernel_request_id, board_id)
    VALUES (NULL, 'application', 'share.read', 'share', p_after, left(p_request_id, 80), p_board);
END$$;
REVOKE ALL ON FUNCTION bd_log_mcp_read(text, jsonb, uuid, text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION bd_log_mcp_read(text, jsonb, uuid, text) TO boards_records_ro, boards_rw;

GRANT SELECT ON mcp_folders TO boards_activity_ro;

--  * mcp_activity_log gains `before` (appended last, as the additive rule says): record_history answers {at, member_name, action, before, after}. The writers' rule of db/002 holds — a payload carries ids, names,
--    counts, kinds and levels, never a comment's body, a token or a path; an item's text only on item.text, cut to 200 characters. The wall is unchanged: a board's rows only for a caller who can open the board.
CREATE OR REPLACE VIEW mcp_activity_log WITH (security_barrier = true) AS
SELECT l.id AS log_id, l.occurred_at, l.actor_member_id, l.source, l.action, l.screen, l.entity_type, l.entity_id, l.after,
       l.request_id, l.agent_run_id, l.department_id, l.token_id, l.board_id, l.before
  FROM activity_log l
 WHERE (SELECT bd_is_member_here())
   AND (l.board_id IS NULL OR l.board_id IN (SELECT v.board_id FROM bd_my_board_levels() v));

--  * bd_outline_of() (db/013) wrote ` — on ""` after an open comment pinned to a POINT of the canvas (no item: the LEFT JOIN gave an empty label, not NULL) — found by the Phase 4 proof of the board_outline
--    share. The anchor is named only when there is one. The same renderer writes the live outline, a version's and the share.
CREATE OR REPLACE FUNCTION bd_outline_of(p_title text, p_items jsonb, p_comments jsonb DEFAULT '[]') RETURNS text
    LANGUAGE sql IMMUTABLE AS $$
    WITH it AS (SELECT e AS j, (e->>'id') AS id, e->>'type' AS type, NULLIF(e->>'parent_id', '') AS parent,
                       (e->>'x')::float8 AS x, (e->>'y')::float8 AS y FROM jsonb_array_elements(COALESCE(p_items, '[]')) e)
    SELECT '# ' || COALESCE(p_title, '') || E'\n'
        || COALESCE((SELECT string_agg(E'\n## ' || COALESCE(NULLIF(f.j->'data'->>'title', ''), 'Untitled frame') || E'\n'
                          || COALESCE((SELECT string_agg(bd_outline_line(c.j), E'\n' ORDER BY c.y, c.x) FROM it c
                                        WHERE c.parent = f.id AND c.type <> 'connector'), '(empty)'), E'\n' ORDER BY f.y, f.x)
                      FROM it f WHERE f.type = 'frame'), '')
        || COALESCE((SELECT E'\n\n## On the canvas\n' || string_agg(bd_outline_line(c.j), E'\n' ORDER BY c.y, c.x)
                      FROM it c WHERE c.parent IS NULL AND c.type NOT IN ('frame', 'connector') HAVING count(*) > 0), '')
        || COALESCE((SELECT E'\n\n## Connections\n' || string_agg('- ' || COALESCE(NULLIF(left(bd_item_words(s.j, 60), 60), ''), s.type, 'a free point')
                            || ' → ' || COALESCE(NULLIF(left(bd_item_words(t.j, 60), 60), ''), t.type, 'a free point')
                            || COALESCE(' (' || NULLIF(COALESCE(k.j->'data'->'captions'->0->>'content', k.j->'data'->>'caption'), '') || ')', ''),
                            E'\n' ORDER BY k.y, k.x)
                      FROM it k LEFT JOIN it s ON s.id = k.j->>'start_item_id' LEFT JOIN it t ON t.id = k.j->>'end_item_id'
                     WHERE k.type = 'connector' HAVING count(*) > 0), '')
        || COALESCE((SELECT E'\n\n## Open comments\n' || string_agg('- **' || COALESCE(c->>'author', 'someone') || '**: ' || COALESCE(c->>'text', '')
                            || CASE WHEN NULLIF(c->>'on', '') IS NOT NULL THEN ' — on "' || (c->>'on') || '"' ELSE '' END, E'\n' ORDER BY ord)
                      FROM jsonb_array_elements(COALESCE(p_comments, '[]')) WITH ORDINALITY x(c, ord) HAVING count(*) > 0), '')
        || E'\n';
$$;

COMMIT;
