-- Phase 0 proof of the schema (docs/boards-design.md §10). Everything happens inside one transaction that is ROLLED BACK: fixtures written
-- as boards_rw (the web application), reads as the two read roles, acting members set through app.member_id exactly as PHP and the MCP
-- servers set it. Fixtures are synthetic (example.invalid). Run as postgres on a SCRATCH database — never the installed one — after loading
-- every migration in order (tests/phase0/schema.sh, or tests/phase0/run.sh after tests/setup_dev.sh):
--   sudo -n -u postgres psql -v ON_ERROR_STOP=1 -d bd_proof -f db/proof/phase0_proof.sql; echo "exit $?"
-- Prints one line per check (ok / FAIL) and the count; exits non-zero when any check failed.
\set QUIET on
\o /dev/null
BEGIN;
CREATE TEMP TABLE proof (n serial, ok boolean, label text);
GRANT ALL ON proof TO PUBLIC; GRANT ALL ON SEQUENCE proof_n_seq TO PUBLIC;
CREATE OR REPLACE FUNCTION pg_temp.check(b boolean, l text) RETURNS void LANGUAGE sql AS $$ INSERT INTO proof (ok, label) VALUES (COALESCE(b, false), l) $$;
CREATE OR REPLACE FUNCTION pg_temp.refused(p_sql text, p_like text, l text) RETURNS void LANGUAGE plpgsql AS $$
BEGIN
    BEGIN
        EXECUTE p_sql;
        INSERT INTO proof (ok, label) VALUES (false, l || ' (was NOT refused)');
    EXCEPTION WHEN OTHERS THEN
        INSERT INTO proof (ok, label) VALUES (SQLERRM ILIKE '%' || p_like || '%', l || CASE WHEN SQLERRM ILIKE '%' || p_like || '%' THEN '' ELSE ' (said: ' || SQLERRM || ')' END);
    END;
END$$;
CREATE OR REPLACE FUNCTION pg_temp.as_member(p bigint) RETURNS void LANGUAGE sql AS $$ SELECT set_config('app.member_id', COALESCE(p::text, ''), true) $$;
CREATE TEMP TABLE uids (k text PRIMARY KEY, u uuid);
GRANT ALL ON uids TO PUBLIC;
CREATE OR REPLACE FUNCTION pg_temp.u(k text) RETURNS uuid LANGUAGE sql STABLE AS $$ SELECT u FROM uids WHERE uids.k = $1 $$;
CREATE OR REPLACE FUNCTION pg_temp.keep(k text, v uuid) RETURNS uuid LANGUAGE sql AS $$ INSERT INTO uids (k, u) VALUES ($1, $2) ON CONFLICT (k) DO UPDATE SET u = EXCLUDED.u RETURNING u $$;
CREATE TEMP TABLE res (k text PRIMARY KEY, j jsonb);
GRANT ALL ON res TO PUBLIC;
CREATE OR REPLACE FUNCTION pg_temp.r(k text) RETURNS jsonb LANGUAGE sql STABLE AS $$ SELECT j FROM res WHERE res.k = $1 $$;
-- commit a batch as a member, keeping the answer under a name
CREATE OR REPLACE FUNCTION pg_temp.ops(p_key text, b uuid, m bigint, o jsonb, batch uuid DEFAULT NULL, src text DEFAULT 'web') RETURNS jsonb LANGUAGE plpgsql AS $$
DECLARE v jsonb;
BEGIN
    PERFORM pg_temp.as_member(m);
    v := bd_commit_ops(b, m, src, 'proof', batch, o);
    INSERT INTO res AS r (k, j) VALUES (p_key, v) ON CONFLICT (k) DO UPDATE SET j = EXCLUDED.j;
    RETURN v;
END$$;
CREATE OR REPLACE FUNCTION pg_temp.lvl(b uuid, m bigint) RETURNS text LANGUAGE sql STABLE AS $$ SELECT bd_board_level(b, m) $$;
CREATE OR REPLACE FUNCTION pg_temp.bx(i uuid) RETURNS box LANGUAGE sql STABLE SECURITY DEFINER AS $$ SELECT bbox FROM board_items WHERE id = i $$;
CREATE OR REPLACE FUNCTION pg_temp.it(i uuid) RETURNS board_items LANGUAGE sql STABLE SECURITY DEFINER AS $$ SELECT * FROM board_items WHERE id = i $$;
GRANT EXECUTE ON FUNCTION pg_temp.check(boolean, text), pg_temp.refused(text, text, text), pg_temp.as_member(bigint), pg_temp.u(text),
    pg_temp.keep(text, uuid), pg_temp.r(text), pg_temp.ops(text, uuid, bigint, jsonb, uuid, text), pg_temp.lvl(uuid, bigint),
    pg_temp.bx(uuid), pg_temp.it(uuid) TO PUBLIC;

-- ================================================================ fixtures: every actor of §2
SET ROLE boards_rw;
INSERT INTO members (id, member_kind, display_name, email, business_role, is_external, status, capability, roles) VALUES
 (1,  'human', 'SMOKE Owner',   'owner@example.invalid', 'super_admin', false, 'active', 'admin', '{admin}'),    -- the super-admin
 (26, 'human', 'SMOKE Priya',   'priya@example.invalid', 'user',        false, 'active', 'write', '{user}'),     -- a Member, Sales
 (27, 'human', 'SMOKE Marco',   'marco@example.invalid', 'user',        false, 'active', 'admin', '{admin}'),    -- a Boards admin (not super)
 (28, 'human', 'SMOKE Bea',     'bea@example.invalid',   'user',        false, 'active', 'write', '{}'),         -- write, no roles yet → Member
 (29, 'human', 'SMOKE Ann',     'ann@example.invalid',   'user',        true,  'active', 'read',  '{viewer}'),   -- an EXTERNAL Viewer
 (30, 'human', 'SMOKE Dana',    'dana@example.invalid',  'user',        false, 'active', 'read',  '{viewer}'),   -- an internal Viewer, Sales
 (31, 'human', 'SMOKE Lee',     'lee@example.invalid',   'user',        false, 'active', 'read',  '{}'),         -- read, no roles → Viewer
 (32, 'human', 'SMOKE Sam',     'sam@example.invalid',   'user',        false, 'active', NULL,    '{}'),         -- no Boards grant
 (33, 'human', 'SMOKE Kim',     'kim@example.invalid',   'user',        false, 'active', 'write', '{user}'),     -- a Member, Marketing
 (40, 'agent', 'SMOKE Boards Expert', NULL,              'user',        false, 'active', 'write', '{user}'),
 (41, 'agent', 'SMOKE Watcher',       NULL,              'user',        false, 'active', NULL,    '{}'),         -- an agent not admitted
 (42, 'agent', 'SMOKE Facilitator',   NULL,              'user',        false, 'active', 'write', '{user}');
INSERT INTO departments (id, name) VALUES (7, 'Sales'), (8, 'Marketing');
INSERT INTO department_members (member_id, department_id, is_primary) VALUES (26, 7, true), (30, 7, true), (33, 8, true), (28, 8, false);

-- ================================================================ 1. the wall
SELECT pg_temp.as_member(26);
SELECT pg_temp.keep('b1', bd_board_create(26, 'Q4 launch retro', 'SMOKE'));
SELECT pg_temp.check(pg_temp.lvl(pg_temp.u('b1'), 26) = 'full', 'the maker owns the board: full');
SELECT pg_temp.check(pg_temp.lvl(pg_temp.u('b1'), 28) = 'none' AND pg_temp.lvl(pg_temp.u('b1'), 30) = 'none' AND pg_temp.lvl(pg_temp.u('b1'), 40) = 'none',
                     'a new board is private (D3): a Member, a Viewer and an agent see nothing');
SELECT pg_temp.check(pg_temp.lvl(pg_temp.u('b1'), 27) = 'full' AND pg_temp.lvl(pg_temp.u('b1'), 1) = 'full', 'a Boards admin and the super-admin are full everywhere');
SELECT pg_temp.check(pg_temp.lvl(pg_temp.u('b1'), 32) = 'none', 'no Boards grant, nothing');
SELECT pg_temp.as_member(30);
SELECT pg_temp.refused($$SELECT bd_board_create(30, 'Mine')$$, 'not make new ones', 'a Viewer may not make a board');
SELECT pg_temp.as_member(31);
SELECT pg_temp.refused($$SELECT bd_board_create(31, 'Mine')$$, 'not make new ones', 'read with no roles reads as a Viewer — no board of their own');
SELECT pg_temp.as_member(28);
SELECT pg_temp.check(bd_board_create(28, 'Bea''s board') IS NOT NULL, 'write with no roles reads as a Member — may make a board');

SELECT pg_temp.as_member(26);
SELECT pg_temp.check((bd_board_share(pg_temp.u('b1'), 26, 'department', 7, 'edit')->>'level') = 'edit', 'the owner shares with Sales at edit');
SELECT pg_temp.check(pg_temp.lvl(pg_temp.u('b1'), 30) = 'comment', 'a Viewer in Sales is capped at comment (D2)');
SELECT pg_temp.check(pg_temp.lvl(pg_temp.u('b1'), 33) = 'none', 'Marketing is not Sales');
SELECT bd_board_access_set(pg_temp.u('b1'), 26, 'view');
SELECT pg_temp.check(pg_temp.lvl(pg_temp.u('b1'), 28) = 'view' AND pg_temp.lvl(pg_temp.u('b1'), 31) = 'view' AND pg_temp.lvl(pg_temp.u('b1'), 40) = 'view',
                     'everyone at view: a Member, a Viewer, an agent (D11: agents are members)');
SELECT pg_temp.check(pg_temp.lvl(pg_temp.u('b1'), 29) = 'none', 'everyone never includes an external (D3)');
SELECT pg_temp.check(pg_temp.lvl(pg_temp.u('b1'), 41) = 'none' AND pg_temp.lvl(pg_temp.u('b1'), 32) = 'none', 'nor anyone without a Boards grant');
SELECT pg_temp.check((bd_board_share(pg_temp.u('b1'), 26, 'member', 29, 'edit')->>'needs_grant')::boolean = false, 'an external is shared by name');
SELECT pg_temp.check(pg_temp.lvl(pg_temp.u('b1'), 29) = 'comment', '…and, a Viewer, is capped at comment');
SELECT pg_temp.check((bd_board_share(pg_temp.u('b1'), 26, 'member', 32, 'view')->>'needs_grant')::boolean, 'sharing with someone with no Boards grant says so');
SELECT bd_board_share(pg_temp.u('b1'), 26, 'agent', 42, 'edit');
SELECT pg_temp.check(pg_temp.lvl(pg_temp.u('b1'), 42) = 'edit', 'the Facilitator is shared at edit');
SELECT pg_temp.refused($$SELECT bd_board_share(pg_temp.u('b1'), 26, 'member', 42, 'edit')$$, 'no such person', 'an agent is never a member row');
SELECT pg_temp.refused($$SELECT bd_board_share(pg_temp.u('b1'), 26, 'member', 26, 'view')$$, 'owner already', 'the owner gets no row');
SELECT pg_temp.as_member(28);
SELECT pg_temp.refused($$SELECT bd_board_share(pg_temp.u('b1'), 28, 'member', 33, 'view')$$, 'owners can do that', 'only an owner shares');
SELECT pg_temp.refused($$SELECT bd_board_share(pg_temp.u('b1'), 26, 'member', 33, 'view')$$, 'not the signed-in one', 'nobody acts as someone else');
-- the two forms of the rule agree, for every actor on every board
SELECT pg_temp.as_member(NULL);
SELECT pg_temp.check(NOT EXISTS (
    SELECT 1 FROM members m CROSS JOIN boards b
     WHERE bd_board_level(b.id, m.id) IS DISTINCT FROM COALESCE((SELECT l.level FROM bd_member_board_levels(m.id) l WHERE l.board_id = b.id), 'none')),
    'bd_board_level and bd_member_board_levels agree for every member on every board');
-- leaving a department leaves its boards
UPDATE department_members SET left_at = now() WHERE member_id = 30 AND department_id = 7;
SELECT pg_temp.check(pg_temp.lvl(pg_temp.u('b1'), 30) = 'view', 'Dana left Sales: back to everyone''s view');
UPDATE department_members SET left_at = NULL WHERE member_id = 30 AND department_id = 7;

-- ================================================================ 2. items, geometry and the batch
SELECT pg_temp.keep('F', '00000000-0000-4000-8000-0000000000f1'), pg_temp.keep('S', '00000000-0000-4000-8000-0000000000a1'),
       pg_temp.keep('R', '00000000-0000-4000-8000-0000000000b1'), pg_temp.keep('C', '00000000-0000-4000-8000-0000000000c1'),
       pg_temp.keep('T', '00000000-0000-4000-8000-0000000000d1'), pg_temp.keep('K', '00000000-0000-4000-8000-0000000000e1');
SELECT pg_temp.ops('c1', pg_temp.u('b1'), 26, jsonb_build_array(
    jsonb_build_object('op', 'create', 'item', jsonb_build_object('id', pg_temp.u('F'), 'type', 'frame', 'x', 500, 'y', 500, 'width', 400, 'height', 300, 'z', 'a0', 'data', '{"title": "Went well"}'::jsonb)),
    jsonb_build_object('op', 'create', 'item', jsonb_build_object('id', pg_temp.u('S'), 'type', 'sticky_note', 'parent_id', pg_temp.u('F'), 'x', 100, 'y', 100, 'width', 200, 'height', 200, 'z', 'a1',
                       'style', '{"fillColor": "light_yellow", "fontSize": 14}'::jsonb, 'data', jsonb_build_object('content', bd_rich_text('Shipped on time')))),
    jsonb_build_object('op', 'create', 'item', jsonb_build_object('id', pg_temp.u('R'), 'type', 'shape', 'x', 1000, 'y', 1000, 'width', 100, 'height', 50, 'rotation', 90, 'z', 'a2', 'data', '{"shape": "rectangle"}'::jsonb)),
    jsonb_build_object('op', 'create', 'item', jsonb_build_object('id', pg_temp.u('C'), 'type', 'connector', 'start_item_id', pg_temp.u('S'), 'end_item_id', pg_temp.u('R'), 'z', 'a3', 'data', '{"shape": "elbowed"}'::jsonb))),
    '11111111-0000-4000-8000-000000000001');
SELECT pg_temp.check((pg_temp.r('c1')->>'seq')::bigint > 0 AND (pg_temp.r('c1')->>'item_count')::int = 4, 'a batch of four creates commits as one seq; the board counts four');
SELECT pg_temp.check(pg_temp.bx(pg_temp.u('F')) ~= box(point(300, 350), point(700, 650)), 'a frame''s box: centre ± half its size');
SELECT pg_temp.check(pg_temp.bx(pg_temp.u('S')) ~= box(point(300, 350), point(500, 550)), 'a child''s box is absolute: the frame''s top-left + its frame-relative centre (D4)');
SELECT pg_temp.check(pg_temp.bx(pg_temp.u('R')) ~= box(point(975, 950), point(1025, 1050)), 'a rotated shape''s box includes the rotation');
SELECT pg_temp.check(pg_temp.bx(pg_temp.u('C')) ~= box(point(300, 350), point(1025, 1050)), 'a connector''s box spans its two ends');
SELECT pg_temp.ops('m1', pg_temp.u('b1'), 26, jsonb_build_array(jsonb_build_object('op', 'update', 'id', pg_temp.u('F'), 'fields', '{"x": 600}'::jsonb)));
SELECT pg_temp.check(pg_temp.bx(pg_temp.u('S')) ~= box(point(400, 350), point(600, 550)), 'moving the frame (one row) re-boxes its child');
SELECT pg_temp.check(pg_temp.bx(pg_temp.u('C')) ~= box(point(400, 350), point(1025, 1050)), '…and the child''s connector');
SELECT pg_temp.ops('m2', pg_temp.u('b1'), 26, jsonb_build_array(jsonb_build_object('op', 'update', 'id', pg_temp.u('R'), 'fields', '{"x": 1100}'::jsonb)));
SELECT pg_temp.check(pg_temp.bx(pg_temp.u('C')) ~= box(point(400, 350), point(1125, 1050)), 'moving an end re-boxes the connector');
SELECT pg_temp.check((SELECT last_seq FROM boards WHERE id = pg_temp.u('b1')) = (pg_temp.r('m2')->>'seq')::bigint
                     AND (pg_temp.it(pg_temp.u('R'))).last_seq = (pg_temp.r('m2')->>'seq')::bigint, 'the board and the item carry the last seq');
SELECT pg_temp.check((pg_temp.r('m1')->>'seq')::bigint > (pg_temp.r('c1')->>'seq')::bigint AND (pg_temp.r('m2')->>'seq')::bigint > (pg_temp.r('m1')->>'seq')::bigint,
                     'seqs grow in commit order');

-- per-property last-writer-wins
SELECT bd_board_access_set(pg_temp.u('b1'), 26, 'edit');
SELECT pg_temp.ops('w1', pg_temp.u('b1'), 26, jsonb_build_array(jsonb_build_object('op', 'update', 'id', pg_temp.u('S'), 'fields', '{"style": {"fillColor": "light_green"}}'::jsonb)));
SELECT pg_temp.ops('w2', pg_temp.u('b1'), 28, jsonb_build_array(jsonb_build_object('op', 'update', 'id', pg_temp.u('S'), 'fields', '{"x": 120}'::jsonb)));
SELECT pg_temp.check((pg_temp.it(pg_temp.u('S'))).x = 120 AND (pg_temp.it(pg_temp.u('S'))).style->>'fillColor' = 'light_green'
                     AND ((pg_temp.it(pg_temp.u('S'))).style->>'fontSize')::int = 14, 'a recolour and a move by two people both survive; untouched keys stay');
SELECT pg_temp.ops('w3', pg_temp.u('b1'), 26, jsonb_build_array(jsonb_build_object('op', 'update', 'id', pg_temp.u('S'), 'fields', '{"style": {"fontSize": null}}'::jsonb)));
SELECT pg_temp.check(NOT ((pg_temp.it(pg_temp.u('S'))).style ? 'fontSize'), 'a JSON null removes a key');
SELECT pg_temp.check((SELECT inverse FROM board_ops WHERE seq = (pg_temp.r('w2')->>'seq')::bigint) -> 0 -> 'fields' = '{"x": 100}'::jsonb,
                     'the inverse holds exactly the old value of what changed');
-- idempotency
SELECT pg_temp.ops('i1', pg_temp.u('b1'), 26, jsonb_build_array(jsonb_build_object('op', 'update', 'id', pg_temp.u('R'), 'fields', '{"y": 1010}'::jsonb)), '22222222-0000-4000-8000-000000000002');
SELECT pg_temp.ops('i2', pg_temp.u('b1'), 26, jsonb_build_array(jsonb_build_object('op', 'update', 'id', pg_temp.u('R'), 'fields', '{"y": 9999}'::jsonb)), '22222222-0000-4000-8000-000000000002');
SELECT pg_temp.check((pg_temp.r('i2')->>'duplicate')::boolean AND pg_temp.r('i2')->'seq' = pg_temp.r('i1')->'seq' AND (pg_temp.it(pg_temp.u('R'))).y = 1010,
                     'a retried batch answers the first seq and does nothing twice');
SELECT pg_temp.check((SELECT count(*) FROM board_ops WHERE client_batch_id = '22222222-0000-4000-8000-000000000002') = 1, '…one row in the log');
-- undo (D8)
SELECT pg_temp.as_member(28);
SELECT bd_undo_ops(pg_temp.u('b1'), 28, ARRAY[(pg_temp.r('w2')->>'seq')::bigint], 'web');
SELECT pg_temp.check((pg_temp.it(pg_temp.u('S'))).x = 100, 'undo takes back one''s own batch');
SELECT pg_temp.check((SELECT undoes_seq FROM board_ops WHERE board_id = pg_temp.u('b1') ORDER BY seq DESC LIMIT 1) = ARRAY[(pg_temp.r('w2')->>'seq')::bigint], 'the undo names what it undid');
SELECT pg_temp.refused($$SELECT bd_undo_ops(pg_temp.u('b1'), 28, ARRAY[(pg_temp.r('w1')->>'seq')::bigint], 'web')$$, 'own changes', 'nobody undoes another''s batch');
-- the text replaced notice
SELECT pg_temp.ops('t1', pg_temp.u('b1'), 26, jsonb_build_array(jsonb_build_object('op', 'update', 'id', pg_temp.u('S'), 'fields', jsonb_build_object('data', jsonb_build_object('content', bd_rich_text('Shipped on time, mostly'))))));
SELECT pg_temp.ops('t2', pg_temp.u('b1'), 28, jsonb_build_array(jsonb_build_object('op', 'update', 'id', pg_temp.u('S'), 'base_version', (pg_temp.r('t1')->'versions'->>(pg_temp.u('S')::text))::bigint - 1,
                       'fields', jsonb_build_object('data', jsonb_build_object('content', bd_rich_text('Shipped late'))))));
SELECT pg_temp.check(pg_temp.r('t2')->'replaced' = jsonb_build_array(jsonb_build_object('id', pg_temp.u('S'), 'by', 26)) AND (pg_temp.it(pg_temp.u('S'))).plain_text = 'Shipped late',
                     'a text written over a newer one wins — and says whose it replaced');
-- locks
SELECT pg_temp.ops('l1', pg_temp.u('b1'), 26, jsonb_build_array(jsonb_build_object('op', 'update', 'id', pg_temp.u('R'), 'fields', '{"locked": true}'::jsonb)));
SELECT pg_temp.refused($$SELECT pg_temp.ops('l2', pg_temp.u('b1'), 26, jsonb_build_array(jsonb_build_object('op', 'update', 'id', pg_temp.u('R'), 'fields', '{"x": 1}'::jsonb)))$$, 'locked', 'a locked item does not move');
SELECT pg_temp.refused($$SELECT pg_temp.ops('l3', pg_temp.u('b1'), 26, jsonb_build_array(jsonb_build_object('op', 'delete', 'id', pg_temp.u('R'))))$$, 'unlock it first', '…nor go');
SELECT pg_temp.ops('l4', pg_temp.u('b1'), 26, jsonb_build_array(jsonb_build_object('op', 'update', 'id', pg_temp.u('R'), 'fields', '{"locked": false, "x": 1100}'::jsonb)));
SELECT pg_temp.check(NOT (pg_temp.it(pg_temp.u('R'))).locked, 'unlocking may change the rest in the same op');
-- malformed batches and broken rules
SELECT pg_temp.refused($$SELECT pg_temp.ops('x1', pg_temp.u('b1'), 26, '[{"op": "update", "id": "00000000-0000-4000-8000-0000000000a1", "fields": {"colour": "red"}}]')$$, 'no field colour', 'an unknown field');
SELECT pg_temp.refused($$SELECT pg_temp.ops('x2', pg_temp.u('b1'), 26, '[{"op": "explode"}]')$$, 'no op called', 'an unknown op');
SELECT pg_temp.refused($$SELECT pg_temp.ops('x3', pg_temp.u('b1'), 26, '[]')$$, 'at least one op', 'an empty batch');
SELECT pg_temp.refused($$SELECT pg_temp.ops('x4', pg_temp.u('b1'), 26, jsonb_build_array(jsonb_build_object('op', 'create', 'item', jsonb_build_object('id', pg_temp.u('S'), 'type', 'text'))))$$, 'exists already', 'an id already taken');
SELECT pg_temp.refused($$SELECT pg_temp.ops('x5', pg_temp.u('b1'), 26, jsonb_build_array(jsonb_build_object('op', 'create', 'item', jsonb_build_object('id', gen_random_uuid(), 'type', 'text', 'parent_id', pg_temp.u('R')))))$$, 'only in a frame', 'a parent that is not a frame');
SELECT pg_temp.refused($$SELECT pg_temp.ops('x6', pg_temp.u('b1'), 26, jsonb_build_array(jsonb_build_object('op', 'create', 'item', jsonb_build_object('id', gen_random_uuid(), 'type', 'frame', 'rotation', 10))))$$, 'board_items_check', 'a frame does not rotate');
SELECT pg_temp.refused($$SELECT pg_temp.ops('x7', pg_temp.u('b1'), 30, jsonb_build_array(jsonb_build_object('op', 'update', 'id', pg_temp.u('S'), 'fields', '{"x": 1}'::jsonb)))$$, 'not change it', 'a commenter does not draw');
SELECT pg_temp.as_member(26);
SELECT pg_temp.refused($$SELECT bd_commit_ops(pg_temp.u('b1'), 28, 'web', NULL, NULL, '[{"op": "delete", "id": "00000000-0000-4000-8000-0000000000a1"}]')$$, 'not the signed-in one', 'a batch is the signed-in member''s');
-- a connector stays on its board
SELECT pg_temp.as_member(26);
SELECT pg_temp.keep('b2', bd_board_create(26, 'Other board'));
SELECT pg_temp.ops('o1', pg_temp.u('b2'), 26, jsonb_build_array(jsonb_build_object('op', 'create', 'item', jsonb_build_object('id', pg_temp.u('K'), 'type', 'text', 'width', 10, 'height', 10))));
SELECT pg_temp.refused($$SELECT pg_temp.ops('x8', pg_temp.u('b1'), 26, jsonb_build_array(jsonb_build_object('op', 'create', 'item', jsonb_build_object('id', gen_random_uuid(), 'type', 'connector', 'start_item_id', pg_temp.u('S'), 'end_item_id', pg_temp.u('K')))))$$, 'own board', 'a connector joins items on its own board');

-- deletes, cascades, restores
SELECT pg_temp.ops('d1', pg_temp.u('b1'), 26, jsonb_build_array(jsonb_build_object('op', 'delete', 'id', pg_temp.u('F'))));
SELECT pg_temp.check((SELECT count(*) FROM board_items WHERE board_id = pg_temp.u('b1') AND deleted_at IS NOT NULL AND id IN (pg_temp.u('F'), pg_temp.u('S'), pg_temp.u('C'))) = 3
                     AND (pg_temp.it(pg_temp.u('R'))).deleted_at IS NULL, 'deleting a frame takes its children and their connectors; the other end stays');
SELECT pg_temp.check((SELECT item_count FROM boards WHERE id = pg_temp.u('b1')) = 1, 'the live count follows');
SELECT pg_temp.check((SELECT ops->0->'ids' FROM board_ops WHERE seq = (pg_temp.r('d1')->>'seq')::bigint) @> to_jsonb(ARRAY[pg_temp.u('C')]),
                     'the batch names every item it took');
SELECT pg_temp.as_member(26);
SELECT bd_undo_ops(pg_temp.u('b1'), 26, ARRAY[(pg_temp.r('d1')->>'seq')::bigint], 'web');
SELECT pg_temp.check((SELECT count(*) FROM board_items WHERE board_id = pg_temp.u('b1') AND deleted_at IS NULL) = 4 AND (SELECT item_count FROM boards WHERE id = pg_temp.u('b1')) = 4,
                     'undoing the delete brings all four back');
SELECT pg_temp.ops('d2', pg_temp.u('b1'), 26, jsonb_build_array(jsonb_build_object('op', 'delete', 'id', pg_temp.u('F'), 'keep_children', true)));
SELECT pg_temp.check((pg_temp.it(pg_temp.u('S'))).parent_id IS NULL AND (pg_temp.it(pg_temp.u('S'))).x = 500 AND (pg_temp.it(pg_temp.u('S'))).y = 450
                     AND (pg_temp.it(pg_temp.u('S'))).deleted_at IS NULL AND (pg_temp.it(pg_temp.u('C'))).deleted_at IS NULL,
                     'keep_children: the child stays where it stood, on the canvas, with its connector');
SELECT bd_undo_ops(pg_temp.u('b1'), 26, ARRAY[(pg_temp.r('d2')->>'seq')::bigint], 'web');
SELECT pg_temp.check((pg_temp.it(pg_temp.u('S'))).parent_id = pg_temp.u('F') AND (pg_temp.it(pg_temp.u('S'))).x = 100 AND (pg_temp.it(pg_temp.u('F'))).deleted_at IS NULL,
                     '…and undo puts it back in its frame');
SELECT pg_temp.ops('d3', pg_temp.u('b1'), 26, jsonb_build_array(jsonb_build_object('op', 'delete', 'id', pg_temp.u('F'))));
SELECT pg_temp.ops('r1', pg_temp.u('b1'), 26, jsonb_build_array(jsonb_build_object('op', 'restore', 'ids', jsonb_build_array(pg_temp.u('S')))));
SELECT pg_temp.check((pg_temp.it(pg_temp.u('S'))).deleted_at IS NULL AND (pg_temp.it(pg_temp.u('F'))).deleted_at IS NULL AND (pg_temp.it(pg_temp.u('C'))).deleted_at IS NOT NULL,
                     'restoring a child brings its frame back; what was not asked for stays gone');
SELECT pg_temp.ops('r2', pg_temp.u('b1'), 26, jsonb_build_array(jsonb_build_object('op', 'restore', 'ids', jsonb_build_array(pg_temp.u('C')))));
SELECT pg_temp.check((pg_temp.it(pg_temp.u('C'))).start_item_id = pg_temp.u('S') AND pg_temp.bx(pg_temp.u('C')) ~= box(point(400, 350), point(1125, 1060)),
                     'a restored connector keeps its live ends and re-boxes');
SELECT pg_temp.ops('r3', pg_temp.u('b1'), 26, jsonb_build_array(jsonb_build_object('op', 'delete', 'id', pg_temp.u('S'))));
SELECT pg_temp.check((pg_temp.it(pg_temp.u('C'))).deleted_at IS NOT NULL, 'deleting an item takes its connectors');
SELECT pg_temp.ops('r4', pg_temp.u('b1'), 26, jsonb_build_array(jsonb_build_object('op', 'delete', 'id', pg_temp.u('S'))));
SELECT pg_temp.check((pg_temp.r('r4')->>'noop')::boolean, 'deleting what is already gone does nothing');
SELECT bd_undo_ops(pg_temp.u('b1'), 26, ARRAY[(pg_temp.r('r3')->>'seq')::bigint], 'web');

-- the limits (D7)
RESET ROLE;
UPDATE bd_settings SET item_soft_budget = 100, item_hard_cap = 101, batch_max_ops = 2, drawing_max_points = 10;
SET ROLE boards_rw;
SELECT pg_temp.refused($$SELECT pg_temp.ops('y1', pg_temp.u('b1'), 26, '[{"op":"update","id":"00000000-0000-4000-8000-0000000000a1","fields":{"x":1}},{"op":"update","id":"00000000-0000-4000-8000-0000000000a1","fields":{"x":2}},{"op":"update","id":"00000000-0000-4000-8000-0000000000a1","fields":{"x":3}}]')$$,
                       'at most 2 ops', 'a batch holds at most batch_max_ops');
SELECT pg_temp.refused($$SELECT pg_temp.ops('y2', pg_temp.u('b1'), 26, jsonb_build_array(jsonb_build_object('op', 'create', 'item', jsonb_build_object('id', gen_random_uuid(), 'type', 'drawing',
                         'data', jsonb_build_object('points', to_jsonb(ARRAY(SELECT g FROM generate_series(1, 22) g)))))))$$, 'at most 10 points', 'a drawing holds at most drawing_max_points');
RESET ROLE;
UPDATE bd_settings SET batch_max_ops = 500;
SET ROLE boards_rw;
SELECT pg_temp.as_member(26);
SELECT pg_temp.keep('b3', bd_board_create(26, 'Crowded'));
SELECT pg_temp.ops('y3', pg_temp.u('b3'), 26, (SELECT jsonb_agg(jsonb_build_object('op', 'create', 'item', jsonb_build_object('id', gen_random_uuid(), 'type', 'sticky_note', 'width', 100, 'height', 100))) FROM generate_series(1, 101)));
SELECT pg_temp.check(jsonb_array_length(pg_temp.r('y3')->'warnings') = 1, 'past the soft budget a batch warns');
SELECT pg_temp.refused($$SELECT pg_temp.ops('y4', pg_temp.u('b3'), 26, jsonb_build_array(jsonb_build_object('op', 'create', 'item', jsonb_build_object('id', gen_random_uuid(), 'type', 'text'))))$$, 'board is full', 'past the hard cap a create is refused');
RESET ROLE;
UPDATE bd_settings SET item_soft_budget = 4000, item_hard_cap = 10000, drawing_max_points = 2000;
SET ROLE boards_rw;

-- the NOTIFY payload
SELECT pg_temp.check((SELECT bd_ops_payload(o) ? 'ops' AND bd_ops_payload(o)->>'room' = 'board:' || pg_temp.u('b1') FROM board_ops o WHERE seq = (pg_temp.r('m1')->>'seq')::bigint),
                     'a small batch rides in the NOTIFY with its room');
SELECT pg_temp.check((SELECT NOT (bd_ops_payload(o) ? 'ops') AND (bd_ops_payload(o)->>'seq')::bigint = o.seq AND octet_length(bd_ops_payload(o)::text) < 8000
                        FROM board_ops o WHERE seq = (pg_temp.r('y3')->>'seq')::bigint), 'a large batch sends {seq} alone, under NOTIFY''s 8,000 bytes');
SELECT pg_temp.check((SELECT bd_ops_payload(o)->'summary'->'bbox' IS NOT NULL FROM board_ops o WHERE seq = (pg_temp.r('m2')->>'seq')::bigint),
                     'the payload carries the batch''s box (an agent''s cursor goes there)');

-- the region read (design §7)
SELECT pg_temp.as_member(28);
SELECT pg_temp.check((SELECT array_agg(id ORDER BY id) FROM bd_region_items(pg_temp.u('b1'), 350, 400, 450, 500)) @> ARRAY[pg_temp.u('S'), pg_temp.u('F')]
                     AND NOT (SELECT array_agg(id) FROM bd_region_items(pg_temp.u('b1'), 350, 400, 450, 500)) @> ARRAY[pg_temp.u('R')], 'a region read answers what meets the box');
SELECT pg_temp.check((SELECT type FROM bd_region_items(pg_temp.u('b1'), 0, 0, 2000, 2000) LIMIT 1) = 'frame', '…frames first');
SELECT pg_temp.as_member(32);
SELECT pg_temp.check(NOT EXISTS (SELECT 1 FROM bd_region_items(pg_temp.u('b1'), 0, 0, 2000, 2000)), '…and nothing for someone who cannot see the board');
RESET ROLE;
SET LOCAL enable_seqscan = off;
DO $$ DECLARE l text; p text := ''; BEGIN
    FOR l IN EXECUTE format('EXPLAIN SELECT id FROM board_items WHERE board_id = %L AND deleted_at IS NULL AND bbox && box(point(0,0), point(10,10))',
                            (SELECT u FROM uids WHERE k = 'b1')) LOOP p := p || l; END LOOP;
    INSERT INTO proof (ok, label) VALUES (p LIKE '%board_items_bbox_idx%', 'a region read can use the (board_id, bbox) GiST index');
END $$;
SET LOCAL enable_seqscan = on;
SET ROLE boards_rw;

-- ================================================================ 3. comments, mentions, notifications, dispatches (D11)
SELECT pg_temp.as_member(26);
SELECT pg_temp.keep('cm1', ((SELECT bd_comment_create(pg_temp.u('b1'), 26,
          jsonb_build_array(jsonb_build_object('type', 'text', 'text', jsonb_build_object('content', 'Can '), 'plain_text', 'Can '),
                            jsonb_build_object('type', 'mention', 'mention', jsonb_build_object('type', 'agent', 'id', 42), 'plain_text', '@Facilitator'),
                            jsonb_build_object('type', 'text', 'text', jsonb_build_object('content', ' group these? '), 'plain_text', ' group these? '),
                            jsonb_build_object('type', 'mention', 'mention', jsonb_build_object('type', 'member', 'id', 28), 'plain_text', '@Bea'),
                            jsonb_build_object('type', 'mention', 'mention', jsonb_build_object('type', 'member', 'id', 32), 'plain_text', '@Sam'),
                            jsonb_build_object('type', 'mention', 'mention', jsonb_build_object('type', 'agent', 'id', 40), 'plain_text', '@Expert')),
          pg_temp.u('S'), 10, -20, NULL, ARRAY[pg_temp.u('S'), pg_temp.u('R'), gen_random_uuid()]))->>'id')::uuid);
SELECT pg_temp.check((SELECT context_item_ids = ARRAY[pg_temp.u('S'), pg_temp.u('R')] FROM comments WHERE id = pg_temp.u('cm1')),
                     'the selection when it was written is kept — only items on this board');
SELECT pg_temp.check((SELECT count(*) FROM agent_dispatches WHERE record_uuid = pg_temp.u('cm1') AND agent_member_id = 42 AND kind = 'mention' AND via = 'chat'
                        AND acting_member_id = 26 AND conversation_id = 'boards:thread:' || pg_temp.u('cm1')) = 1,
                     'a person''s mention of an agent who can see the board is one dispatch, on the thread''s conversation');
SELECT pg_temp.check(EXISTS (SELECT 1 FROM agent_dispatches WHERE record_uuid = pg_temp.u('cm1') AND agent_member_id = 40), 'the Expert sees the board through everyone: dispatched too');
SELECT pg_temp.check((SELECT count(*) FROM notifications WHERE member_id = 28 AND kind = 'mention' AND record_uuid = pg_temp.u('cm1')) = 1
                     AND EXISTS (SELECT 1 FROM notification_outbox WHERE member_id = 28 AND channel = 'email' AND to_email = 'bea@example.invalid'),
                     'a person mentioned who can see the board is told, and emailed');
SELECT pg_temp.check(NOT EXISTS (SELECT 1 FROM notifications WHERE member_id = 32), 'someone who cannot open Boards is told nothing');
SELECT bd_board_access_set(pg_temp.u('b1'), 26, 'none');
SELECT pg_temp.keep('cm2', ((SELECT bd_comment_create(pg_temp.u('b1'), 26,
          jsonb_build_array(jsonb_build_object('type', 'mention', 'mention', jsonb_build_object('type', 'agent', 'id', 40), 'plain_text', '@Expert'),
                            jsonb_build_object('type', 'mention', 'mention', jsonb_build_object('type', 'member', 'id', 33), 'plain_text', '@Kim')),
          NULL, 50, 60)) ->> 'id')::uuid);
SELECT pg_temp.check((SELECT needs_share = '[40]'::jsonb AND not_shared = '[33]'::jsonb FROM (SELECT
                        (SELECT bd_comment_create(pg_temp.u('b1'), 26, jsonb_build_array(jsonb_build_object('type', 'mention', 'mention', jsonb_build_object('type', 'agent', 'id', 40), 'plain_text', '@Expert'),
                                                                                         jsonb_build_object('type', 'mention', 'mention', jsonb_build_object('type', 'member', 'id', 33), 'plain_text', '@Kim')),
                                                 NULL, 70, 80)) AS j) x, LATERAL (SELECT j->'needs_share' AS needs_share, j->'not_shared' AS not_shared) y),
                     'a mention never grants: an agent who cannot see the board is asked about, a person who cannot is not told');
SELECT pg_temp.check(NOT EXISTS (SELECT 1 FROM agent_dispatches WHERE record_uuid = pg_temp.u('cm2')), '…and nothing is dispatched');
SELECT pg_temp.refused($$SELECT bd_comment_create(pg_temp.u('b1'), 26, bd_rich_text('Floating'))$$, 'comments_check', 'a new thread is pinned to an item or a point');
-- replies
SELECT pg_temp.as_member(30);
SELECT pg_temp.keep('rp1', ((SELECT bd_comment_create(pg_temp.u('b1'), 30, bd_rich_text('Agreed'), NULL, NULL, NULL, pg_temp.u('cm1'))) ->> 'id')::uuid);
SELECT pg_temp.check(EXISTS (SELECT 1 FROM notifications WHERE member_id = 26 AND kind = 'reply' AND record_uuid = pg_temp.u('rp1')), 'a reply tells the thread''s people');
SELECT pg_temp.as_member(26);
SELECT pg_temp.keep('rp2', ((SELECT bd_comment_create(pg_temp.u('b1'), 26, bd_rich_text('Thanks'), NULL, NULL, NULL, pg_temp.u('rp1'))) ->> 'id')::uuid);
SELECT pg_temp.check((SELECT parent_comment_id FROM comments WHERE id = pg_temp.u('rp2')) = pg_temp.u('cm1'), 'a reply to a reply hangs on the thread''s root');
SELECT pg_temp.refused($$SELECT bd_comment_create(pg_temp.u('b2'), 26, bd_rich_text('x'), NULL, NULL, NULL, pg_temp.u('cm1'))$$, 'thread''s board', 'a reply stays on its thread''s board');
SELECT pg_temp.as_member(28);
SELECT pg_temp.refused($$SELECT bd_comment_create(pg_temp.u('b1'), 28, bd_rich_text('x'), NULL, 1, 1)$$, 'no such board', 'someone who cannot see the board cannot find it to comment');
-- an agent's comment dispatches nobody
SELECT pg_temp.as_member(42);
SELECT pg_temp.check((SELECT bd_comment_create(pg_temp.u('b1'), 42, jsonb_build_array(jsonb_build_object('type', 'mention', 'mention', jsonb_build_object('type', 'agent', 'id', 40), 'plain_text', '@Expert')),
                        NULL, NULL, NULL, pg_temp.u('cm1'))->'dispatched') = '[]'::jsonb, 'an agent''s mention of an agent dispatches nobody');
-- a department mention, prefs: the digest and texts
SELECT pg_temp.as_member(26);
INSERT INTO notification_prefs (member_id, digest, text_enabled, text_kinds) VALUES (30, true, true, '{mention}');
SELECT bd_comment_create(pg_temp.u('b1'), 26, jsonb_build_array(jsonb_build_object('type', 'mention', 'mention', jsonb_build_object('type', 'department', 'id', 7), 'plain_text', '@Sales')), NULL, 5, 5);
SELECT pg_temp.check(EXISTS (SELECT 1 FROM notifications WHERE member_id = 30 AND kind = 'mention' AND title LIKE '%your department%'), 'a department mention tells its people who can see the board');
SELECT pg_temp.check(NOT EXISTS (SELECT 1 FROM notification_outbox WHERE member_id = 30 AND channel = 'email' AND kind = 'mention')
                     AND EXISTS (SELECT 1 FROM notification_outbox WHERE member_id = 30 AND channel = 'text' AND kind = 'mention'), 'the digest holds the email back; an opted-in text goes');
-- resolve, delete
SELECT pg_temp.as_member(30);
SELECT bd_comment_resolve(pg_temp.u('cm1'), 30, true);
SELECT pg_temp.check((SELECT resolved_by FROM comments WHERE id = pg_temp.u('cm1')) = 30, 'a commenter resolves a thread');
SELECT pg_temp.refused($$SELECT bd_comment_delete(pg_temp.u('cm1'), 30)$$, 'author or the board''s owners', 'only its author or an owner removes a comment');
SELECT pg_temp.as_member(26);
SELECT bd_comment_delete(pg_temp.u('cm1'), 26);
SELECT pg_temp.check((SELECT count(*) FROM comments WHERE (id = pg_temp.u('cm1') OR parent_comment_id = pg_temp.u('cm1')) AND deleted_at IS NULL) = 0, 'removing a thread removes its replies');

-- ================================================================ 4. versions and restore (D6)
SELECT pg_temp.as_member(26);
SELECT pg_temp.check(bd_save_version(pg_temp.u('b1'), 26, 'manual', 'Before the workshop') = 1, 'a named version');
SELECT pg_temp.keep('snap1', NULL);
CREATE TEMP TABLE snap AS SELECT bd_board_snapshot(pg_temp.u('b1')) AS s;
SELECT pg_temp.ops('v1', pg_temp.u('b1'), 26, jsonb_build_array(
    jsonb_build_object('op', 'update', 'id', pg_temp.u('S'), 'fields', jsonb_build_object('x', 150, 'style', '{"fillColor": "red", "extra": 1}'::jsonb)),
    jsonb_build_object('op', 'create', 'item', jsonb_build_object('id', pg_temp.u('T'), 'type', 'text', 'x', 5, 'y', 5, 'width', 50, 'height', 20,
                       'data', jsonb_build_object('content', bd_rich_text('added after'))))));
SELECT pg_temp.ops('v2', pg_temp.u('b1'), 26, jsonb_build_array(jsonb_build_object('op', 'delete', 'id', pg_temp.u('R')),
                                                                 jsonb_build_object('op', 'update', 'id', pg_temp.u('F'), 'fields', '{"locked": true}'::jsonb)));
SELECT pg_temp.keep('rs', NULL);
INSERT INTO res (k, j) SELECT 'restore', bd_restore_version(pg_temp.u('b1'), 26, 1);
SELECT pg_temp.check((SELECT jsonb_agg(x - 'version' - 'updated_by' - 'created_by' ORDER BY x->>'id') FROM jsonb_array_elements(bd_board_snapshot(pg_temp.u('b1'))->'items') x)
                   = (SELECT jsonb_agg(x - 'version' - 'updated_by' - 'created_by' ORDER BY x->>'id') FROM snap, jsonb_array_elements(snap.s->'items') x),
                     'restore turns today''s board into the version — moved back, added removed, deleted returned, a lock undone');
SELECT pg_temp.check((SELECT source FROM board_ops WHERE seq = (pg_temp.r('restore')->>'seq')::bigint) = 'restore'
                     AND (SELECT count(*) FROM board_ops WHERE board_id = pg_temp.u('b1') AND seq > (pg_temp.r('v2')->>'seq')::bigint) = 1,
                     '…in ONE batch');
SELECT pg_temp.check((SELECT array_agg(reason ORDER BY version_no) FROM board_versions WHERE board_id = pg_temp.u('b1')) = ARRAY['manual', 'before_restore', 'restore'],
                     'a before_restore version first, a restore version after: nothing is lost');
SELECT pg_temp.as_member(28);
SELECT pg_temp.refused($$SELECT bd_restore_version(pg_temp.u('b1'), 28, 1)$$, 'no such board', 'a restore is an owner''s');
SELECT pg_temp.refused($$SELECT bd_save_version(pg_temp.u('b1'), 28, 'interval')$$, 'worker''s', 'interval versions are the worker''s');

-- ================================================================ 5. duplicate and templates (D12)
SELECT pg_temp.as_member(26);
SELECT pg_temp.keep('dup', bd_board_duplicate(pg_temp.u('b1'), 26));
SELECT pg_temp.check((SELECT item_count FROM boards WHERE id = pg_temp.u('dup')) = (SELECT item_count FROM boards WHERE id = pg_temp.u('b1'))
                     AND (SELECT title FROM boards WHERE id = pg_temp.u('dup')) = 'Q4 launch retro (copy)', 'a duplicate copies every live item');
SELECT pg_temp.check((SELECT c.parent_id = f.id FROM board_items c JOIN board_items f ON f.board_id = c.board_id AND f.type = 'frame'
                       WHERE c.board_id = pg_temp.u('dup') AND c.type = 'sticky_note')
                     AND (SELECT k.start_item_id IS NOT NULL AND k.end_item_id IS NOT NULL FROM board_items k WHERE k.board_id = pg_temp.u('dup') AND k.type = 'connector'),
                     '…with its frames and connectors mapped to the new items');
SELECT pg_temp.check(pg_temp.lvl(pg_temp.u('dup'), 28) = 'none' AND pg_temp.lvl(pg_temp.u('dup'), 42) = 'none', 'a duplicate is private: nobody else''s rows come with it');
SELECT pg_temp.keep('tpl', bd_board_duplicate(pg_temp.u('b1'), 26, 'My retro template', 'personal', 'retrospective'));
SELECT pg_temp.check(pg_temp.lvl(pg_temp.u('tpl'), 28) = 'none', 'a personal template is its maker''s');
SELECT pg_temp.refused($$SELECT bd_board_duplicate(pg_temp.u('b1'), 26, 'For everyone', 'business')$$, 'Boards admin publishes', 'a Member does not publish for the business');
SELECT pg_temp.as_member(27);
SELECT pg_temp.keep('btpl', bd_board_duplicate(pg_temp.u('b1'), 27, 'Retro', 'business', 'retrospective'));
SELECT pg_temp.check(pg_temp.lvl(pg_temp.u('btpl'), 28) = 'view' AND pg_temp.lvl(pg_temp.u('btpl'), 31) = 'view' AND pg_temp.lvl(pg_temp.u('btpl'), 29) = 'none',
                     'a business template is every insider''s to see, not an external''s');
RESET ROLE;
INSERT INTO boards (id, title, is_template, template_scope, template_category) VALUES ('00000000-0000-4000-8000-00000000aaaa', 'Brainstorm', true, 'shipped', 'brainstorm');
SET ROLE boards_rw;
SELECT pg_temp.as_member(28);
SELECT pg_temp.check(bd_board_duplicate('00000000-0000-4000-8000-00000000aaaa', 28, 'Our brainstorm') IS NOT NULL, 'a shipped template (no owner) is used by a Member');
SELECT pg_temp.as_member(30);
SELECT pg_temp.refused($$SELECT bd_board_duplicate('00000000-0000-4000-8000-00000000aaaa', 30)$$, 'not make new ones', '…not by a Viewer');

-- ================================================================ 6. transfer, the trash, purge
SELECT pg_temp.as_member(26);
SELECT pg_temp.refused($$SELECT bd_board_transfer(pg_temp.u('b2'), 26, 30)$$, 'may make boards', 'a board passes only to someone who may make boards');
SELECT bd_board_transfer(pg_temp.u('b2'), 26, 33);
SELECT pg_temp.check((SELECT owner_member_id FROM boards WHERE id = pg_temp.u('b2')) = 33 AND pg_temp.lvl(pg_temp.u('b2'), 26) = 'edit', 'transferred; the previous owner keeps edit');
SELECT pg_temp.as_member(33);
SELECT bd_board_delete(pg_temp.u('b2'), 33);
SELECT pg_temp.refused($$SELECT bd_board_update(pg_temp.u('b2'), 33, '{"title": "x"}')$$, 'no such board', 'a board in the trash is not changed');
SELECT pg_temp.as_member(26);
SELECT pg_temp.refused($$SELECT bd_board_restore(pg_temp.u('b2'), 26)$$, 'your trash', 'only the owner or an admin brings it back');
SELECT pg_temp.refused($$SELECT bd_board_purge(pg_temp.u('b2'))$$, 'Boards admin', 'only an admin empties the trash');
SELECT pg_temp.as_member(33);
SELECT bd_board_restore(pg_temp.u('b2'), 33);
SELECT pg_temp.check((SELECT deleted_at IS NULL FROM boards WHERE id = pg_temp.u('b2')), 'the owner brings it back');
SELECT bd_board_delete(pg_temp.u('b2'), 33);
SELECT pg_temp.as_member(27);
SELECT bd_board_purge(pg_temp.u('b2'));
SELECT pg_temp.check(NOT EXISTS (SELECT 1 FROM boards WHERE id = pg_temp.u('b2')) AND NOT EXISTS (SELECT 1 FROM board_items WHERE id = pg_temp.u('K')),
                     'an admin purges it, with everything on it');

-- ================================================================ 7. the live check (contract gap 5)
RESET ROLE;
SELECT pg_temp.as_member(NULL);
INSERT INTO member_sessions (session_hash, member_id) VALUES ('proof-s-26', 26), ('proof-s-30', 30);
SET ROLE boards_records_ro;
SELECT pg_temp.check(bd_live_check(26, pg_temp.u('b1')) = 'full' AND bd_live_check(30, pg_temp.u('b1')) = 'comment', 'the relay''s check answers the level now (as the records role)');
SELECT pg_temp.check(bd_live_check(28, pg_temp.u('b1')) IS NULL, '…NULL with no open session');
SELECT pg_temp.check((SELECT bd_live_batch((pg_temp.r('y3')->>'seq')::bigint)) IS NOT NULL, 'the relay reads a large batch by its seq');
RESET ROLE;
UPDATE member_sessions SET ended_at = now(), ended_by = 'kernel' WHERE member_id = 30;
SET ROLE boards_records_ro;
SELECT pg_temp.check(bd_live_check(30, pg_temp.u('b1')) IS NULL, 'a kernel sign-out closes the socket at the next check');
RESET ROLE;
DELETE FROM board_permissions WHERE board_id = pg_temp.u('b1') AND principal_kind = 'department';
SET ROLE boards_records_ro;
SELECT pg_temp.check(bd_live_check(26, pg_temp.u('b1')) = 'full', '…and an owner keeps theirs');
RESET ROLE;
SET ROLE boards_rw;
SELECT pg_temp.as_member(26);
SELECT bd_board_delete(pg_temp.u('dup'), 26);
RESET ROLE;
SET ROLE boards_records_ro;
SELECT pg_temp.check(bd_live_check(26, pg_temp.u('dup')) IS NULL, 'a board in the trash closes its sockets');
RESET ROLE;

-- ================================================================ 8. search and the read views (as the read roles)
SELECT pg_temp.check(EXISTS (SELECT 1 FROM search_index WHERE entity_kind = 'item' AND entity_uuid = pg_temp.u('S') AND body = 'Shipped late')
                     AND EXISTS (SELECT 1 FROM search_index WHERE entity_kind = 'board' AND entity_uuid = pg_temp.u('b1')), 'items and boards are indexed by trigger');
SELECT pg_temp.check(NOT EXISTS (SELECT 1 FROM search_index WHERE entity_kind = 'board' AND entity_uuid = '00000000-0000-4000-8000-00000000aaaa'), 'a shipped template is not in search');
SET ROLE boards_rw;
SELECT pg_temp.ops('s1', pg_temp.u('b1'), 26, jsonb_build_array(jsonb_build_object('op', 'delete', 'id', pg_temp.u('T'))));
RESET ROLE;
SELECT pg_temp.check(NOT EXISTS (SELECT 1 FROM search_index WHERE entity_uuid = pg_temp.u('T')), 'a deleted item leaves the index');
SET ROLE boards_records_ro;
SELECT pg_temp.as_member(28);
SELECT pg_temp.check(NOT EXISTS (SELECT 1 FROM mcp_search WHERE board_id = pg_temp.u('b1')) AND NOT EXISTS (SELECT 1 FROM mcp_boards WHERE board_id = pg_temp.u('b1'))
                     AND NOT EXISTS (SELECT 1 FROM mcp_board_items WHERE board_id = pg_temp.u('b1')), 'a private board is invisible to a Member it is not shared with');
SELECT pg_temp.as_member(42);
SELECT pg_temp.check((SELECT my_level FROM mcp_boards WHERE board_id = pg_temp.u('b1')) = 'edit' AND EXISTS (SELECT 1 FROM mcp_search WHERE board_id = pg_temp.u('b1'))
                     AND (SELECT count(*) FROM mcp_board_items WHERE board_id = pg_temp.u('b1')) = (SELECT item_count FROM mcp_boards WHERE board_id = pg_temp.u('b1')),
                     'the Facilitator, shared at edit, reads the board, its items and its search rows');
SELECT pg_temp.check((SELECT x1 = 400 AND y1 = 350 AND x2 = 600 AND y2 = 550 FROM mcp_board_items WHERE item_id = pg_temp.u('S')), 'a box as four numbers');
SELECT pg_temp.check(EXISTS (SELECT 1 FROM mcp_board_changes WHERE board_id = pg_temp.u('b1') AND source = 'restore'), 'the change feed, for what changed since');
SELECT pg_temp.as_member(29);
SELECT pg_temp.check((SELECT count(*) FROM mcp_boards) = 1 AND (SELECT my_level FROM mcp_boards) = 'comment', 'an external sees only what is shared with them by name, capped');
SELECT pg_temp.check((SELECT count(*) FROM mcp_members) = 1, 'an external sees only themselves in the directory');
SELECT pg_temp.as_member(26);
SELECT pg_temp.check((SELECT count(*) > 0 AND bool_and(member_id = 26) FROM mcp_notifications), 'notifications are one''s own');
SELECT pg_temp.check((SELECT count(*) FROM mcp_board_permissions WHERE board_id = pg_temp.u('b1')) >= 2, 'who has access is readable by whoever has access');
SELECT pg_temp.as_member(28);
SELECT pg_temp.check(NOT EXISTS (SELECT 1 FROM mcp_board_permissions WHERE board_id = pg_temp.u('b1')) AND NOT EXISTS (SELECT 1 FROM mcp_comments WHERE board_id = pg_temp.u('b1')),
                     '…and by nobody else; nor its comments');
SELECT pg_temp.as_member(27);
SELECT pg_temp.check(EXISTS (SELECT 1 FROM mcp_agent_dispatches) AND EXISTS (SELECT 1 FROM mcp_trash) IS NOT NULL, 'a Boards admin sees every dispatch');
RESET ROLE;
SET ROLE boards_activity_ro;
SELECT pg_temp.as_member(26);
SELECT pg_temp.check((SELECT count(*) FROM mcp_boards) >= 1, 'the activity role reads the boards it needs to name');
RESET ROLE;

-- ================================================================ 9. the worker
SELECT pg_temp.as_member(NULL);
SET ROLE boards_rw;
SELECT pg_temp.check(bd_interval_versions() >= 1, 'an interval version for a board that changed');
SELECT pg_temp.check(bd_interval_versions() = 0, '…at most hourly');
RESET ROLE;
UPDATE board_ops SET at = now() - interval '100 days' WHERE board_id = pg_temp.u('b3');
UPDATE board_versions SET created_at = now() - interval '100 days' WHERE board_id = pg_temp.u('b3');
SET ROLE boards_rw;
SELECT pg_temp.keep('compacted', NULL), bd_compact_ops();
SELECT pg_temp.check(NOT EXISTS (SELECT 1 FROM board_ops WHERE board_id = pg_temp.u('b3')), 'ops past retention are compacted away');
SELECT pg_temp.check(EXISTS (SELECT 1 FROM board_versions WHERE board_id = pg_temp.u('b3') AND content_rev = (SELECT last_seq FROM boards WHERE id = pg_temp.u('b3'))),
                     '…a version stands at the cut');
RESET ROLE;
UPDATE board_items SET deleted_at = now() - interval '100 days' WHERE id = pg_temp.u('T');
SET ROLE boards_rw;
SELECT bd_purge_deleted_items();
SELECT pg_temp.check(NOT EXISTS (SELECT 1 FROM board_items WHERE id = pg_temp.u('T')), 'an item deleted long ago is purged');
SELECT pg_temp.as_member(26);
SELECT pg_temp.refused($$SELECT bd_compact_ops()$$, 'worker''s', 'housekeeping is the worker''s');
RESET ROLE;

-- ================================================================ 10. grants and the estate's shapes
SELECT pg_temp.check(NOT EXISTS (SELECT 1 FROM information_schema.role_table_grants
                                  WHERE grantee IN ('boards_records_ro', 'boards_activity_ro')
                                    AND table_name IN ('boards', 'board_items', 'board_ops', 'board_permissions', 'comments', 'attachments', 'board_versions',
                                                       'notifications', 'notification_outbox', 'agent_dispatches', 'search_index', 'members', 'activity_log')),
                     'the read roles hold no grant on a table — the mcp_* views are their only window');
SELECT pg_temp.check(NOT has_function_privilege('boards_rw', 'bd_live_check(bigint, uuid)', 'EXECUTE')
                     AND NOT has_function_privilege('boards_records_ro', 'bd_commit_ops(uuid, bigint, text, text, uuid, jsonb, bigint, text, bigint[])', 'EXECUTE'),
                     'the relay''s role cannot write; the writer does not need the relay''s check');
SELECT pg_temp.check((SELECT array_agg(column_name::text ORDER BY ordinal_position) FROM information_schema.columns WHERE table_name = 'attachments')
                     [1:13] = ARRAY['id', 'record_type', 'record_id', 'filename', 'mime_type', 'byte_size', 'sha256', 'storage_path', 'uploaded_by', 'created_at', 'record_uuid', 'width', 'height'],
                     'attachments: Spaces'' canonical columns in order, ours appended');
SELECT pg_temp.check((SELECT array_agg(column_name::text ORDER BY ordinal_position) FROM information_schema.columns WHERE table_name = 'notifications')
                     [1:11] = ARRAY['id', 'member_id', 'kind', 'record_type', 'record_id', 'title', 'body', 'read_at', 'created_at', 'record_uuid', 'actor_member_id'],
                     'notifications: the canonical, then record_uuid and actor_member_id as Spaces has them');
SELECT pg_temp.check((SELECT array_agg(column_name::text ORDER BY ordinal_position) FROM information_schema.columns WHERE table_name = 'notification_outbox')
                     = ARRAY['id', 'channel', 'member_id', 'to_email', 'kind', 'record_type', 'record_id', 'dedupe_key', 'subject', 'body', 'body_html', 'status', 'attempts',
                             'detail', 'provider_ref', 'send_after', 'sent_at', 'created_at'], 'notification_outbox: verbatim');
SELECT pg_temp.check((SELECT array_agg(column_name::text ORDER BY ordinal_position) FROM information_schema.columns WHERE table_name = 'agent_dispatches')
                     [1:16] = ARRAY['id', 'record_type', 'record_id', 'agent_member_id', 'kind', 'via', 'acting_member_id', 'run_id', 'request_id', 'status', 'reply_excerpt',
                                    'detail', 'attempts', 'created_at', 'answered_at', 'record_uuid'], 'agent_dispatches: the canonical + record_uuid, ours appended');
SELECT pg_temp.check((SELECT array_agg(column_name::text ORDER BY ordinal_position) FROM information_schema.columns WHERE table_name = 'search_index')
                     [1:14] = ARRAY['entity_kind', 'entity_uuid', 'entity_id', 'page_id', 'space_id', 'channel_id', 'author_member_id', 'title', 'body', 'tsv', 'has_link',
                                    'has_file', 'occurred_at', 'updated_at'], 'search_index: Spaces'' canonical, ours appended');
SELECT pg_temp.check((SELECT array_agg(column_name::text ORDER BY ordinal_position) FROM information_schema.columns WHERE table_name = 'board_permissions')
                     = ARRAY['board_id', 'principal_kind', 'principal_id', 'level', 'granted_by', 'created_at'], 'board_permissions: page_permissions'' shape');
SELECT pg_temp.check((SELECT array_agg(column_name::text ORDER BY ordinal_position) FROM information_schema.columns WHERE table_name = 'board_versions')
                     [1:9] = ARRAY['id', 'board_id', 'version_no', 'content_rev', 'reason', 'snapshot', 'plain_text', 'saved_by', 'created_at'], 'board_versions: page_versions'' shape');
SELECT pg_temp.check((SELECT array_agg(column_name::text ORDER BY ordinal_position) FROM information_schema.columns WHERE table_name = 'comments')
                     [1:14] = ARRAY['id', 'board_id', 'item_id', 'parent_comment_id', 'author_member_id', 'body', 'plain_text', 'resolved_at', 'resolved_by', 'edited_at',
                                    'deleted_at', 'deleted_by', 'agent_run_id', 'created_at'], 'comments: Spaces'' family, the key following the application');
SELECT pg_temp.check((SELECT array_agg(column_name::text ORDER BY ordinal_position) FROM information_schema.columns WHERE table_name = 'worker_passes')
                     = ARRAY['id', 'started_at', 'finished_at', 'steps', 'error'], 'worker_passes: verbatim');
-- mcp_app_roles is the kit's catalogue (the roles and rights, no row rule) and is a plain view in every sibling; the rest are walls.
SELECT pg_temp.check(NOT EXISTS (SELECT 1 FROM pg_views v WHERE v.viewname LIKE 'mcp\_%' AND v.viewname <> 'mcp_app_roles'
                                  AND NOT EXISTS (SELECT 1 FROM pg_class c WHERE c.relname = v.viewname AND 'security_barrier=true' = ANY (c.reloptions))),
                     'every mcp_* view with a row rule is a security barrier');

-- ================================================================ 11. the Phase 1 reads (db/013; docs/boards-mcp-tool-surface.md)
SET ROLE boards_rw;
SELECT pg_temp.as_member(26);
SELECT pg_temp.keep('X', '00000000-0000-4000-8000-0000000000e9');
SELECT pg_temp.ops('p1', pg_temp.u('b1'), 26, jsonb_build_array(
    jsonb_build_object('op', 'create', 'item', jsonb_build_object('id', pg_temp.u('X'), 'type', 'text', 'x', 3000, 'y', 3000, 'width', 100, 'height', 40,
                       'z', 'B0', 'data', jsonb_build_object('content', bd_rich_text('<b>Tom & "Jerry"</b>'))))));
RESET ROLE;
SET ROLE boards_records_ro;
SELECT pg_temp.as_member(42);
CREATE TEMP TABLE ov AS SELECT bd_board_overview(pg_temp.u('b1')) AS j;
SELECT pg_temp.check((SELECT j->'frames'->0->>'title' = 'Went well' AND (j->'frames'->0->'counts_by_type'->>'sticky_note')::int = 1
                             AND j->'frames'->0->'sample'->>0 = 'Shipped late' FROM ov), 'the overview: frames with their titles, counts and a sample');
SELECT pg_temp.check((SELECT jsonb_array_length(j->'clusters') = 2 AND (j->'totals_by_type'->>'connector')::int = 1 AND j->>'my_level' = 'edit'
                             AND j->'last_change'->>'member_name' = 'SMOKE Priya' FROM ov),
                     '…the unframed items in cells (the shape, and the far text in its own), totals, the caller''s level, the last change');
SELECT pg_temp.as_member(28);
SELECT pg_temp.check(bd_board_overview(pg_temp.u('b1')) IS NULL AND bd_region_svg(pg_temp.u('b1'), 0, 0, 5000, 5000) IS NULL
                     AND bd_board_outline(pg_temp.u('b1')) IS NULL, 'nothing for a board the caller may not see — the same nothing as no board');
SELECT pg_temp.as_member(42);
CREATE TEMP TABLE rg AS SELECT bd_region_svg(pg_temp.u('b1'), 0, 0, 1500, 1500) AS j;
SELECT pg_temp.check((SELECT j->>'svg' LIKE '<svg xmlns="http://www.w3.org/2000/svg" data-board="' || pg_temp.u('b1') || '"%' AND (j->>'items')::int = 4
                             AND NOT (j->>'truncated')::boolean FROM rg), 'a region in the SVG dialect: the frame, its sticky, the shape, the connector');
SELECT pg_temp.check((SELECT j->>'svg' ~ ('<g data-id="' || pg_temp.u('F') || '" data-type="frame" data-title="Went well".*<foreignObject data-id="' || pg_temp.u('S')
                             || '" data-type="sticky_note" data-z="a1" data-cx="500" data-cy="450" data-color="light_green" x="0" y="0" width="200" height="200">Shipped late</foreignObject></g>') FROM rg),
                     '…a frame is a group; its child''s x/y are frame-relative, its data-cx/cy absolute');
SELECT pg_temp.check((SELECT j->>'svg' LIKE '%data-type="connector" data-start="' || pg_temp.u('S') || '" data-end="' || pg_temp.u('R') || '"%' FROM rg),
                     '…a connector names its two ends');
SELECT pg_temp.check((SELECT (j->>'truncated')::boolean AND (j->>'items')::int = 1 AND (j->>'total')::int = 4 FROM (SELECT bd_region_svg(pg_temp.u('b1'), 0, 0, 1500, 1500, 1) AS j) x),
                     'a region past its limit says it is truncated');
SELECT pg_temp.check((SELECT j->>'svg' LIKE '%&lt;b&gt;Tom &amp; &quot;Jerry&quot;&lt;/b&gt;%' FROM (SELECT bd_region_svg(pg_temp.u('b1'), 2900, 2900, 3100, 3100) AS j) x),
                     'text is XML-escaped');
SELECT pg_temp.check((SELECT j->>'svg' LIKE '%data-frame="' || pg_temp.u('F') || '"%' AND j->>'svg' LIKE '%x="0" y="0" width="200"%' AND (j->>'items')::int = 1
                        FROM (SELECT bd_region_svg(pg_temp.u('b1'), NULL, NULL, NULL, NULL, 400, pg_temp.u('F')) AS j) x), 'a frame read: its children, frame-relative');
CREATE TEMP TABLE ol AS SELECT bd_board_outline(pg_temp.u('b1')) AS t;
SELECT pg_temp.check((SELECT t LIKE '# Q4 launch retro%' AND t LIKE E'%## Went well\n- [sticky, light_green] Shipped late%'
                             AND t LIKE '%## On the canvas%[shape: rectangle]%' AND t LIKE '%## Connections%Shipped late → shape%' FROM ol),
                     'the outline: frames as headings, items as lines, the canvas, the connections');
SELECT pg_temp.check(bd_version_outline(pg_temp.u('b1'), 1) LIKE '# Q4 launch retro%Shipped late%', 'a version as its outline');
SELECT pg_temp.check(NOT EXISTS (SELECT 1 FROM mcp_boards_usage), 'usage is the admin''s alone');
SELECT pg_temp.as_member(27);
SELECT pg_temp.check((SELECT count(*) FROM mcp_boards_usage) >= 3 AND NOT EXISTS (SELECT 1 FROM mcp_boards_usage WHERE title = 'Brainstorm'),
                     '…who sees every working board, not the shipped templates');
RESET ROLE;
SELECT pg_temp.check((SELECT c.collname FROM pg_attribute a JOIN pg_collation c ON c.oid = a.attcollation
                       WHERE a.attrelid = 'board_items'::regclass AND a.attname = 'z') = 'C', 'z sorts byte-wise (a fractional index), never by the locale');
SELECT pg_temp.check((SELECT array_agg(z ORDER BY z) FROM board_items WHERE id IN (pg_temp.u('X'), pg_temp.u('F'))) = ARRAY['B0', 'a0'],
                     '…so B0 sorts before a0');

\o
SELECT CASE WHEN ok THEN 'ok   ' ELSE 'FAIL ' END || label FROM proof ORDER BY n;
SELECT ' ' || count(*) FILTER (WHERE ok) || ' ok, ' || count(*) FILTER (WHERE NOT ok) || ' failed' FROM proof;
DO $$ BEGIN IF EXISTS (SELECT 1 FROM proof WHERE NOT ok) THEN RAISE EXCEPTION 'the schema proof failed'; END IF; END $$;
ROLLBACK;
