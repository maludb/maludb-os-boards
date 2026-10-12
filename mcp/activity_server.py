"""boards_activity_mcp — Boards' activity-memory server (activity_log through mcp_activity_log, read-only).

Tool surface: docs/boards-mcp-tool-surface.md, "Activity server". Every query runs as the asking member; the view decides which rows they see: the events of boards they can open (the board's wall, bd_my_board_levels) and the events that concern
no board — which this server narrows further to the caller's own, unless they are a Boards admin (a token minted, a directory sync is nobody else's business). The activity role reads mcp_activity_log plus mcp_members, mcp_departments, mcp_boards
and mcp_folders for names beside a row, and nothing else. A comment's text, a token's value and a file's path are never in a payload (the writers' rule of db/002), so nothing here can return one. The log is the same rows the tenant's MaluDB holds as
`activity` episodes (mcp/activity_ingest.py ships them): the tools read the local log, which is the wall's, rather than the memory, which has none (the same decision as Spaces and Shareholder Intelligence).
"""
from __future__ import annotations

from mcp.server.fastmcp import FastMCP
from pydantic import Field

import db
from bd_common import RO, Ref, Refusal, Where, _Base, ago, answers, board_row, is_int, like, listing, member_id_of, not_found, uid, when, words_of

mcp = FastMCP("boards_activity_mcp")

# Rows that concern no board are the caller's own, or a Boards admin's to see (the view admits them to every insider).
TIGHT = "(l.board_id IS NOT NULL OR l.actor_member_id = app_current_member_id() OR (SELECT bd_is_boards_admin(app_current_member_id())))"
WHO = "COALESCE(m.display_name, CASE WHEN l.actor_member_id IS NULL THEN 'the system' ELSE 'someone' END)"
NOISE = "l.action <> 'screen.view'"
WORDS = {
    "board.create": "created the board", "board.update": "changed the board", "board.delete": "moved the board to the trash", "board.restore": "restored the board", "board.duplicate": "duplicated a board", "board.lock": "locked or unlocked the board",
    "board.share": "shared the board", "board.unshare": "stopped sharing the board", "board.access_set": "changed who can open the board", "board.transfer": "handed the board to someone", "board.favorite": "starred the board",
    "board.unfavorite": "unstarred the board", "board.open": "opened the board", "board.export": "exported the board", "item.create": "added items", "item.update": "changed items", "item.move": "moved items", "item.delete": "deleted items",
    "item.batch": "changed items", "item.text": "edited an item's text", "item.connect": "connected items", "item.frame": "made a frame", "ops.undo": "undid a change", "comment.create": "started a comment thread", "comment.reply": "replied in a thread",
    "comment.resolve": "resolved a thread", "comment.reopen": "reopened a thread", "comment.edit": "edited a comment", "comment.delete": "deleted a comment", "version.save": "saved a version", "version.restore": "restored a version",
    "template.use": "made a board from a template", "template.save": "saved a template", "template.publish": "published a template", "file.upload": "added a file", "file.open": "opened a file", "file.purge": "removed a file",
    "folder.create": "made a folder", "folder.update": "renamed a folder", "folder.delete": "deleted a folder", "folder.add": "put a board in a folder", "folder.remove": "took a board out of a folder", "agent.dispatch": "mentioned an agent",
    "agent.reply": "answered a comment", "dispatch.retry": "retried an agent's answer", "settings.save": "changed the settings", "trash.purge": "emptied a board from the trash", "share.read": "read a share for another application",
    "token.mint": "made an access token", "token.revoke": "revoked an access token", "member.sign_on": "signed on",
}
COLS = f"""l.log_id, l.occurred_at AS at, {WHO} AS member_name, m.member_kind, l.actor_member_id, l.source, l.action, l.entity_type, l.board_id, l.after->'counts' AS counts, l.agent_run_id AS run_id"""


class TrailIn(_Base):
    board: Ref = Field(..., description="The board: its id (a UUID) or its title")
    since: str | None = Field(None, description="Only on or after this day (2026-03-02) or ISO time")
    until: str | None = Field(None, description="Only before the end of this day or ISO time")
    limit: int = Field(50, ge=1, le=200, description="How many rows at most")


class MemberActivityIn(_Base):
    member: Ref = Field(..., description="A person or an agent (id, name or me)")
    since: str | None = Field(None, description="Only on or after this day or ISO time; default the last 30 days")
    until: str | None = Field(None, description="Only before the end of this day or ISO time")
    limit: int = Field(50, ge=1, le=200, description="How many rows at most")


class HistoryIn(_Base):
    kind: str = Field(..., pattern=r"^(board|comment|folder)$", description="board, comment or folder")
    id: str = Field(..., description="The record: a board's id or title, a comment's id (a UUID), a folder's id (a number)")
    limit: int = Field(50, ge=1, le=200, description="How many rows at most")


class MostActiveIn(_Base):
    since: str | None = Field(None, description="A day or ISO time; default the last 30 days")
    limit: int = Field(10, ge=1, le=50, description="How many boards at most")


class AgentActivityIn(_Base):
    agent: Ref | None = Field(None, description="Only this agent (id or name)")
    board: Ref | None = Field(None, description="Only this board (id or title)")
    since: str | None = Field(None, description="A day or ISO time; default the last 30 days")
    limit: int = Field(50, ge=1, le=200, description="How many rows at most")


class RecentIn(_Base):
    limit: int = Field(50, ge=1, le=200, description="How many rows at most")


class SearchIn(_Base):
    q: str = Field(..., min_length=2, max_length=200, description="Words to find in an action, a board's title, a name or a title the payloads carry, and the actor's name — each word must match")
    since: str | None = Field(None, description="A day or ISO time")
    limit: int = Field(25, ge=1, le=100, description="How many rows at most")


async def _pool():
    return await db.get_pool(db.ENV["MCP_ACTIVITY_DB_USER"], db.ENV["MCP_ACTIVITY_DB_PASSWORD"])


async def q(sql: str, *args) -> list[dict]:
    return await db.fetch_scoped(await _pool(), sql, *args)


def sentence(r: dict) -> str:
    return f"{r['member_name']} {WORDS.get(r['action'], str(r['action']).replace('.', ' ').replace('_', ' '))}"


def shape(rows: list[dict], with_summary: bool = False) -> list[dict]:
    for r in rows:
        r.pop("log_id", None)
        r.pop("actor_member_id", None)
        if with_summary:
            r["summary"] = sentence(r)
    return rows


@mcp.tool(name="board_trail", annotations={"title": "Who worked on a board", **RO})
@answers
async def board_trail(params: TrailIn) -> str:
    """Who opened or worked on a board (A1), newest first: at, member_name, member_kind (human or agent), action (item.update, comment.reply, board.share … — screen.view is someone opening a screen of it), counts by verb for an item batch, and source (web, agent,
    assistant …). You see it only for a board you can open."""
    b = await board_row(q, params.board)
    w = Where().add("l.board_id = {}::uuid", str(b["board_id"]))
    if params.since:
        w.add("l.occurred_at >= {}", when(params.since, "since"))
    if params.until:
        w.add("l.occurred_at < {}", when(params.until, "until", end_of_day=True))
    rows = await q(f"SELECT {COLS} FROM mcp_activity_log l LEFT JOIN mcp_members m ON m.member_id = l.actor_member_id WHERE {w.sql()} ORDER BY l.occurred_at DESC, l.log_id DESC LIMIT {params.limit + 1}", *w.args)
    return listing(shape(rows, True), params.limit, board_id=b["board_id"], title=b["title"])


@mcp.tool(name="member_activity", annotations={"title": "What a person or an agent did", **RO})
@answers
async def member_activity(params: MemberActivityIn) -> str:
    """What a person or an agent did on boards (A2) since a time (30 days by default), newest first: at, action, board_title, entity_type and a sentence — only on boards you can open, never screen views."""
    p = params
    target = await member_id_of(q, p.member)
    w = Where().add("l.actor_member_id = {}", target).raw("l.board_id IS NOT NULL").raw(NOISE).add("l.occurred_at >= {}", when(p.since, "since") if p.since else ago(30))
    if p.until:
        w.add("l.occurred_at < {}", when(p.until, "until", end_of_day=True))
    rows = await q(f"SELECT {COLS}, b.title AS board_title FROM mcp_activity_log l LEFT JOIN mcp_members m ON m.member_id = l.actor_member_id LEFT JOIN mcp_boards b ON b.board_id = l.board_id WHERE {w.sql()} ORDER BY l.occurred_at DESC, l.log_id DESC LIMIT {p.limit + 1}", *w.args)
    return listing(shape(rows, True), p.limit, member_id=target)


@mcp.tool(name="record_history", annotations={"title": "A record's history", **RO})
@answers
async def record_history(params: HistoryIn) -> str:
    """When a board was shared, renamed, locked, restored; when a comment was written, edited, resolved; when a folder was made or changed (A3), oldest last: at, member_name, action, before, after — ids, names and levels, never text. Give kind (board, comment or
    folder) and id. You see only records you can open."""
    p = params
    w = Where()
    if p.kind == "board":
        b = await board_row(q, p.id)
        w.add("l.board_id = {}::uuid", str(b["board_id"])).raw("l.entity_type = 'board'").raw("l.action NOT LIKE 'item.%'").raw("l.action NOT IN ('screen.view', 'ops.undo', 'board.open', 'file.open')")
        subject = {"board_id": b["board_id"], "title": b["title"]}
    elif p.kind == "comment":
        cid = uid(p.id, "id")
        w.raw("l.entity_type = 'comment'").add("(l.after->>'comment_id' = {} OR l.after->>'thread_id' = {})", cid, cid)
        subject = {"comment_id": cid}
    else:
        if not is_int(p.id):
            raise Refusal("invalid_argument", "A folder is named by its id (find_folders).", fields=["id"])
        fid = int(p.id)
        visible = await q("SELECT 1 AS ok FROM mcp_folders WHERE folder_id = $1", fid)
        w.raw("l.entity_type = 'folder'").add("l.entity_id = {}", fid)
        if not visible:
            w.raw("l.actor_member_id = app_current_member_id()")           # a deleted or a hidden folder: only what one did oneself
        subject = {"folder_id": fid}
    rows = await q(f"SELECT {COLS}, l.before, l.after FROM mcp_activity_log l LEFT JOIN mcp_members m ON m.member_id = l.actor_member_id WHERE {w.sql()} ORDER BY l.occurred_at DESC, l.log_id DESC LIMIT {p.limit + 1}", *w.args)
    if not rows and p.kind != "board":
        raise not_found(p.kind)
    return listing(shape(rows, True), p.limit, kind=p.kind, **subject)


@mcp.tool(name="most_active_boards", annotations={"title": "The busiest boards", **RO})
@answers
async def most_active_boards(params: MostActiveIn | None = None) -> str:
    """The boards with the most work lately (A4): board_id, title, batches (committed changes to items, undos and restores), members (who worked), last_at — over the last 30 days unless you give a start. Only boards you can open; templates are left out."""
    p = params or MostActiveIn()
    since = when(p.since, "since") if p.since else ago(30)
    rows = await q(f"""SELECT l.board_id, b.title, count(*) FILTER (WHERE l.action LIKE 'item.%' OR l.action IN ('ops.undo', 'version.restore'))::int AS batches,
                              count(DISTINCT l.actor_member_id)::int AS members, max(l.occurred_at) AS last_at
                         FROM mcp_activity_log l JOIN mcp_boards b ON b.board_id = l.board_id
                        WHERE l.occurred_at >= $1 AND {NOISE} AND NOT b.is_template GROUP BY l.board_id, b.title
                        ORDER BY batches DESC, last_at DESC, b.title LIMIT {p.limit + 1}""", since)
    return listing(rows, p.limit, since=since)


@mcp.tool(name="agent_activity", annotations={"title": "What agents did on boards", **RO})
@answers
async def agent_activity(params: AgentActivityIn | None = None) -> str:
    """What agents did on boards (A5), newest first: at, agent_name, action, board_title, counts and the run id — all agents or one, one board or all you can open, over the last 30 days unless you give a start. Screen views are left out."""
    p = params or AgentActivityIn()
    w = Where().raw("m.member_kind = 'agent'").raw(NOISE).raw(TIGHT).add("l.occurred_at >= {}", when(p.since, "since") if p.since else ago(30))
    if p.agent not in (None, ""):
        w.add("l.actor_member_id = {}", await member_id_of(q, p.agent, "agent"))
    if p.board not in (None, ""):
        w.add("l.board_id = {}::uuid", str((await board_row(q, p.board))["board_id"]))
    rows = await q(f"SELECT {COLS}, m.display_name AS agent_name, b.title AS board_title FROM mcp_activity_log l JOIN mcp_members m ON m.member_id = l.actor_member_id LEFT JOIN mcp_boards b ON b.board_id = l.board_id WHERE {w.sql()} ORDER BY l.occurred_at DESC, l.log_id DESC LIMIT {p.limit + 1}", *w.args)
    return listing(shape(rows, True), p.limit)


@mcp.tool(name="recent_activity", annotations={"title": "What changed lately", **RO})
@answers
async def recent_activity(params: RecentIn | None = None) -> str:
    """What happened lately across everything you can open (A6), newest first: at, member_name, action, board_title, counts and a sentence. Boards you cannot open are not here; screen views are left out; an event that concerns no board is shown only if it is yours."""
    p = params or RecentIn()
    w = Where().raw(NOISE).raw(TIGHT)
    rows = await q(f"SELECT {COLS}, b.title AS board_title FROM mcp_activity_log l LEFT JOIN mcp_members m ON m.member_id = l.actor_member_id LEFT JOIN mcp_boards b ON b.board_id = l.board_id WHERE {w.sql()} ORDER BY l.occurred_at DESC, l.log_id DESC LIMIT {p.limit + 1}", *w.args)
    return listing(shape(rows, True), p.limit)


@mcp.tool(name="activity_search", annotations={"title": "Search the trail", **RO})
@answers
async def activity_search(params: SearchIn) -> str:
    """The trail by a phrase (A6): each word is looked for in the action (board.share), the board's title, the actor's name and the names and titles the payloads carry; newest first, only rows you may see. These are the same events the tenant's memory holds as
    episodes tagged boards — filtered through the board wall, not ranked by a model."""
    p = params
    w = Where().raw(NOISE).raw(TIGHT)
    if p.since:
        w.add("l.occurred_at >= {}", when(p.since, "since"))
    for word in words_of(p.q):
        w.add("(l.action ILIKE {} OR COALESCE(b.title, '') ILIKE {} OR COALESCE(m.display_name, '') ILIKE {} OR COALESCE(l.after->>'title', '') ILIKE {} OR COALESCE(l.after->>'name', '') ILIKE {} OR COALESCE(l.after->>'label', '') ILIKE {} OR COALESCE(l.after->>'principal_name', '') ILIKE {})",
              *([like(word)] * 7))
    rows = await q(f"SELECT {COLS}, b.title AS board_title FROM mcp_activity_log l LEFT JOIN mcp_members m ON m.member_id = l.actor_member_id LEFT JOIN mcp_boards b ON b.board_id = l.board_id WHERE {w.sql()} ORDER BY l.occurred_at DESC, l.log_id DESC LIMIT {p.limit + 1}", *w.args)
    return listing(shape(rows, True), p.limit)


if __name__ == "__main__":
    import server_common
    server_common.run(mcp, "MCP_ACTIVITY_DB_USER", "MCP_ACTIVITY_DB_PASSWORD", port=int(db.ENV["MCP_ACTIVITY_PORT"]), endpoint_name="Activity MCP")
