-- 013: THE PHASE 1 READS — the SQL behind the records tools that are more than a view (docs/boards-mcp-tool-surface.md): the board's
-- overview (always an agent's first read), the outline (live and as a version had it — also the K7 share board_outline), the SVG
-- dialect (board_read_region, frame_read, and the SVG export door: ONE renderer), and the admin's usage view. Every function answers as
-- the caller (app.member_id) and answers NULL for a board the caller may not see — the same nothing as a board that does not exist.
-- Additive.
BEGIN;

-- ---------------------------------------------------------------------------------------------
-- Small helpers
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION bd_xml(p text) RETURNS text
    LANGUAGE sql IMMUTABLE AS $$
    SELECT replace(replace(replace(replace(replace(COALESCE(p, ''), '&', '&amp;'), '<', '&lt;'), '>', '&gt;'), '"', '&quot;'), '''', '&apos;');
$$;
CREATE OR REPLACE FUNCTION bd_num(p float8) RETURNS text
    LANGUAGE sql IMMUTABLE AS $$ SELECT trim_scale(round(COALESCE(p, 0)::numeric, 1))::text; $$;
CREATE OR REPLACE FUNCTION bd_box_json(b box) RETURNS jsonb
    LANGUAGE sql IMMUTABLE AS $$ SELECT jsonb_build_array(round(((b[1])[0])::numeric, 1), round(((b[1])[1])::numeric, 1), round(((b[0])[0])::numeric, 1), round(((b[0])[1])::numeric, 1)); $$;
-- An item's words for a line or a label: its text, else its title, else its type.
CREATE OR REPLACE FUNCTION bd_item_words(p_item jsonb, p_max integer DEFAULT 500) RETURNS text
    LANGUAGE sql IMMUTABLE AS $$
    SELECT CASE WHEN length(w) > p_max THEN left(w, p_max - 1) || '…' ELSE w END
      FROM (SELECT COALESCE(NULLIF(bd_rich_text_plain(p_item->'data'->'content'), ''), NULLIF(p_item->'data'->>'title', ''), '') AS w) x;
$$;

-- ---------------------------------------------------------------------------------------------
-- The SVG dialect (normative in docs/boards-mcp-tool-surface.md). x/y are SVG's top-left, relative to (p_dx, p_dy) — a frame's top-left
-- inside its group, 0 at the top level of a region; data-cx/data-cy are ALWAYS the absolute centre, which every action takes.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION bd_svg_item(i board_items, p_dx float8, p_dy float8) RETURNS text
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE cx float8 := ((i.bbox[0])[0] + (i.bbox[1])[0]) / 2; cy float8 := ((i.bbox[0])[1] + (i.bbox[1])[1]) / 2;
        sx float8; sy float8; ex float8; ey float8; b box; common text; words text;
BEGIN
    IF i.type = 'connector' THEN
        SELECT bbox INTO b FROM board_items WHERE id = i.start_item_id AND deleted_at IS NULL;
        IF b IS NOT NULL THEN sx := ((b[0])[0] + (b[1])[0]) / 2; sy := ((b[0])[1] + (b[1])[1]) / 2;
        ELSE sx := (i.data->'startPoint'->>'x')::float8; sy := (i.data->'startPoint'->>'y')::float8; END IF;
        b := NULL;
        SELECT bbox INTO b FROM board_items WHERE id = i.end_item_id AND deleted_at IS NULL;
        IF b IS NOT NULL THEN ex := ((b[0])[0] + (b[1])[0]) / 2; ey := ((b[0])[1] + (b[1])[1]) / 2;
        ELSE ex := (i.data->'endPoint'->>'x')::float8; ey := (i.data->'endPoint'->>'y')::float8; END IF;
        RETURN format('<path data-id="%s" data-type="connector" data-start="%s" data-end="%s" data-shape="%s"%s d="M %s %s L %s %s"/>',
                      i.id, COALESCE(i.start_item_id::text, ''), COALESCE(i.end_item_id::text, ''), bd_xml(COALESCE(i.data->>'shape', 'straight')),
                      CASE WHEN COALESCE(i.data->'captions'->0->>'content', i.data->>'caption', '') <> ''
                           THEN format(' data-caption="%s"', bd_xml(COALESCE(i.data->'captions'->0->>'content', i.data->>'caption'))) ELSE '' END,
                      bd_num(COALESCE(sx, cx) - p_dx), bd_num(COALESCE(sy, cy) - p_dy), bd_num(COALESCE(ex, cx) - p_dx), bd_num(COALESCE(ey, cy) - p_dy));
    END IF;
    common := format(' data-z="%s" data-cx="%s" data-cy="%s"%s%s', bd_xml(i.z), bd_num(cx), bd_num(cy),
                     CASE WHEN i.locked THEN ' data-locked="true"' ELSE '' END,
                     CASE WHEN i.rotation <> 0 THEN format(' data-rotation="%s"', bd_num(i.rotation)) ELSE '' END);
    IF i.type IN ('image', 'document') THEN
        RETURN format('<image data-id="%s" data-type="%s"%s data-title="%s" x="%s" y="%s" width="%s" height="%s"/>',
                      i.id, i.type, common, bd_xml(i.data->>'title'), bd_num(cx - i.width / 2 - p_dx), bd_num(cy - i.height / 2 - p_dy),
                      bd_num(i.width), bd_num(i.height));
    ELSIF i.type = 'drawing' THEN
        RETURN format('<rect data-id="%s" data-type="drawing"%s data-points="%s" data-tool="%s" x="%s" y="%s" width="%s" height="%s"/>',
                      i.id, common, CASE WHEN jsonb_typeof(i.data->'points') = 'array' THEN jsonb_array_length(i.data->'points') / 2 ELSE 0 END,
                      bd_xml(COALESCE(i.data->>'tool', 'pen')), bd_num(cx - i.width / 2 - p_dx), bd_num(cy - i.height / 2 - p_dy),
                      bd_num(i.width), bd_num(i.height));
    END IF;
    words := bd_item_words(bd_item_json(i), 500);
    RETURN format('<foreignObject data-id="%s" data-type="%s"%s%s%s x="%s" y="%s" width="%s" height="%s">%s</foreignObject>',
                  i.id, i.type, CASE WHEN i.type = 'shape' THEN format(' data-shape="%s"', bd_xml(COALESCE(i.data->>'shape', 'rectangle'))) ELSE '' END,
                  common, CASE WHEN COALESCE(i.style->>'fillColor', '') <> '' THEN format(' data-color="%s"', bd_xml(i.style->>'fillColor')) ELSE '' END,
                  bd_num(cx - i.width / 2 - p_dx), bd_num(cy - i.height / 2 - p_dy), bd_num(i.width), bd_num(i.height), bd_xml(words));
END$$;

-- A region (a box) or a frame (p_frame: its children and the connectors between them, frame-relative). Answers
-- {items, total, truncated, svg}; NULL for a board the caller may not see. Frames first, then by z; at most p_limit (≤ 400).
CREATE OR REPLACE FUNCTION bd_region_svg(p_board_id uuid, p_x1 float8, p_y1 float8, p_x2 float8, p_y2 float8,
                                         p_limit integer DEFAULT 400, p_frame_id uuid DEFAULT NULL)
    RETURNS jsonb LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE v_lim integer := greatest(1, least(COALESCE(p_limit, 400), 400)); v_box box; v_frame board_items%ROWTYPE; v_ids uuid[]; v_total integer;
        v_dx float8 := 0; v_dy float8 := 0; v_body text := ''; f board_items%ROWTYPE; v_head text;
BEGIN
    IF bd_level_rank(bd_my_level(p_board_id)) = 0 OR NOT EXISTS (SELECT 1 FROM boards WHERE id = p_board_id AND deleted_at IS NULL) THEN
        RETURN NULL;
    END IF;
    IF p_frame_id IS NOT NULL THEN
        SELECT * INTO v_frame FROM board_items WHERE id = p_frame_id AND board_id = p_board_id AND type = 'frame' AND deleted_at IS NULL;
        IF NOT FOUND THEN RETURN NULL; END IF;
        v_box := v_frame.bbox; v_dx := (v_frame.bbox[1])[0]; v_dy := (v_frame.bbox[1])[1];
        v_ids := ARRAY(SELECT c.id FROM board_items c WHERE c.parent_id = p_frame_id AND c.deleted_at IS NULL
                       UNION ALL
                       SELECT k.id FROM board_items k WHERE k.board_id = p_board_id AND k.type = 'connector' AND k.deleted_at IS NULL
                          AND k.start_item_id IN (SELECT id FROM board_items WHERE parent_id = p_frame_id AND deleted_at IS NULL)
                          AND k.end_item_id IN (SELECT id FROM board_items WHERE parent_id = p_frame_id AND deleted_at IS NULL));
    ELSE
        v_box := box(point(p_x1, p_y1), point(p_x2, p_y2));
        v_ids := ARRAY(SELECT i.id FROM board_items i WHERE i.board_id = p_board_id AND i.deleted_at IS NULL AND i.bbox && v_box);
    END IF;
    v_total := cardinality(v_ids);
    v_ids := ARRAY(SELECT i.id FROM board_items i WHERE i.id = ANY (v_ids) ORDER BY (i.type = 'frame') DESC, i.z, i.id LIMIT v_lim);
    -- a frame in the set is a group holding its children that are in the set, frame-relative
    FOR f IN SELECT * FROM board_items WHERE id = ANY (v_ids) AND type = 'frame' ORDER BY z, id LOOP
        v_body := v_body || format('<g data-id="%s" data-type="frame" data-title="%s" data-z="%s" data-cx="%s" data-cy="%s" data-box="%s %s %s %s" transform="translate(%s %s)"><rect x="0" y="0" width="%s" height="%s"/>',
                    f.id, bd_xml(f.data->>'title'), bd_xml(f.z), bd_num(f.x), bd_num(f.y),
                    bd_num((f.bbox[1])[0]), bd_num((f.bbox[1])[1]), bd_num((f.bbox[0])[0]), bd_num((f.bbox[0])[1]),
                    bd_num((f.bbox[1])[0] - v_dx), bd_num((f.bbox[1])[1] - v_dy), bd_num(f.width), bd_num(f.height))
               || COALESCE((SELECT string_agg(bd_svg_item(c, (f.bbox[1])[0], (f.bbox[1])[1]), '' ORDER BY c.z, c.id)
                              FROM board_items c WHERE c.id = ANY (v_ids) AND c.parent_id = f.id), '')
               || '</g>';
    END LOOP;
    -- everything else at the top level (in frame mode: the frame's children, relative to it)
    v_body := v_body || COALESCE((SELECT string_agg(bd_svg_item(i, v_dx, v_dy), '' ORDER BY i.z, i.id) FROM board_items i
                                   WHERE i.id = ANY (v_ids) AND i.type <> 'frame' AND (i.parent_id IS NULL OR NOT (i.parent_id = ANY (v_ids)))), '');
    v_head := format('<svg xmlns="http://www.w3.org/2000/svg" data-board="%s"%s data-box="%s %s %s %s" data-items="%s" data-truncated="%s" viewBox="%s %s %s %s">',
                     p_board_id, CASE WHEN p_frame_id IS NOT NULL THEN format(' data-frame="%s"', p_frame_id) ELSE '' END,
                     bd_num((v_box[1])[0]), bd_num((v_box[1])[1]), bd_num((v_box[0])[0]), bd_num((v_box[0])[1]),
                     cardinality(v_ids), (v_total > cardinality(v_ids))::text,
                     bd_num((v_box[1])[0] - v_dx), bd_num((v_box[1])[1] - v_dy),
                     bd_num((v_box[0])[0] - (v_box[1])[0]), bd_num((v_box[0])[1] - (v_box[1])[1]));
    RETURN jsonb_build_object('items', cardinality(v_ids), 'total', v_total, 'truncated', v_total > cardinality(v_ids),
                              'svg', v_head || v_body || '</svg>');
END$$;

-- ---------------------------------------------------------------------------------------------
-- The overview: an agent's first read of a board (frames, unframed clusters in 1,000-unit cells by centre, totals, the budget, the last change,
-- who worked on it in the last hour). At most 200 frames and 200 clusters, top to bottom, left to right.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION bd_board_overview(p_board_id uuid) RETURNS jsonb
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE v_level text := bd_my_level(p_board_id); b boards%ROWTYPE; v_frames jsonb; v_clusters jsonb;
BEGIN
    SELECT * INTO b FROM boards WHERE id = p_board_id AND deleted_at IS NULL;
    IF NOT FOUND OR bd_level_rank(v_level) = 0 THEN RETURN NULL; END IF;
    -- frames, top to bottom then left to right
    SELECT COALESCE(jsonb_agg(jsonb_build_object(
             'item_id', f.id, 'title', f.data->>'title', 'box', bd_box_json(f.bbox),
             'counts_by_type', COALESCE((SELECT jsonb_object_agg(t.type, t.n) FROM (SELECT c.type, count(*) AS n FROM board_items c
                                           WHERE c.parent_id = f.id AND c.deleted_at IS NULL GROUP BY c.type) t), '{}'),
             'sample', COALESCE((SELECT jsonb_agg(left(s.plain_text, 80)) FROM (SELECT c.plain_text FROM board_items c
                                   WHERE c.parent_id = f.id AND c.deleted_at IS NULL AND c.plain_text <> ''
                                   ORDER BY (c.bbox[1])[1], (c.bbox[1])[0] LIMIT 3) s), '[]'))
           ORDER BY (f.bbox[1])[1], (f.bbox[1])[0]), '[]')
      INTO v_frames
      FROM (SELECT * FROM board_items WHERE board_id = b.id AND type = 'frame' AND deleted_at IS NULL
             ORDER BY (bbox[1])[1], (bbox[1])[0] LIMIT 200) f;
    -- the unframed items, in 1,000-unit cells by their centre
    WITH cells AS (
        SELECT floor(((i.bbox[0])[1] + (i.bbox[1])[1]) / 2000) AS gy, floor(((i.bbox[0])[0] + (i.bbox[1])[0]) / 2000) AS gx,
               i.type, i.bbox, i.plain_text
          FROM board_items i
         WHERE i.board_id = b.id AND i.deleted_at IS NULL AND i.parent_id IS NULL AND i.type NOT IN ('frame', 'connector')),
    g AS (
        SELECT gy, gx, box(point(min((bbox[1])[0]), min((bbox[1])[1])), point(max((bbox[0])[0]), max((bbox[0])[1]))) AS bx, count(*) AS n,
               to_jsonb((array_agg(left(plain_text, 80) ORDER BY (bbox[1])[1], (bbox[1])[0]) FILTER (WHERE plain_text <> ''))[1:3]) AS sample
          FROM cells GROUP BY gy, gx),
    t AS (
        SELECT gy, gx, jsonb_object_agg(type, n) AS types FROM (SELECT gy, gx, type, count(*) AS n FROM cells GROUP BY 1, 2, 3) z GROUP BY gy, gx)
    SELECT COALESCE(jsonb_agg(jsonb_build_object('box', bd_box_json(g.bx), 'count', g.n, 'counts_by_type', t.types,
                                                 'sample', COALESCE(g.sample, '[]')) ORDER BY g.gy, g.gx), '[]')
      INTO v_clusters
      FROM (SELECT * FROM g ORDER BY gy, gx LIMIT 200) g JOIN t USING (gy, gx);
    RETURN jsonb_build_object(
      'board_id', b.id, 'title', b.title, 'my_level', v_level, 'item_count', b.item_count,
      'soft_budget', bd_setting_int('item_soft_budget'), 'hard_cap', bd_setting_int('item_hard_cap'),
      'extent', (SELECT bd_box_json(box(point(min((i.bbox[1])[0]), min((i.bbox[1])[1])), point(max((i.bbox[0])[0]), max((i.bbox[0])[1]))))
                   FROM board_items i WHERE i.board_id = b.id AND i.deleted_at IS NULL HAVING count(*) > 0),
      'frames', v_frames,
      'clusters', v_clusters,
      'totals_by_type', COALESCE((SELECT jsonb_object_agg(t.type, t.n) FROM (SELECT i.type, count(*) AS n FROM board_items i
                                    WHERE i.board_id = b.id AND i.deleted_at IS NULL GROUP BY i.type) t), '{}'),
      'last_change', (SELECT jsonb_build_object('seq', o.seq, 'member_name', m.display_name, 'member_kind', m.member_kind, 'source', o.source, 'at', o.at)
                        FROM board_ops o LEFT JOIN members m ON m.id = o.member_id WHERE o.board_id = b.id ORDER BY o.seq DESC LIMIT 1),
      'active_last_hour', COALESCE((SELECT jsonb_agg(DISTINCT m.display_name) FROM board_ops o JOIN members m ON m.id = o.member_id
                                      WHERE o.board_id = b.id AND o.at > now() - interval '1 hour'), '[]'));
END$$;

-- ---------------------------------------------------------------------------------------------
-- The outline: a board as Markdown (the 375 px reader, the screen reader, an agent's summary, the K7 share board_outline). Frames by
-- position (top to bottom, left to right), each a heading with its items in reading order; the unframed items; the connections; the
-- open comments. One renderer for the live board and a version (bd_outline_of over item JSON in bd_item_json's shape).
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION bd_outline_line(p_item jsonb) RETURNS text
    LANGUAGE sql IMMUTABLE AS $$
    SELECT '- [' || CASE p_item->>'type'
                      WHEN 'sticky_note' THEN 'sticky' || COALESCE(', ' || NULLIF(p_item->'style'->>'fillColor', ''), '')
                      WHEN 'shape' THEN 'shape: ' || COALESCE(p_item->'data'->>'shape', 'rectangle')
                      WHEN 'document' THEN 'file'
                      ELSE p_item->>'type' END || ']'
        || CASE WHEN bd_item_words(p_item, 500) <> '' THEN ' ' || replace(bd_item_words(p_item, 500), E'\n', ' / ') ELSE '' END;
$$;

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
                            || CASE WHEN c->>'on' IS NOT NULL THEN ' — on "' || (c->>'on') || '"' ELSE '' END, E'\n' ORDER BY ord)
                      FROM jsonb_array_elements(COALESCE(p_comments, '[]')) WITH ORDINALITY x(c, ord) HAVING count(*) > 0), '')
        || E'\n';
$$;

CREATE OR REPLACE FUNCTION bd_board_outline(p_board_id uuid) RETURNS text
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE b boards%ROWTYPE;
BEGIN
    SELECT * INTO b FROM boards WHERE id = p_board_id AND deleted_at IS NULL;
    IF NOT FOUND OR bd_level_rank(bd_my_level(p_board_id)) = 0 THEN RETURN NULL; END IF;
    RETURN bd_outline_of(b.title,
             (SELECT COALESCE(jsonb_agg(bd_item_json(i)), '[]') FROM board_items i WHERE i.board_id = b.id AND i.deleted_at IS NULL),
             (SELECT COALESCE(jsonb_agg(jsonb_build_object('author', m.display_name, 'text', left(c.plain_text, 200),
                                                           'on', left(bd_item_words(bd_item_json(i), 60), 60)) ORDER BY c.created_at), '[]')
                FROM comments c LEFT JOIN members m ON m.id = c.author_member_id LEFT JOIN board_items i ON i.id = c.item_id
               WHERE c.board_id = b.id AND c.parent_comment_id IS NULL AND c.resolved_at IS NULL AND c.deleted_at IS NULL));
END$$;

CREATE OR REPLACE FUNCTION bd_version_outline(p_board_id uuid, p_version_no integer) RETURNS text
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE v jsonb;
BEGIN
    IF bd_level_rank(bd_my_level(p_board_id)) = 0 THEN RETURN NULL; END IF;
    SELECT snapshot INTO v FROM board_versions WHERE board_id = p_board_id AND version_no = p_version_no;
    IF v IS NULL THEN RETURN NULL; END IF;
    RETURN bd_outline_of(v->>'title', v->'items', '[]');
END$$;

-- ---------------------------------------------------------------------------------------------
-- The admin's usage (Q16): every board against the budget, idle ones, ones whose owner has left. Shipped templates are not boards anyone
-- works on and are left out.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE VIEW mcp_boards_usage WITH (security_barrier = true) AS
SELECT b.id AS board_id, b.title, b.owner_member_id, o.display_name AS owner_name,
       (o.status = 'active' AND o.capability IS NOT NULL) AS owner_active, b.is_template, b.item_count,
       b.item_count > s.item_soft_budget AS over_soft, (now()::date - b.last_activity_at::date) AS idle_days,
       (SELECT count(*) FROM comments c WHERE c.board_id = b.id AND c.parent_comment_id IS NULL AND c.resolved_at IS NULL AND c.deleted_at IS NULL) AS open_threads,
       b.last_activity_at, b.created_at
  FROM boards b
  CROSS JOIN bd_settings s
  LEFT JOIN members o ON o.id = b.owner_member_id
 WHERE b.deleted_at IS NULL AND NOT (b.is_template AND b.template_scope = 'shipped')
   AND (SELECT bd_is_boards_admin(app_current_member_id()));

REVOKE ALL ON FUNCTION bd_svg_item(board_items, float8, float8), bd_region_svg(uuid, float8, float8, float8, float8, integer, uuid),
                       bd_board_overview(uuid), bd_board_outline(uuid), bd_version_outline(uuid, integer) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION bd_svg_item(board_items, float8, float8), bd_region_svg(uuid, float8, float8, float8, float8, integer, uuid),
                          bd_board_overview(uuid), bd_board_outline(uuid), bd_version_outline(uuid, integer)
    TO boards_rw, boards_records_ro;
GRANT SELECT ON mcp_boards_usage TO boards_records_ro, boards_rw;

COMMIT;
