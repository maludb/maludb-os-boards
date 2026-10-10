-- 004: Boards' roles and the rights they give (kernel db/145; roles-and-rights.md; the design §2, §3, D2).
--
-- Published to the kernel by app_roles on the records MCP (os.app-roles/1). The kernel grants a SET of roles; the claims and
-- the feed carry them back into members.roles. What a role lets its holder do is the application's to enforce:
-- bd_has_right(right) answers from the catalogue and the mirror. The base role's KEY IS `user` on purpose (Member): the
-- installer's --grant-standing-departments gives a department the role keyed member/user/write.
--
-- Rights are application-wide capabilities. WHICH BOARD a caller opens, and at what level, is NOT a right: it is the board's level
-- for the caller (view / comment / edit / full — the owner, the board's permission rows for the member, their departments and
-- `everyone`; design §3), answered by bd_board_level() (db/006+). The ROLE only CAPS that level: bd_level_cap() — a Viewer, whose
-- roles give no boards.edit, never rises above `comment` however a board is shared with them. Three roles (D2): Viewer, Member
-- (user), Boards admin (is_admin — `full` on every board).
--
-- THE ESTATE'S DATA MODEL (design §6.7): the shape is Consultant Tracking db/004 = DocCloud db/004 = Sales CRM db/004 = the Data Lake
-- db/004 (dl_ -> bd_); the roles and rights are this application's; bd_member_roles(member) is Inventory db/004's convenience (the
-- roles a member holds, resolved); bd_member_has_right(member, right) the Data Lake's; bd_level_cap(member) is new here.
BEGIN;

CREATE TABLE bd_rights (
    right_key   text PRIMARY KEY CHECK (right_key ~ '^[a-z][a-z0-9_.]{0,59}$'),
    description text NOT NULL,
    sort_order  integer NOT NULL DEFAULT 0
);
CREATE TABLE bd_roles (
    role_key    text PRIMARY KEY CHECK (role_key ~ '^[a-z][a-z0-9_]{0,39}$'),
    name        text NOT NULL,
    description text NOT NULL,
    capability  text NOT NULL CHECK (capability IN ('read', 'write', 'admin')),
    is_admin    boolean NOT NULL DEFAULT false,
    sort_order  integer NOT NULL DEFAULT 0,
    CHECK (NOT is_admin OR capability = 'admin')
);
CREATE UNIQUE INDEX bd_roles_one_admin ON bd_roles (is_admin) WHERE is_admin;
CREATE TABLE bd_role_rights (
    role_key  text NOT NULL REFERENCES bd_roles(role_key) ON DELETE CASCADE,
    right_key text NOT NULL REFERENCES bd_rights(right_key) ON DELETE CASCADE,
    PRIMARY KEY (role_key, right_key)
);

INSERT INTO bd_rights (right_key, description, sort_order) VALUES
 -- every role (design §2)
 ('boards.view',       'Open the boards shared with them, at the level given (a Viewer at most `comment`); the outline, the history they may see, favourites, recents and folders', 1),
 ('boards.comment',    'Comment on a board where their level is `comment` or higher: pins on an item or a point, replies, resolve, mentions', 2),
 ('boards.export',     'Export what they may open: a PNG of a view, a frame or a selection; the board''s SVG', 3),
 ('tokens.own',        'Mint and revoke their own tokens for the two read MCP servers (a client''s own AI tool reads what they may)', 4),
 -- Member
 ('boards.create',     'Create boards, folders and personal templates; duplicate a board they may open; use a template', 5),
 ('boards.edit',       'Work on a board where their level is `edit` or `full`: items, connectors, frames, uploads, versions; share and delete where `full`', 6),
 -- Boards admin
 ('templates.publish', 'Publish a board as a business template, seen by every Member (pauses for an agent)', 7),
 ('boards.admin',      'Open and manage every board as `full`: restore, transfer a departed person''s boards, read usage against the budget', 8),
 ('trash.purge',       'Empty the trash before its 30 days (pauses for an agent)', 9),
 ('settings.manage',   'Run Boards: the defaults (a new board''s `everyone` level), the limits, the retention windows', 10),
 ('agents.manage',     'See every agent''s dispatches (Retry a failed one) and link to the kernel for hires and grants', 11);

INSERT INTO bd_roles (role_key, name, description, capability, is_admin, sort_order) VALUES
 ('viewer', 'Viewer',       'Opens the boards shared with them and comments where a board allows it; never draws, never creates. Their level on any board is capped at `comment` — for an external adviser, a client contact with an OS account, a reviewer.', 'read', false, 1),
 ('user',   'Member',       'Creates boards, folders and personal templates, and works on every board shared with them at the level given — view, comment, edit or full.', 'write', false, 2),
 ('admin',  'Boards admin', 'Every right: `full` on every board, business templates, the trash, usage against the budget, the settings, the agents'' dispatches. A super-admin holds this role.', 'admin', true, 3);

INSERT INTO bd_role_rights (role_key, right_key)
SELECT 'viewer', r FROM unnest(ARRAY['boards.view','boards.comment','boards.export','tokens.own']) r
UNION ALL SELECT 'user', r FROM unnest(ARRAY['boards.view','boards.comment','boards.export','tokens.own','boards.create','boards.edit']) r
UNION ALL SELECT 'admin', right_key FROM bd_rights;

-- The roles a member holds, resolved: the kernel's list; a super-admin always holds admin; a member the kernel has not yet sent
-- roles for is read from the capability (admin = admin, write = user, read = viewer — unlike the Data Lake, Boards HAS a read-only
-- role, so a read grant never draws). Empty for nobody.
CREATE OR REPLACE FUNCTION bd_member_roles(p_member_id bigint) RETURNS text[]
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE r text[];
BEGIN
    SELECT CASE
             WHEN m.member_kind = 'human' AND m.business_role = 'super_admin' THEN (SELECT array_agg(DISTINCT x) FROM unnest(m.roles || ARRAY['admin']) x)
             WHEN cardinality(m.roles) > 0 THEN m.roles
             ELSE ARRAY[CASE m.capability WHEN 'admin' THEN 'admin' WHEN 'write' THEN 'user' ELSE 'viewer' END]
           END
      INTO r
      FROM members m
     WHERE m.id = p_member_id AND m.status = 'active' AND m.capability IS NOT NULL;
    RETURN COALESCE(r, '{}');
END$$;

-- A right a given member's roles give (the member must be active and admitted).
CREATE OR REPLACE FUNCTION bd_member_has_right(p_member_id bigint, p_right text) RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    RETURN EXISTS (
        SELECT 1 FROM bd_role_rights rr
         WHERE rr.right_key = p_right AND rr.role_key = ANY (bd_member_roles(p_member_id)));
END$$;

-- The rule: a right the acting member's roles give.
CREATE OR REPLACE FUNCTION bd_has_right(p_right text) RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    RETURN bd_member_has_right(app_current_member_id(), p_right);
END$$;

-- The ceiling a member's ROLE puts on any board level (design §3): `full` when a role they hold gives boards.edit, `comment`
-- when they are admitted with none (a Viewer: comments where a board allows it, never draws), NULL for nobody (not active or not
-- admitted — no level at all, fail closed). bd_board_level() (db/006+) takes the lower of a board's level and this. One query.
CREATE OR REPLACE FUNCTION bd_level_cap(p_member_id bigint) RETURNS text
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE r text[];
BEGIN
    r := bd_member_roles(p_member_id);
    IF cardinality(r) = 0 THEN
        RETURN NULL;
    END IF;
    RETURN CASE WHEN EXISTS (SELECT 1 FROM bd_role_rights rr WHERE rr.right_key = 'boards.edit' AND rr.role_key = ANY (r))
                THEN 'full' ELSE 'comment' END;
END$$;

-- Runs Boards: the super-admin, or a human holding boards.admin (`full` on every board).
CREATE OR REPLACE FUNCTION bd_is_admin() RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    RETURN app_is_super_admin() OR (app_member_kind() = 'human' AND bd_has_right('boards.admin'));
END$$;

-- Someone who belongs in the business here: admitted (any role; an agent granted a role too).
CREATE OR REPLACE FUNCTION bd_is_member_here() RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    RETURN app_is_active_member();
END$$;

-- What app_roles answers, in one read.
CREATE OR REPLACE VIEW mcp_app_roles AS
SELECT r.role_key, r.name, r.description, r.capability, r.is_admin, r.sort_order,
       COALESCE((SELECT array_agg(rr.right_key ORDER BY h.sort_order) FROM bd_role_rights rr
                   JOIN bd_rights h ON h.right_key = rr.right_key WHERE rr.role_key = r.role_key), '{}') AS rights
FROM bd_roles r;

GRANT SELECT ON bd_rights, bd_roles, bd_role_rights, mcp_app_roles TO boards_rw, boards_records_ro;
GRANT EXECUTE ON FUNCTION bd_member_roles(bigint), bd_member_has_right(bigint, text), bd_has_right(text), bd_level_cap(bigint), bd_is_admin(), bd_is_member_here()
    TO boards_rw, boards_records_ro, boards_activity_ro;

COMMIT;
