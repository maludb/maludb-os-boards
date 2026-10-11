-- Slice 8 review (templates-export.md, decision taken by the planning model): a SHIPPED template is `view` for everyone — a Boards admin
-- included, who held `full` on every board through db/006's wall. A shipped template never changes (db/015 keeps interval versions off it;
-- db/016 seeds it); only a copy is edited. db/006's two level functions with one more term in their LEAST(); everything else as it was
-- (CREATE OR REPLACE keeps their grants). Additive: nothing is dropped.
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
                    bd_level_rank(bd_level_cap(m.id)),
                    CASE WHEN b.is_template AND b.template_scope = 'shipped' THEN 1 ELSE 4 END))   -- a shipped template: view, for everyone
           END
      INTO r
      FROM members m LEFT JOIN boards b ON b.id = p_board_id
     WHERE m.id = p_member_id;
    RETURN COALESCE(r, 'none');
END$$;

-- The same rule for every board at once (one statement).
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
                              COALESCE(perm.r, 0)), me.cap,
                         CASE WHEN b.is_template AND b.template_scope = 'shipped' THEN 1 ELSE 4 END) AS r   -- a shipped template: view, for everyone
          FROM me CROSS JOIN boards b LEFT JOIN perm ON perm.board_id = b.id
         WHERE me.ok)
    SELECT lv.board_id, bd_level_of_rank(lv.r) FROM lv WHERE lv.r > 0;
$$;
