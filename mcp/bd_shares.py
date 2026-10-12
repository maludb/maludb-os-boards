"""The two shares[] of maludb-os.json (design §4, D14; K7): boards_index (os.boards-index/1) and board_outline (os.boards-outline/1). Both are SQL, so they read the database directly — no door: boards_index through mcp_boards /
mcp_folder_entries and board_outline through bd_board_outline(), the same functions the screens read. THE KERNEL'S TOKEN reaches them (and app_roles) and nothing else; a share runs AS THE CONSUMING APPLICATION'S EXPERT AGENT, the member the
kernel names in X-OS-Consumer-Agent (K26, kernel db/173 — a header beside the token, never an argument; an `as_agent` or `member` argument is dropped), so the agent sees exactly the boards it could open itself (D11): the wall is the views'.
No agent named → boards_index answers EMPTY with a note and board_outline is refused; a member that is not an active, admitted agent → refused. A person or an agent calling board_outline reads as themselves (boards_index is the
kernel's alone). A kernel call logs `share.read` through db/019's bd_log_mcp_read(): source application, the consumer, the tool, the argument KEYS, the rows answered, the agent — never a title or an answer.
"""
from __future__ import annotations

import time

from pydantic import Field

import db
from bd_common import OUTLINE_MAX_BYTES, RO, Ref, Refusal, Where, _Base, answers, board_row, detail, dumps, like, not_found, url_of

SCHEMA_INDEX = "os.boards-index/1"
SCHEMA_OUTLINE = "os.boards-outline/1"


class BoardsIndexIn(_Base):
    q: str | None = Field(None, max_length=200, description="Words of a board's title")
    limit: int = Field(25, ge=1, le=100, description="How many boards at most (100 is the cap)")


class OutlineIn(_Base):
    board: Ref = Field(..., description="The board: its id (a UUID) or its title")


async def _pool():
    return await db.get_pool(db.ENV["MCP_RECORDS_DB_USER"], db.ENV["MCP_RECORDS_DB_PASSWORD"])


async def consumer_agent() -> int:
    """The member a kernel share runs as: the active, admitted AGENT the kernel named. Raises a Refusal otherwise (the caller decides what 'none named' means)."""
    agent = db.request_consumer_agent.get()
    if agent is None:
        raise Refusal("not_allowed", "No requesting expert agent was named by the kernel; a share is read as the consuming application's agent.")
    kind = (await db.fetch_scoped(await _pool(), "SELECT mcp_member_kind($1::bigint) AS k", agent, as_nobody=True))[0]["k"]
    if kind != "agent":
        raise Refusal("not_allowed", "The requesting agent is not an active agent member here; nothing was shared.")
    return agent


async def share_log(tool: str, given: dict, rows: int, agent: int | None, error: str | None, ms: int) -> None:
    """The kernel's read, on the trail: no board id on the row — a board's trail is for the people who can open the board, and a sibling's read is the admin's business, not theirs."""
    after = {"direction": "in", "application": db.request_consumer.get(), "tool": tool, "keys": sorted(k for k, v in given.items() if v is not None), "rows": rows, "agent_member_id": agent, "ms": ms}
    if error:
        after["error"] = error
    try:
        await db.fetch_scoped(await _pool(), "SELECT bd_log_mcp_read('share.read', $1::jsonb, NULL, $2::text)", after, db.request_kernel_request_id.get(), as_nobody=True)
    except Exception:  # noqa: BLE001 — the trail never costs the sibling its answer
        pass


def register(mcp, q) -> None:
    @mcp.tool(name="boards_index", annotations={"title": "Boards index (share)", **RO})
    @answers
    async def boards_index(params: BoardsIndexIn | None = None) -> str:
        """A shared tool (os.boards-index/1): the boards the requesting application's expert agent may open — board_id, title, owner_name, folder_names, item_count, updated_at, url — newest activity first; `q` searches titles. Templates are left out; nothing of a
        board's content. The kernel's token over an approved connection (the share runs as the consuming application's expert agent the kernel names in X-OS-Consumer-Agent; none named reads nothing)."""
        p = params or BoardsIndexIn()
        t0 = time.perf_counter()
        given = {k: getattr(p, k) for k in p.model_fields_set}
        kernel = db.request_is_kernel.get()
        agent = None
        if kernel:
            if db.request_consumer_agent.get() is None:
                await share_log("boards_index", given, 0, None, "no_agent", int((time.perf_counter() - t0) * 1000))
                return dumps({"schema": SCHEMA_INDEX, "application": "boards", "as_member": None, "count": 0, "rows": [], "note": "No requesting expert agent was named by the kernel: an application with no expert agent reads nothing."})
            try:
                agent = await consumer_agent()
            except Refusal as r:
                await share_log("boards_index", given, 0, db.request_consumer_agent.get(), r.code, int((time.perf_counter() - t0) * 1000))
                raise
        w = Where().raw("NOT b.is_template")
        if p.q:
            w.add("b.title ILIKE {}", like(p.q))
        sql = f"""SELECT b.board_id, b.title, b.owner_name, b.item_count, b.last_activity_at AS updated_at,
                         (SELECT COALESCE(array_agg(f.name ORDER BY f.name), '{{}}') FROM mcp_folder_entries e JOIN mcp_folders f ON f.folder_id = e.folder_id WHERE e.board_id = b.board_id) AS folder_names
                    FROM mcp_boards b WHERE {w.sql()} ORDER BY b.last_activity_at DESC, b.title, b.board_id LIMIT {p.limit + 1}"""
        rows = await (db.fetch_scoped(await _pool(), sql, *w.args, member_id=agent, role="agent") if agent is not None else q(sql, *w.args))
        truncated = len(rows) > p.limit
        rows = rows[:p.limit]
        for r in rows:
            r["url"] = url_of(r["board_id"])
        if kernel:
            await share_log("boards_index", given, len(rows), agent, None, int((time.perf_counter() - t0) * 1000))
        return dumps({"schema": SCHEMA_INDEX, "application": "boards", "as_member": agent, "count": len(rows), "truncated": truncated, "rows": rows})

    @mcp.tool(name="board_outline", annotations={"title": "A board as text", **RO})
    @answers
    async def board_outline(params: OutlineIn) -> str:
        """A whole board as text (Q6; also a K7 share, os.boards-outline/1): each frame a heading with its items as lines (- [sticky, light_yellow] text), the unframed items under "On the canvas", connectors as A → B (caption), and the open comments with
        their anchor — Markdown, at most 256 kB (over it: too_large; read a frame at a time). The cheapest way to understand a board in words. The kernel's token (as the expert agent the kernel names in X-OS-Consumer-Agent), or you for what you may open."""
        p = params
        t0 = time.perf_counter()
        given = {k: getattr(p, k) for k in p.model_fields_set}
        kernel = db.request_is_kernel.get()
        agent = None
        try:
            if kernel:
                agent = await consumer_agent()
                db_pool = await _pool()
                qa = lambda sql, *a: db.fetch_scoped(db_pool, sql, *a, member_id=agent, role="agent")   # noqa: E731
            else:
                qa = q
            b = await board_row(qa, p.board)
            text = (await qa("SELECT bd_board_outline($1::uuid) AS o", str(b["board_id"])))[0]["o"]
            if text is None:
                raise not_found("board")
            if len(text.encode("utf-8")) > OUTLINE_MAX_BYTES:
                raise Refusal("too_large", "That board's outline is more than 256 kB: read it a frame at a time (board_overview, then frame_read).")
        except Refusal as r:
            if kernel:
                await share_log("board_outline", given, 0, db.request_consumer_agent.get(), r.code, int((time.perf_counter() - t0) * 1000))
            raise
        if kernel:
            await share_log("board_outline", given, 1, agent, None, int((time.perf_counter() - t0) * 1000))
            return detail(schema=SCHEMA_OUTLINE, application="boards", as_member=agent, board_id=b["board_id"], title=b["title"], outline=text, url=url_of(b["board_id"]))
        return detail(board_id=b["board_id"], title=b["title"], outline=text, url=url_of(b["board_id"]))
