"""Boards, folders, the trash, templates, usage, people and departments — the records tools that are views of whole boards (docs/boards-mcp-tool-surface.md). Every row is the mcp_* view's, as the asking member: a board the
wall hides is `not_found`, the same words as one that never existed. find_boards, find_templates, find_folders, find_members and find_departments are the kernel's entity resolvers (deploy/kernel-registry-boards.json):
called with `q` alone they answer {rows:[…]} carrying the id and the label field the registry names, the exact name first; a UUID given as `q` is an id lookup (the kernel's own resolver treats only digit strings as ids).
"""
from __future__ import annotations

from pydantic import Field

from bd_common import (RO, Ref, Refusal, Where, _Base, answers, board_row, department_id_of, detail, exact_first, folder_id_of, is_boards_admin, is_int, is_uuid, like, listing, member_id_of, off, resolving, url_of, when)


class FindBoardsIn(_Base):
    q: str | None = Field(None, description="A fragment of the board's title, or the board's id (a UUID). Called with q alone this is the entity resolver: the exact title first")
    folder: Ref | None = Field(None, description="Only boards kept in this folder (its id or its name)")
    owner: Ref | None = Field(None, description="Only boards owned by this member (an id, a name or me)")
    shared_with_me: bool | None = Field(None, description="true: only boards someone else owns that are shared with me (a person, my department or everyone)")
    favorites: bool | None = Field(None, description="true: only my favourites")
    templates: bool | None = Field(None, description="true: templates only (find_templates is the fuller list); omitted: boards, never templates")
    updated_since: str | None = Field(None, description="Only boards with activity on or after this day (2026-03-02) or ISO time")
    limit: int = Field(25, ge=1, le=100, description="How many boards at most (100 is the cap)")
    offset: int = Field(0, ge=0, le=10000, description="Skip this many (paging)")


class BoardIn(_Base):
    board: Ref = Field(..., description="The board: its id (a UUID) or its title")


class FindFoldersIn(_Base):
    q: str | None = Field(None, description="A fragment of the folder's name (a digit string is the folder's id). Called with q alone this is the entity resolver")
    team: bool | None = Field(None, description="true: team folders only; false: my own folders only")
    limit: int = Field(25, ge=1, le=100, description="How many folders at most")


class TrashIn(_Base):
    limit: int = Field(25, ge=1, le=100, description="How many boards at most")


class FindTemplatesIn(_Base):
    q: str | None = Field(None, description="A fragment of the template's title, or its id. Called with q alone this is the entity resolver")
    category: str | None = Field(None, max_length=60, description="Only this category (retrospective, planning, workshop …)")
    scope: str | None = Field(None, pattern=r"^(shipped|business|personal)$", description="shipped, business or personal")
    limit: int = Field(25, ge=1, le=100, description="How many templates at most")


class UsageIn(_Base):
    sort: str = Field("items", pattern=r"^(items|idle|open_threads)$", description="items (most items first), idle (idle longest first) or open_threads")
    idle_days: int | None = Field(None, ge=0, le=3650, description="Only boards idle at least this many days")
    owner_left: bool | None = Field(None, description="true: only boards whose owner has left the business")
    limit: int = Field(25, ge=1, le=100, description="How many boards at most")


class FindMembersIn(_Base):
    q: str | None = Field(None, description="A fragment of the person's or agent's name (a digit string is the member id; me is the asker). Called with q alone this is the entity resolver")
    kind: str | None = Field(None, pattern=r"^(human|agent)$", description="human or agent")
    department: Ref | None = Field(None, description="Only members of this department (id or name)")
    limit: int = Field(25, ge=1, le=100, description="How many members at most")


class FindDepartmentsIn(_Base):
    q: str | None = Field(None, description="A fragment of the department's name (a digit string is its id). Called with q alone this is the entity resolver")
    limit: int = Field(25, ge=1, le=100, description="How many departments at most")


BOARD_COLS = "b.board_id, b.title, b.owner_name, b.my_level, b.item_count, b.open_threads, b.last_activity_at, b.is_favorite, b.is_template"


def register(mcp, q) -> None:
    @mcp.tool(name="find_boards", annotations={"title": "Find boards", **RO})
    @answers
    async def find_boards(params: FindBoardsIn) -> str:
        """The boards you can open (Q1), newest activity first: board_id, title, owner_name, my_level (view, comment, edit or full), item_count, open_threads, last_activity_at, is_favorite, is_template. Filter by title fragment, folder,
        owner, shared with me, favourites, updated since — or give a board's id as q. Templates are left out unless templates=true. The first read when a person names a board: take its board_id for the other tools."""
        p = params
        w = Where()
        if p.q and is_uuid(p.q):
            w.add("b.board_id = {}::uuid", p.q.lower())
        else:
            w.raw("b.is_template" if p.templates else "NOT b.is_template")
            if p.q:
                w.add("b.title ILIKE {}", like(p.q))
        if p.folder not in (None, ""):
            w.add("EXISTS (SELECT 1 FROM mcp_folder_entries e WHERE e.board_id = b.board_id AND e.folder_id = {})", await folder_id_of(q, p.folder))
        if p.owner not in (None, ""):
            w.add("b.owner_member_id = {}", await member_id_of(q, p.owner, "owner"))
        if p.shared_with_me:
            w.raw("""b.owner_member_id IS DISTINCT FROM app_current_member_id() AND EXISTS (SELECT 1 FROM mcp_board_permissions x WHERE x.board_id = b.board_id AND (
                       (x.principal_kind IN ('member', 'agent') AND x.principal_id = app_current_member_id())
                    OR (x.principal_kind = 'department' AND x.principal_id = ANY (COALESCE((SELECT m.department_ids FROM mcp_members m WHERE m.member_id = app_current_member_id()), '{}')))
                    OR x.principal_kind = 'everyone'))""")
        if p.favorites:
            w.raw("b.is_favorite")
        if p.updated_since:
            w.add("b.last_activity_at >= {}", when(p.updated_since, "updated_since"))
        rows = await q(f"SELECT {BOARD_COLS} FROM mcp_boards b WHERE {w.sql()} ORDER BY b.last_activity_at DESC, b.title, b.board_id LIMIT {p.limit + 1} OFFSET {off(p.offset)}", *w.args)
        if resolving(p):
            rows = exact_first(rows, p.q, "title")
        if not rows and p.q and is_uuid(p.q):
            # a board in the TRASH named by its id (the kernel's resolver turns board_restore / trash_purge's `board` into an id through this tool, and the trash is not in mcp_boards): the owner's own, or a Boards admin's
            rows = await q("""SELECT t.board_id, t.title, o.display_name AS owner_name, NULL::text AS my_level, t.item_count, NULL::int AS open_threads, t.deleted_at AS last_activity_at, false AS is_favorite, false AS is_template, t.deleted_at
                                FROM mcp_trash t LEFT JOIN mcp_members o ON o.member_id = t.owner_member_id WHERE t.board_id = $1::uuid""", p.q.lower())
        return listing(rows, p.limit)

    @mcp.tool(name="board_get", annotations={"title": "A board's facts", **RO})
    @answers
    async def board_get(params: BoardIn) -> str:
        """What a board is (Q2): title, description, owner, my_level, whether it is locked, item_count against the soft and hard budget, the counts by item type, open comment threads, the folders it is kept in, the start view, the last change (seq, who, when)
        and its URL. Always follow with board_overview to see its shape, then board_search or board_read_region to read part of it."""
        b = await board_row(q, params.board)
        ov = (await q("SELECT bd_board_overview($1::uuid) AS o", str(b["board_id"])))[0]["o"] or {}
        folders = await q("""SELECT f.folder_id, f.name, f.is_team FROM mcp_folder_entries e JOIN mcp_folders f ON f.folder_id = e.folder_id WHERE e.board_id = $1::uuid ORDER BY f.name""", str(b["board_id"]))
        last = ov.get("last_change")
        return detail(
            board_id=b["board_id"], title=b["title"], description=b["description"], owner={"member_id": b["owner_member_id"], "name": b["owner_name"]}, my_level=b["my_level"], is_locked=b["is_locked"],
            is_template=b["is_template"], template_scope=b["template_scope"], template_category=b["template_category"], is_favorite=b["is_favorite"],
            item_count=b["item_count"], soft_budget=ov.get("soft_budget"), hard_cap=ov.get("hard_cap"), counts_by_type=ov.get("totals_by_type") or {}, open_threads=b["open_threads"],
            folders=folders, start_view=b["start_view"], last_change=({"seq": last.get("seq"), "who": last.get("member_name"), "kind": last.get("member_kind"), "source": last.get("source"), "at": last.get("at")} if last else None),
            last_activity_at=b["last_activity_at"], created_at=b["created_at"], url=url_of(b["board_id"]))

    @mcp.tool(name="board_access", annotations={"title": "Who can open a board", **RO})
    @answers
    async def board_access(params: BoardIn) -> str:
        """Who can open a board and at which level (Q10): the owner, the level everyone has, and every person, agent and department it is shared with (principal_kind, principal_id, principal_name, level, granted_by, created_at). Levels are
        view, comment, edit and full; a person's own level is also capped by their role. You need to be able to open the board."""
        b = await board_row(q, params.board)
        rows = await q("""SELECT p.principal_kind, p.principal_id, p.principal_name, p.level, p.granted_by, g.display_name AS granted_by_name, p.created_at
                            FROM mcp_board_permissions p LEFT JOIN mcp_members g ON g.member_id = p.granted_by
                           WHERE p.board_id = $1::uuid ORDER BY (p.principal_kind = 'everyone') DESC, p.principal_kind, p.principal_name, p.principal_id""", str(b["board_id"]))
        every = next((r["level"] for r in rows if r["principal_kind"] == "everyone"), "none")
        return detail(board_id=b["board_id"], title=b["title"], owner={"member_id": b["owner_member_id"], "name": b["owner_name"]}, everyone_level=every, rows=rows)

    @mcp.tool(name="find_folders", annotations={"title": "Find folders", **RO})
    @answers
    async def find_folders(params: FindFoldersIn) -> str:
        """The folders you can use (Q13): your own and the team folders — folder_id, name, is_team, owner_member_id, board_count (the boards in it you may open). Give a name fragment, or only q: the entity resolver."""
        p = params
        w = Where()
        if p.q:
            if is_int(p.q):
                w.add("f.folder_id = {}", int(p.q))
            else:
                w.add("f.name ILIKE {}", like(p.q))
        if p.team is not None:
            w.add("f.is_team = {}", p.team)
        rows = await q(f"SELECT f.folder_id, f.name, f.is_team, f.owner_member_id, f.board_count FROM mcp_folders f WHERE {w.sql()} ORDER BY f.is_team, f.name, f.folder_id LIMIT {p.limit + 1}", *w.args)
        if resolving(p):
            rows = exact_first(rows, p.q, "name")
        return listing(rows, p.limit)

    @mcp.tool(name="trash_list", annotations={"title": "The trash", **RO})
    @answers
    async def trash_list(params: TrashIn | None = None) -> str:
        """The boards in the trash (yours; a Boards admin sees every one): board_id, title, item_count, deleted_at, deleted_by, purge_after (when the nightly purge takes it for good). Restoring is an action, not a read."""
        p = params or TrashIn()
        rows = await q(f"""SELECT t.board_id, t.title, t.item_count, t.deleted_at, t.deleted_by, d.display_name AS deleted_by_name, t.purge_after
                             FROM mcp_trash t LEFT JOIN mcp_members d ON d.member_id = t.deleted_by ORDER BY t.deleted_at DESC LIMIT {p.limit + 1}""")
        return listing(rows, p.limit)

    @mcp.tool(name="find_templates", annotations={"title": "Find templates", **RO})
    @answers
    async def find_templates(params: FindTemplatesIn) -> str:
        """Board templates you may use (Q12): board_id, title, template_scope (shipped with Boards, the business's, or your own), template_category, item_count, owner_name. Use a template with the template_use action. Called with q alone: the entity resolver."""
        p = params
        w = Where().raw("b.is_template")
        if p.q and is_uuid(p.q):
            w.add("b.board_id = {}::uuid", p.q.lower())
        elif p.q:
            w.add("b.title ILIKE {}", like(p.q))
        if p.category:
            w.add("lower(b.template_category) = lower({})", p.category)
        if p.scope:
            w.add("b.template_scope = {}", p.scope)
        rows = await q(f"""SELECT b.board_id, b.title, b.template_scope, b.template_category, b.item_count, b.owner_name FROM mcp_boards b WHERE {w.sql()}
                            ORDER BY (b.template_scope = 'shipped') DESC, b.template_scope, b.title, b.board_id LIMIT {p.limit + 1}""", *w.args)
        if resolving(p):
            rows = exact_first(rows, p.q, "title")
        return listing(rows, p.limit)

    @mcp.tool(name="boards_usage", annotations={"title": "Board usage against the budget", **RO})
    @answers
    async def boards_usage(params: UsageIn | None = None) -> str:
        """Usage of every board against the item budget (Q16) — a Boards admin's: soft_budget and hard_cap, and per board its item_count, over_soft, idle_days, open_threads, last_activity_at, the owner and whether the owner is still active. Find idle boards
        (idle_days) and boards whose owner has left (owner_left). Shipped templates are left out."""
        p = params or UsageIn()
        if not await is_boards_admin(q):
            raise Refusal("not_allowed", "Board usage is a Boards admin's to read.")
        w = Where()
        if p.idle_days is not None:
            w.add("u.idle_days >= {}", p.idle_days)
        if p.owner_left:
            w.raw("NOT COALESCE(u.owner_active, false)")
        order = {"items": "u.item_count DESC", "idle": "u.idle_days DESC", "open_threads": "u.open_threads DESC"}[p.sort]
        rows = await q(f"""SELECT u.board_id, u.title, u.owner_name, u.owner_active, u.is_template, u.item_count, u.over_soft, u.idle_days, u.open_threads, u.last_activity_at
                             FROM mcp_boards_usage u WHERE {w.sql()} ORDER BY {order}, u.title LIMIT {p.limit + 1}""", *w.args)
        s = (await q("SELECT bd_setting_int('item_soft_budget') AS soft, bd_setting_int('item_hard_cap') AS hard"))[0]
        return listing(rows, p.limit, soft_budget=s["soft"], hard_cap=s["hard"])

    @mcp.tool(name="find_members", annotations={"title": "Find people and agents", **RO})
    @answers
    async def find_members(params: FindMembersIn) -> str:
        """The people and agents of the business (Q17): member_id, display_name, member_kind (human or agent), roles_resolved (the Boards roles they hold) and department_ids. An external sees only themselves. Called with q alone: the entity resolver for a person."""
        p = params
        w = Where()
        if p.q:
            if p.q.strip().lower() == "me":
                w.raw("m.member_id = app_current_member_id()")
            elif is_int(p.q):
                w.add("m.member_id = {}", int(p.q))
            else:
                w.add("m.display_name ILIKE {}", like(p.q))
        if p.kind:
            w.add("m.member_kind = {}", p.kind)
        if p.department not in (None, ""):
            w.add("{} = ANY (COALESCE(m.department_ids, '{{}}'))", await department_id_of(q, p.department))
        rows = await q(f"SELECT m.member_id, m.display_name, m.member_kind, m.roles_resolved, m.department_ids FROM mcp_members m WHERE {w.sql()} ORDER BY m.display_name, m.member_id LIMIT {p.limit + 1}", *w.args)
        if resolving(p):
            rows = exact_first(rows, p.q, "display_name")
        return listing(rows, p.limit)

    @mcp.tool(name="find_departments", annotations={"title": "Find departments", **RO})
    @answers
    async def find_departments(params: FindDepartmentsIn) -> str:
        """The departments of the business (Q17): department_id, name, parent_id. A board can be shared with a department. Called with q alone: the entity resolver."""
        p = params
        w = Where().raw("d.archived_at IS NULL")
        if p.q:
            if is_int(p.q):
                w.add("d.department_id = {}", int(p.q))
            else:
                w.add("d.name ILIKE {}", like(p.q))
        rows = await q(f"SELECT d.department_id, d.name, d.parent_id FROM mcp_departments d WHERE {w.sql()} ORDER BY d.name, d.department_id LIMIT {p.limit + 1}", *w.args)
        if resolving(p):
            rows = exact_first(rows, p.q, "name")
        return listing(rows, p.limit)
