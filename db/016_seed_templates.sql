-- 016: THE SIX SHIPPED TEMPLATES (templates-export.md "The seed") — slice 8. Shipped templates are boards with is_template, template_scope 'shipped', owner and creator NULL; every member who is not
-- external sees them at `view` (db/006's wall), and a shipped template never changes (db/015 keeps interval versions off them). Idempotent: every INSERT is ON CONFLICT (id) DO NOTHING, so applying it again
-- changes nothing. Board ids 7e000000-0000-4000-8000-00000000000N; an item 7e00000N-0000-4000-8000-0000000000KK (KK the row's number in hex). A child's x, y are its centre relative to its frame's
-- top-left (db/007). Per template three INSERTs in this order: its frames; its other items; its connectors (each needs what it stands on). Generated, then read by tests/phase3/slice8/templates.php.
BEGIN;

-- 1. Retrospective (retrospective)
INSERT INTO boards (id, title, description, owner_member_id, is_template, template_scope, template_category, created_by)
VALUES ('7e000000-0000-4000-8000-000000000001', 'Retrospective', 'Look back on a piece of work: what went well, what to improve, who does what next.', NULL, true, 'shipped', 'retrospective', NULL)
ON CONFLICT (id) DO NOTHING;
INSERT INTO board_items (id, board_id, type, parent_id, x, y, width, height, z, style, data, start_item_id, end_item_id, text_version)
VALUES
    ('7e000001-0000-4000-8000-000000000002', '7e000000-0000-4000-8000-000000000001', 'frame', NULL, -650, 0, 600, 900, 'a1', '{}'::jsonb, '{"title":"Went well"}'::jsonb, NULL, NULL, 0),
    ('7e000001-0000-4000-8000-000000000003', '7e000000-0000-4000-8000-000000000001', 'frame', NULL, 0, 0, 600, 900, 'a2', '{}'::jsonb, '{"title":"To improve"}'::jsonb, NULL, NULL, 0),
    ('7e000001-0000-4000-8000-000000000004', '7e000000-0000-4000-8000-000000000001', 'frame', NULL, 650, 0, 600, 900, 'a3', '{}'::jsonb, '{"title":"Actions"}'::jsonb, NULL, NULL, 0)
ON CONFLICT (id) DO NOTHING;
INSERT INTO board_items (id, board_id, type, parent_id, x, y, width, height, z, style, data, start_item_id, end_item_id, text_version)
VALUES
    ('7e000001-0000-4000-8000-000000000001', '7e000000-0000-4000-8000-000000000001', 'text', NULL, 0, -520, 800, 80, 'a0', '{"fontSize":48}'::jsonb, jsonb_build_object('content', bd_rich_text('Retrospective')), NULL, NULL, 1),
    ('7e000001-0000-4000-8000-000000000005', '7e000000-0000-4000-8000-000000000001', 'sticky_note', '7e000001-0000-4000-8000-000000000002', 130, 130, 200, 200, 'a4', '{"fillColor":"light_green"}'::jsonb, jsonb_build_object('content', bd_rich_text('What went well?')), NULL, NULL, 1),
    ('7e000001-0000-4000-8000-000000000006', '7e000000-0000-4000-8000-000000000001', 'sticky_note', '7e000001-0000-4000-8000-000000000003', 130, 130, 200, 200, 'a5', '{"fillColor":"light_pink"}'::jsonb, jsonb_build_object('content', bd_rich_text('What could be better?')), NULL, NULL, 1),
    ('7e000001-0000-4000-8000-000000000007', '7e000000-0000-4000-8000-000000000001', 'sticky_note', '7e000001-0000-4000-8000-000000000004', 130, 130, 200, 200, 'a6', '{"fillColor":"light_yellow"}'::jsonb, jsonb_build_object('content', bd_rich_text('Who does what, by when?')), NULL, NULL, 1)
ON CONFLICT (id) DO NOTHING;

-- 2. Brainstorm (brainstorm)
INSERT INTO boards (id, title, description, owner_member_id, is_template, template_scope, template_category, created_by)
VALUES ('7e000000-0000-4000-8000-000000000002', 'Brainstorm', 'Collect ideas on one question, one per sticky, and park what is off topic.', NULL, true, 'shipped', 'brainstorm', NULL)
ON CONFLICT (id) DO NOTHING;
INSERT INTO board_items (id, board_id, type, parent_id, x, y, width, height, z, style, data, start_item_id, end_item_id, text_version)
VALUES
    ('7e000002-0000-4000-8000-000000000002', '7e000000-0000-4000-8000-000000000002', 'frame', NULL, -300, 0, 1600, 1000, 'a1', '{}'::jsonb, '{"title":"Ideas"}'::jsonb, NULL, NULL, 0),
    ('7e000002-0000-4000-8000-000000000003', '7e000000-0000-4000-8000-000000000002', 'frame', NULL, 900, 0, 600, 1000, 'a2', '{}'::jsonb, '{"title":"Parking lot"}'::jsonb, NULL, NULL, 0)
ON CONFLICT (id) DO NOTHING;
INSERT INTO board_items (id, board_id, type, parent_id, x, y, width, height, z, style, data, start_item_id, end_item_id, text_version)
VALUES
    ('7e000002-0000-4000-8000-000000000001', '7e000000-0000-4000-8000-000000000002', 'text', NULL, 0, -620, 1000, 80, 'a0', '{"fontSize":40}'::jsonb, jsonb_build_object('content', bd_rich_text('What should we brainstorm?')), NULL, NULL, 1),
    ('7e000002-0000-4000-8000-000000000004', '7e000000-0000-4000-8000-000000000002', 'sticky_note', '7e000002-0000-4000-8000-000000000002', 130, 130, 200, 200, 'a3', '{"fillColor":"light_yellow"}'::jsonb, jsonb_build_object('content', bd_rich_text('One idea per sticky')), NULL, NULL, 1),
    ('7e000002-0000-4000-8000-000000000005', '7e000000-0000-4000-8000-000000000002', 'sticky_note', '7e000002-0000-4000-8000-000000000002', 370, 130, 200, 200, 'a4', '{"fillColor":"light_yellow"}'::jsonb, jsonb_build_object('content', bd_rich_text('Build on others'' ideas')), NULL, NULL, 1),
    ('7e000002-0000-4000-8000-000000000006', '7e000000-0000-4000-8000-000000000002', 'sticky_note', '7e000002-0000-4000-8000-000000000002', 610, 130, 200, 200, 'a5', '{"fillColor":"light_yellow"}'::jsonb, jsonb_build_object('content', bd_rich_text('Quantity first, judge later')), NULL, NULL, 1),
    ('7e000002-0000-4000-8000-000000000007', '7e000000-0000-4000-8000-000000000002', 'sticky_note', '7e000002-0000-4000-8000-000000000003', 130, 130, 200, 200, 'a6', '{"fillColor":"gray"}'::jsonb, jsonb_build_object('content', bd_rich_text('Off topic, worth keeping')), NULL, NULL, 1)
ON CONFLICT (id) DO NOTHING;

-- 3. SWOT analysis (strategy)
INSERT INTO boards (id, title, description, owner_member_id, is_template, template_scope, template_category, created_by)
VALUES ('7e000000-0000-4000-8000-000000000003', 'SWOT analysis', 'Strengths, weaknesses, opportunities and threats, one quadrant each.', NULL, true, 'shipped', 'strategy', NULL)
ON CONFLICT (id) DO NOTHING;
INSERT INTO board_items (id, board_id, type, parent_id, x, y, width, height, z, style, data, start_item_id, end_item_id, text_version)
VALUES
    ('7e000003-0000-4000-8000-000000000002', '7e000000-0000-4000-8000-000000000003', 'frame', NULL, -375, -325, 700, 600, 'a1', '{}'::jsonb, '{"title":"Strengths"}'::jsonb, NULL, NULL, 0),
    ('7e000003-0000-4000-8000-000000000003', '7e000000-0000-4000-8000-000000000003', 'frame', NULL, 375, -325, 700, 600, 'a2', '{}'::jsonb, '{"title":"Weaknesses"}'::jsonb, NULL, NULL, 0),
    ('7e000003-0000-4000-8000-000000000004', '7e000000-0000-4000-8000-000000000003', 'frame', NULL, -375, 325, 700, 600, 'a3', '{}'::jsonb, '{"title":"Opportunities"}'::jsonb, NULL, NULL, 0),
    ('7e000003-0000-4000-8000-000000000005', '7e000000-0000-4000-8000-000000000003', 'frame', NULL, 375, 325, 700, 600, 'a4', '{}'::jsonb, '{"title":"Threats"}'::jsonb, NULL, NULL, 0)
ON CONFLICT (id) DO NOTHING;
INSERT INTO board_items (id, board_id, type, parent_id, x, y, width, height, z, style, data, start_item_id, end_item_id, text_version)
VALUES
    ('7e000003-0000-4000-8000-000000000001', '7e000000-0000-4000-8000-000000000003', 'text', NULL, 0, -760, 800, 80, 'a0', '{"fontSize":48}'::jsonb, jsonb_build_object('content', bd_rich_text('SWOT analysis')), NULL, NULL, 1),
    ('7e000003-0000-4000-8000-000000000006', '7e000000-0000-4000-8000-000000000003', 'sticky_note', '7e000003-0000-4000-8000-000000000002', 130, 130, 200, 200, 'a5', '{"fillColor":"light_green"}'::jsonb, jsonb_build_object('content', bd_rich_text('What do we do well?')), NULL, NULL, 1),
    ('7e000003-0000-4000-8000-000000000007', '7e000000-0000-4000-8000-000000000003', 'sticky_note', '7e000003-0000-4000-8000-000000000003', 130, 130, 200, 200, 'a6', '{"fillColor":"light_yellow"}'::jsonb, jsonb_build_object('content', bd_rich_text('Where do we fall short?')), NULL, NULL, 1),
    ('7e000003-0000-4000-8000-000000000008', '7e000000-0000-4000-8000-000000000003', 'sticky_note', '7e000003-0000-4000-8000-000000000004', 130, 130, 200, 200, 'a7', '{"fillColor":"light_blue"}'::jsonb, jsonb_build_object('content', bd_rich_text('What could we use?')), NULL, NULL, 1),
    ('7e000003-0000-4000-8000-000000000009', '7e000000-0000-4000-8000-000000000003', 'sticky_note', '7e000003-0000-4000-8000-000000000005', 130, 130, 200, 200, 'a8', '{"fillColor":"light_pink"}'::jsonb, jsonb_build_object('content', bd_rich_text('What could hurt us?')), NULL, NULL, 1)
ON CONFLICT (id) DO NOTHING;

-- 4. User story map (planning)
INSERT INTO boards (id, title, description, owner_member_id, is_template, template_scope, template_category, created_by)
VALUES ('7e000000-0000-4000-8000-000000000004', 'User story map', 'Activities across the top, stories under them, sliced into releases.', NULL, true, 'shipped', 'planning', NULL)
ON CONFLICT (id) DO NOTHING;
INSERT INTO board_items (id, board_id, type, parent_id, x, y, width, height, z, style, data, start_item_id, end_item_id, text_version)
VALUES
    ('7e000004-0000-4000-8000-000000000002', '7e000000-0000-4000-8000-000000000004', 'frame', NULL, 0, -600, 2400, 300, 'a1', '{}'::jsonb, '{"title":"Activities"}'::jsonb, NULL, NULL, 0),
    ('7e000004-0000-4000-8000-000000000003', '7e000000-0000-4000-8000-000000000004', 'frame', NULL, 0, -100, 2400, 500, 'a2', '{}'::jsonb, '{"title":"Release 1"}'::jsonb, NULL, NULL, 0),
    ('7e000004-0000-4000-8000-000000000004', '7e000000-0000-4000-8000-000000000004', 'frame', NULL, 0, 500, 2400, 500, 'a3', '{}'::jsonb, '{"title":"Release 2"}'::jsonb, NULL, NULL, 0)
ON CONFLICT (id) DO NOTHING;
INSERT INTO board_items (id, board_id, type, parent_id, x, y, width, height, z, style, data, start_item_id, end_item_id, text_version)
VALUES
    ('7e000004-0000-4000-8000-000000000001', '7e000000-0000-4000-8000-000000000004', 'text', NULL, 0, -900, 1200, 80, 'a0', '{"fontSize":36}'::jsonb, jsonb_build_object('content', bd_rich_text('Persona: who are we building for?')), NULL, NULL, 1),
    ('7e000004-0000-4000-8000-000000000005', '7e000000-0000-4000-8000-000000000004', 'sticky_note', '7e000004-0000-4000-8000-000000000002', 300, 150, 200, 200, 'a4', '{"fillColor":"light_blue"}'::jsonb, jsonb_build_object('content', bd_rich_text('Activity 1')), NULL, NULL, 1),
    ('7e000004-0000-4000-8000-000000000006', '7e000000-0000-4000-8000-000000000004', 'sticky_note', '7e000004-0000-4000-8000-000000000002', 900, 150, 200, 200, 'a5', '{"fillColor":"light_blue"}'::jsonb, jsonb_build_object('content', bd_rich_text('Activity 2')), NULL, NULL, 1),
    ('7e000004-0000-4000-8000-000000000007', '7e000000-0000-4000-8000-000000000004', 'sticky_note', '7e000004-0000-4000-8000-000000000002', 1500, 150, 200, 200, 'a6', '{"fillColor":"light_blue"}'::jsonb, jsonb_build_object('content', bd_rich_text('Activity 3')), NULL, NULL, 1),
    ('7e000004-0000-4000-8000-000000000008', '7e000000-0000-4000-8000-000000000004', 'sticky_note', '7e000004-0000-4000-8000-000000000002', 2100, 150, 200, 200, 'a7', '{"fillColor":"light_blue"}'::jsonb, jsonb_build_object('content', bd_rich_text('Activity 4')), NULL, NULL, 1),
    ('7e000004-0000-4000-8000-000000000009', '7e000000-0000-4000-8000-000000000004', 'sticky_note', '7e000004-0000-4000-8000-000000000003', 300, 150, 200, 200, 'a8', '{"fillColor":"light_yellow"}'::jsonb, jsonb_build_object('content', bd_rich_text('User story')), NULL, NULL, 1),
    ('7e000004-0000-4000-8000-00000000000a', '7e000000-0000-4000-8000-000000000004', 'sticky_note', '7e000004-0000-4000-8000-000000000003', 900, 150, 200, 200, 'a9', '{"fillColor":"light_yellow"}'::jsonb, jsonb_build_object('content', bd_rich_text('User story')), NULL, NULL, 1),
    ('7e000004-0000-4000-8000-00000000000b', '7e000000-0000-4000-8000-000000000004', 'sticky_note', '7e000004-0000-4000-8000-000000000004', 300, 150, 200, 200, 'aA', '{"fillColor":"light_yellow"}'::jsonb, jsonb_build_object('content', bd_rich_text('User story')), NULL, NULL, 1),
    ('7e000004-0000-4000-8000-00000000000c', '7e000000-0000-4000-8000-000000000004', 'sticky_note', '7e000004-0000-4000-8000-000000000004', 900, 150, 200, 200, 'aB', '{"fillColor":"light_yellow"}'::jsonb, jsonb_build_object('content', bd_rich_text('User story')), NULL, NULL, 1)
ON CONFLICT (id) DO NOTHING;

-- 5. Flowchart (diagram)
INSERT INTO boards (id, title, description, owner_member_id, is_template, template_scope, template_category, created_by)
VALUES ('7e000000-0000-4000-8000-000000000005', 'Flowchart', 'A start, steps, a decision and an end, joined by connectors — edit the words and add steps.', NULL, true, 'shipped', 'diagram', NULL)
ON CONFLICT (id) DO NOTHING;
INSERT INTO board_items (id, board_id, type, parent_id, x, y, width, height, z, style, data, start_item_id, end_item_id, text_version)
VALUES
    ('7e000005-0000-4000-8000-000000000001', '7e000000-0000-4000-8000-000000000005', 'shape', NULL, 0, 0, 200, 100, 'a0', '{"fillColor":"white","borderColor":"#1a1a1a"}'::jsonb, jsonb_build_object('shape', 'ellipse', 'content', bd_rich_text('Start')), NULL, NULL, 1),
    ('7e000005-0000-4000-8000-000000000002', '7e000000-0000-4000-8000-000000000005', 'shape', NULL, 0, 250, 240, 120, 'a1', '{"fillColor":"white","borderColor":"#1a1a1a"}'::jsonb, jsonb_build_object('shape', 'rectangle', 'content', bd_rich_text('Step')), NULL, NULL, 1),
    ('7e000005-0000-4000-8000-000000000003', '7e000000-0000-4000-8000-000000000005', 'shape', NULL, 0, 520, 240, 160, 'a2', '{"fillColor":"white","borderColor":"#1a1a1a"}'::jsonb, jsonb_build_object('shape', 'diamond', 'content', bd_rich_text('Decision?')), NULL, NULL, 1),
    ('7e000005-0000-4000-8000-000000000004', '7e000000-0000-4000-8000-000000000005', 'shape', NULL, -320, 800, 240, 120, 'a3', '{"fillColor":"white","borderColor":"#1a1a1a"}'::jsonb, jsonb_build_object('shape', 'rectangle', 'content', bd_rich_text('Do this')), NULL, NULL, 1),
    ('7e000005-0000-4000-8000-000000000005', '7e000000-0000-4000-8000-000000000005', 'shape', NULL, 320, 800, 240, 120, 'a4', '{"fillColor":"white","borderColor":"#1a1a1a"}'::jsonb, jsonb_build_object('shape', 'rectangle', 'content', bd_rich_text('Do that')), NULL, NULL, 1),
    ('7e000005-0000-4000-8000-000000000006', '7e000000-0000-4000-8000-000000000005', 'shape', NULL, 0, 1060, 200, 100, 'a5', '{"fillColor":"white","borderColor":"#1a1a1a"}'::jsonb, jsonb_build_object('shape', 'ellipse', 'content', bd_rich_text('End')), NULL, NULL, 1)
ON CONFLICT (id) DO NOTHING;
INSERT INTO board_items (id, board_id, type, parent_id, x, y, width, height, z, style, data, start_item_id, end_item_id, text_version)
VALUES
    ('7e000005-0000-4000-8000-000000000007', '7e000000-0000-4000-8000-000000000005', 'connector', NULL, 0, 0, 0, 0, 'a6', '{"endStrokeCap":"arrow"}'::jsonb, '{"shape":"elbowed"}'::jsonb, '7e000005-0000-4000-8000-000000000001', '7e000005-0000-4000-8000-000000000002', 0),
    ('7e000005-0000-4000-8000-000000000008', '7e000000-0000-4000-8000-000000000005', 'connector', NULL, 0, 0, 0, 0, 'a7', '{"endStrokeCap":"arrow"}'::jsonb, '{"shape":"elbowed"}'::jsonb, '7e000005-0000-4000-8000-000000000002', '7e000005-0000-4000-8000-000000000003', 0),
    ('7e000005-0000-4000-8000-000000000009', '7e000000-0000-4000-8000-000000000005', 'connector', NULL, 0, 0, 0, 0, 'a8', '{"endStrokeCap":"arrow"}'::jsonb, '{"shape":"elbowed","captions":[{"content":"yes"}]}'::jsonb, '7e000005-0000-4000-8000-000000000003', '7e000005-0000-4000-8000-000000000004', 0),
    ('7e000005-0000-4000-8000-00000000000a', '7e000000-0000-4000-8000-000000000005', 'connector', NULL, 0, 0, 0, 0, 'a9', '{"endStrokeCap":"arrow"}'::jsonb, '{"shape":"elbowed","captions":[{"content":"no"}]}'::jsonb, '7e000005-0000-4000-8000-000000000003', '7e000005-0000-4000-8000-000000000005', 0),
    ('7e000005-0000-4000-8000-00000000000b', '7e000000-0000-4000-8000-000000000005', 'connector', NULL, 0, 0, 0, 0, 'aA', '{"endStrokeCap":"arrow"}'::jsonb, '{"shape":"elbowed"}'::jsonb, '7e000005-0000-4000-8000-000000000004', '7e000005-0000-4000-8000-000000000006', 0),
    ('7e000005-0000-4000-8000-00000000000c', '7e000000-0000-4000-8000-000000000005', 'connector', NULL, 0, 0, 0, 0, 'aB', '{"endStrokeCap":"arrow"}'::jsonb, '{"shape":"elbowed"}'::jsonb, '7e000005-0000-4000-8000-000000000005', '7e000005-0000-4000-8000-000000000006', 0)
ON CONFLICT (id) DO NOTHING;

-- 6. Weekly planning (planning)
INSERT INTO boards (id, title, description, owner_member_id, is_template, template_scope, template_category, created_by)
VALUES ('7e000000-0000-4000-8000-000000000006', 'Weekly planning', 'This week''s goals and a column a day.', NULL, true, 'shipped', 'planning', NULL)
ON CONFLICT (id) DO NOTHING;
INSERT INTO board_items (id, board_id, type, parent_id, x, y, width, height, z, style, data, start_item_id, end_item_id, text_version)
VALUES
    ('7e000006-0000-4000-8000-000000000002', '7e000000-0000-4000-8000-000000000006', 'frame', NULL, 0, -800, 2450, 300, 'a1', '{}'::jsonb, '{"title":"Goals this week"}'::jsonb, NULL, NULL, 0),
    ('7e000006-0000-4000-8000-000000000003', '7e000000-0000-4000-8000-000000000006', 'frame', NULL, -1000, 0, 450, 1100, 'a2', '{}'::jsonb, '{"title":"Monday"}'::jsonb, NULL, NULL, 0),
    ('7e000006-0000-4000-8000-000000000004', '7e000000-0000-4000-8000-000000000006', 'frame', NULL, -500, 0, 450, 1100, 'a3', '{}'::jsonb, '{"title":"Tuesday"}'::jsonb, NULL, NULL, 0),
    ('7e000006-0000-4000-8000-000000000005', '7e000000-0000-4000-8000-000000000006', 'frame', NULL, 0, 0, 450, 1100, 'a4', '{}'::jsonb, '{"title":"Wednesday"}'::jsonb, NULL, NULL, 0),
    ('7e000006-0000-4000-8000-000000000006', '7e000000-0000-4000-8000-000000000006', 'frame', NULL, 500, 0, 450, 1100, 'a5', '{}'::jsonb, '{"title":"Thursday"}'::jsonb, NULL, NULL, 0),
    ('7e000006-0000-4000-8000-000000000007', '7e000000-0000-4000-8000-000000000006', 'frame', NULL, 1000, 0, 450, 1100, 'a6', '{}'::jsonb, '{"title":"Friday"}'::jsonb, NULL, NULL, 0)
ON CONFLICT (id) DO NOTHING;
INSERT INTO board_items (id, board_id, type, parent_id, x, y, width, height, z, style, data, start_item_id, end_item_id, text_version)
VALUES
    ('7e000006-0000-4000-8000-000000000001', '7e000000-0000-4000-8000-000000000006', 'text', NULL, 0, -1050, 800, 80, 'a0', '{"fontSize":48}'::jsonb, jsonb_build_object('content', bd_rich_text('Week of …')), NULL, NULL, 1),
    ('7e000006-0000-4000-8000-000000000008', '7e000000-0000-4000-8000-000000000006', 'sticky_note', '7e000006-0000-4000-8000-000000000002', 130, 150, 200, 200, 'a7', '{"fillColor":"light_green"}'::jsonb, jsonb_build_object('content', bd_rich_text('Goal')), NULL, NULL, 1),
    ('7e000006-0000-4000-8000-000000000009', '7e000000-0000-4000-8000-000000000006', 'sticky_note', '7e000006-0000-4000-8000-000000000002', 370, 150, 200, 200, 'a8', '{"fillColor":"light_green"}'::jsonb, jsonb_build_object('content', bd_rich_text('Goal')), NULL, NULL, 1),
    ('7e000006-0000-4000-8000-00000000000a', '7e000000-0000-4000-8000-000000000006', 'sticky_note', '7e000006-0000-4000-8000-000000000002', 610, 150, 200, 200, 'a9', '{"fillColor":"light_green"}'::jsonb, jsonb_build_object('content', bd_rich_text('Goal')), NULL, NULL, 1),
    ('7e000006-0000-4000-8000-00000000000b', '7e000000-0000-4000-8000-000000000006', 'sticky_note', '7e000006-0000-4000-8000-000000000003', 225, 130, 200, 200, 'aA', '{"fillColor":"light_yellow"}'::jsonb, jsonb_build_object('content', bd_rich_text('Plan the week')), NULL, NULL, 1)
ON CONFLICT (id) DO NOTHING;

-- db/010 keeps a shipped template's board out of the search index; its items' words ("Goal", "Step") are taken out here too, so that a search finds a person's boards and not the gallery's.
DELETE FROM search_index WHERE board_id IN (SELECT id FROM boards WHERE is_template AND template_scope = 'shipped');

COMMIT;
