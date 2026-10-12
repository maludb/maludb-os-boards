"""Comments, notifications, agents' dispatches and the guarded search (docs/boards-mcp-tool-surface.md, Q9, Q15, Q18). The rows are mcp_comments / mcp_notifications / mcp_agent_dispatches / mcp_search (db/012): the board's wall is
the view's, a comment on a board the caller cannot open does not exist for them, the notifications are the asker's own, the dispatches a Boards admin's or the asker's own. records_search takes WORDS (a tsquery built here by
websearch_to_tsquery, never raw SQL) and returns rows only on boards the caller may open.
"""
from __future__ import annotations

from pydantic import Field

from bd_common import RO, Ref, Where, _Base, answers, board_row, detail, dumps, excerpt, like, listing, member_id_of, not_found, uid, url_of


class FindCommentsIn(_Base):
    board: Ref | None = Field(None, description="Only this board (its id or title); omitted: every board you may open")
    open: bool | None = Field(None, description="true: threads not yet resolved; false: resolved ones")
    mentions_me: bool | None = Field(None, description="true: threads where the root or a reply mentions you")
    unanswered: bool | None = Field(None, description="true: threads with no reply and not resolved")
    author: Ref | None = Field(None, description="Only threads started by this person or agent (id, name or me)")
    q: str | None = Field(None, description="Words in the thread's text (the root or a reply)")
    limit: int = Field(25, ge=1, le=100, description="How many threads at most")
    offset: int = Field(0, ge=0, le=10000, description="Skip this many (paging)")


class ThreadIn(_Base):
    comment: str = Field(..., description="Any comment of the thread (its id, a UUID, from find_comments)")


class NotificationsIn(_Base):
    unread_only: bool | None = Field(None, description="true: only the ones you have not read")
    limit: int = Field(25, ge=1, le=100, description="How many at most")


class DispatchesIn(_Base):
    status: str | None = Field(None, pattern=r"^(sent|awaiting_approval|answered|refused|failed)$", description="sent, awaiting_approval, answered, refused or failed")
    agent: Ref | None = Field(None, description="Only this agent (id or name)")
    board: Ref | None = Field(None, description="Only this board (id or title)")
    limit: int = Field(25, ge=1, le=100, description="How many at most")


class SearchIn(_Base):
    q: str = Field(..., min_length=2, max_length=200, description="Words to find (every word must match; quotes and OR work as in a web search). Not SQL")
    kind: str | None = Field(None, pattern=r"^(board|item|comment)$", description="Only boards, items or comments")
    board: Ref | None = Field(None, description="Only this board (id or title)")
    limit: int = Field(25, ge=1, le=50, description="How many results at most (50 is the cap)")


def register(mcp, q) -> None:
    @mcp.tool(name="find_comments", annotations={"title": "Find comment threads", **RO})
    @answers
    async def find_comments(params: FindCommentsIn) -> str:
        """Comment threads on boards you may open (Q9), newest first, one row per thread: comment_id (the root), board_id, board_title, item_id (what it is pinned to, or null for a point on the canvas), author_name, an excerpt, replies, resolved_at,
        created_at. Filter by board, open or resolved, mentions you, unanswered, author or words. Read a whole thread with comment_thread."""
        p = params
        w = Where().raw("c.parent_comment_id IS NULL")
        if p.board not in (None, ""):
            w.add("c.board_id = {}::uuid", str((await board_row(q, p.board))["board_id"]))
        if p.open is True:
            w.raw("c.resolved_at IS NULL")
        elif p.open is False:
            w.raw("c.resolved_at IS NOT NULL")
        if p.mentions_me:
            w.raw("""EXISTS (SELECT 1 FROM mcp_comments t WHERE t.thread_id = c.comment_id
                       AND app_current_member_id() = ANY (bd_rich_text_mentions(t.body, 'member') || bd_rich_text_mentions(t.body, 'agent')))""")
        if p.unanswered:
            w.raw("c.resolved_at IS NULL AND NOT EXISTS (SELECT 1 FROM mcp_comments r WHERE r.parent_comment_id = c.comment_id)")
        if p.author not in (None, ""):
            w.add("c.author_member_id = {}", await member_id_of(q, p.author, "author"))
        if p.q:
            for word in p.q.split()[:8]:
                w.add("EXISTS (SELECT 1 FROM mcp_comments t WHERE t.thread_id = c.comment_id AND t.plain_text ILIKE {})", like(word))
        rows = await q(f"""SELECT c.comment_id, c.board_id, b.title AS board_title, c.item_id, c.author_name, c.plain_text, c.author_kind,
                                  (SELECT count(*) FROM mcp_comments r WHERE r.parent_comment_id = c.comment_id)::int AS replies, c.resolved_at, c.created_at
                             FROM mcp_comments c JOIN mcp_boards b ON b.board_id = c.board_id WHERE {w.sql()}
                            ORDER BY c.created_at DESC, c.comment_id LIMIT {p.limit + 1} OFFSET {p.offset}""", *w.args)
        for r in rows:
            r["excerpt"] = excerpt(r.pop("plain_text"), 160)
        return listing(rows, p.limit)

    @mcp.tool(name="comment_thread", annotations={"title": "A comment thread", **RO})
    @answers
    async def comment_thread(params: ThreadIn) -> str:
        """One whole thread (Q9): the root (author, text, where it is pinned — anchor item_id or x, y — and the items it was written beside), the replies in order (author, whether an agent, text, created_at) and whether it is resolved. Pass the id of any comment
        in the thread."""
        cid = uid(params.comment, "comment")
        row = await q("SELECT thread_id FROM mcp_comments WHERE comment_id = $1::uuid", cid)
        if not row:
            raise not_found("comment")
        tid = str(row[0]["thread_id"])
        root = (await q("""SELECT c.comment_id, c.board_id, b.title AS board_title, c.item_id, c.author_name, c.author_kind, c.plain_text, c.anchor_x, c.anchor_y, c.context_item_ids, c.resolved_at, c.created_at, c.edited_at
                             FROM mcp_comments c JOIN mcp_boards b ON b.board_id = c.board_id WHERE c.comment_id = $1::uuid""", tid))
        if not root:
            raise not_found("comment")
        r = root[0]
        ctx = await q("""SELECT i.item_id, i.type, COALESCE(NULLIF(i.plain_text, ''), i.data->>'title') AS words FROM mcp_board_items i WHERE i.item_id = ANY ($1::uuid[]) AND i.board_id = $2::uuid ORDER BY i.z, i.item_id""",
                      [str(x) for x in (r["context_item_ids"] or [])], str(r["board_id"]))
        replies = await q("""SELECT c.comment_id, c.author_name, c.author_kind, c.plain_text AS text, c.created_at FROM mcp_comments c WHERE c.parent_comment_id = $1::uuid ORDER BY c.created_at, c.comment_id""", tid)
        return detail(
            root={"comment_id": r["comment_id"], "board_id": r["board_id"], "board_title": r["board_title"], "author_name": r["author_name"], "author_kind": r["author_kind"], "text": r["plain_text"],
                  "anchor": {"item_id": r["item_id"], "x": r["anchor_x"], "y": r["anchor_y"]}, "context_items": [{"item_id": c["item_id"], "type": c["type"], "excerpt": excerpt(c["words"], 80)} for c in ctx],
                  "created_at": r["created_at"], "edited_at": r["edited_at"], "url": url_of(r["board_id"], "?comment=" + str(r["comment_id"]))},
            replies=replies, resolved_at=r["resolved_at"])

    @mcp.tool(name="my_notifications", annotations={"title": "My notifications", **RO})
    @answers
    async def my_notifications(params: NotificationsIn | None = None) -> str:
        """Your own notifications (Q15), newest first: notification_id, kind (mention, reply, share, agent_replied …), title, body, board_id, who caused it (actor), read_at, created_at. Nobody else's are ever readable."""
        p = params or NotificationsIn()
        w = Where()
        if p.unread_only:
            w.raw("n.read_at IS NULL")
        rows = await q(f"""SELECT n.notification_id, n.kind, n.title, n.body, n.board_id, a.display_name AS actor, n.read_at, n.created_at FROM mcp_notifications n
                             LEFT JOIN mcp_members a ON a.member_id = n.actor_member_id WHERE {w.sql()} ORDER BY n.created_at DESC, n.notification_id DESC LIMIT {p.limit + 1}""")
        return listing(rows, p.limit)

    @mcp.tool(name="agent_dispatches", annotations={"title": "Mentions of agents and what became of them", **RO})
    @answers
    async def agent_dispatches(params: DispatchesIn | None = None) -> str:
        """The comments that mentioned an agent and what became of each: dispatch_id, board_title, comment_id, agent_name, acting_name (who asked), status (sent, awaiting_approval, answered, refused, failed), reply_excerpt, attempts, created_at, answered_at.
        A Boards admin sees every one; anyone else the ones they asked for."""
        p = params or DispatchesIn()
        w = Where()
        if p.status:
            w.add("d.status = {}", p.status)
        if p.agent not in (None, ""):
            w.add("d.agent_member_id = {}", await member_id_of(q, p.agent, "agent"))
        if p.board not in (None, ""):
            w.add("d.board_id = {}::uuid", str((await board_row(q, p.board))["board_id"]))
        rows = await q(f"""SELECT d.dispatch_id, d.board_id, bd.title AS board_title, d.comment_id, ag.display_name AS agent_name, ac.display_name AS acting_name, d.status, d.reply_excerpt, d.attempts, d.created_at, d.answered_at
                             FROM mcp_agent_dispatches d LEFT JOIN mcp_boards bd ON bd.board_id = d.board_id LEFT JOIN mcp_members ag ON ag.member_id = d.agent_member_id LEFT JOIN mcp_members ac ON ac.member_id = d.acting_member_id
                            WHERE {w.sql()} ORDER BY d.created_at DESC, d.dispatch_id DESC LIMIT {p.limit + 1}""", *w.args)
        return listing(rows, p.limit)

    @mcp.tool(name="records_search", annotations={"title": "Search boards, items and comments", **RO})
    @answers
    async def records_search(params: SearchIn) -> str:
        """Search everything you may open by words (Q18): board titles and descriptions, item texts and frame titles, comment texts. Rows: kind (board, item or comment), id, board_id, board_title, item_type, title, excerpt, occurred_at, rank — best first.
        The words are matched as a web search would (every word, "quoted phrases", OR); this is not SQL. Templates are left out. Then read the board with board_overview or board_read_region."""
        p = params
        w = Where().raw("NOT b.is_template").add("s.tsv @@ websearch_to_tsquery('simple', unaccent({}))", p.q[:200])
        if p.kind:
            w.add("s.entity_kind = {}", p.kind)
        if p.board not in (None, ""):
            w.add("s.board_id = {}::uuid", str((await board_row(q, p.board))["board_id"]))
        rank = w.p(p.q[:200])
        rows = await q(f"""SELECT s.entity_kind AS kind, s.entity_uuid AS id, s.board_id, b.title AS board_title, s.item_type, s.title, left(s.body, 220) AS body, s.occurred_at,
                                  ts_rank_cd(s.tsv, websearch_to_tsquery('simple', unaccent({rank}))) AS rank
                             FROM mcp_search s JOIN mcp_boards b ON b.board_id = s.board_id WHERE {w.sql()} ORDER BY rank DESC, s.occurred_at DESC, s.entity_uuid LIMIT {p.limit}""", *w.args)
        for r in rows:
            r["excerpt"] = excerpt(r.pop("body"), 200)
            r["rank"] = round(float(r["rank"]), 4)
            if r["kind"] == "board":
                r["title"] = r["board_title"]
        return dumps({"ok": True, "count": len(rows), "rows": rows})
