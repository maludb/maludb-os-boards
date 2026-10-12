"""Reading the canvas: the overview, search, a region or a frame as SVG, one item, what is connected, files, the board's changes and versions (docs/boards-mcp-tool-surface.md, Q2–Q8, Q11, Q14). A board is read
SEARCH → REGION, never whole: no tool returns every item. The rows are mcp_board_items / mcp_board_changes / mcp_board_versions / mcp_attachments (db/012) and the read functions bd_board_overview(), bd_region_svg() and
bd_version_outline() (db/013); the SVG dialect is bd_region_svg()'s and nothing here renders a word of it. The caps: a region of 400 items or 64 kB, an outline of 256 kB (`too_large`).
"""
from __future__ import annotations

import math

from pydantic import Field

from bd_common import (OUTLINE_MAX_BYTES, REGION_MAX_BYTES, RO, Ref, Refusal, Where, _Base, answers, board_row, detail, dumps, excerpt, item_row, like, listing, member_id_of, not_found, uid, url_of, when, words_of)


class BoardIn(_Base):
    board: Ref = Field(..., description="The board: its id (a UUID) or its title")


class SearchIn(_Base):
    board: Ref = Field(..., description="The board: its id (a UUID) or its title")
    text: str = Field(..., min_length=1, max_length=200, description="Words to find in item texts and frame titles; every word must appear")
    type: str | None = Field(None, pattern=r"^(sticky_note|shape|text|connector|frame|drawing|image|document)$", description="Only this item type")
    frame: str | None = Field(None, description="Only items inside this frame (the frame's item id)")
    limit: int = Field(25, ge=1, le=50, description="How many items at most (50 is the cap)")


class RegionIn(_Base):
    board: Ref = Field(..., description="The board: its id (a UUID) or its title")
    x1: float | None = Field(None, description="Left edge of the box, in canvas units (with y1, x2, y2)")
    y1: float | None = Field(None, description="Top edge of the box")
    x2: float | None = Field(None, description="Right edge of the box")
    y2: float | None = Field(None, description="Bottom edge of the box")
    around: str | None = Field(None, description="Instead of a box: an item id — its box padded by pad")
    pad: float = Field(200, ge=0, le=5000, description="With around: how far past the item's box to read, in canvas units")
    limit: int = Field(400, ge=1, le=400, description="How many items at most (400 is the cap)")


class FrameIn(_Base):
    board: Ref = Field(..., description="The board: its id (a UUID) or its title")
    frame: str = Field(..., description="The frame's item id")
    limit: int = Field(400, ge=1, le=400, description="How many items at most (400 is the cap)")


class ItemIn(_Base):
    item: str = Field(..., description="The item's id (a UUID), from board_search or board_read_region")


class FindAttachmentsIn(_Base):
    board: Ref = Field(..., description="The board: its id (a UUID) or its title")
    kind: str | None = Field(None, pattern=r"^(image|document)$", description="image or document")
    limit: int = Field(25, ge=1, le=100, description="How many files at most")


class ChangesIn(_Base):
    board: Ref = Field(..., description="The board: its id (a UUID) or its title")
    since: str | None = Field(None, description="Only changes on or after this day or ISO time")
    until: str | None = Field(None, description="Only changes before this day or ISO time")
    member: Ref | None = Field(None, description="Only changes by this person or agent (id, name or me)")
    source: str | None = Field(None, pattern=r"^(web|agent|command_bar|restore)$", description="web, agent, command_bar or restore")
    limit: int = Field(25, ge=1, le=100, description="How many changes at most (100 is the cap)")


class VersionsIn(_Base):
    board: Ref = Field(..., description="The board: its id (a UUID) or its title")
    limit: int = Field(25, ge=1, le=100, description="How many versions at most")


class VersionOutlineIn(_Base):
    board: Ref = Field(..., description="The board: its id (a UUID) or its title")
    version: int = Field(..., ge=1, description="The version number from board_versions")


def _centre(r: dict) -> tuple[float | None, float | None]:
    if r.get("x1") is None:
        return None, None
    return round((float(r["x1"]) + float(r["x2"])) / 2, 1), round((float(r["y1"]) + float(r["y2"])) / 2, 1)


def _drop_heavy(data):
    """An item's data for an agent: a drawing's points are counted, not listed; a path of the store is never shown."""
    if not isinstance(data, dict):
        return data
    out = {}
    for k, v in data.items():
        if k == "points" and isinstance(v, list):
            out["point_count"] = len(v) // 2
        elif "path" in k.lower():
            continue
        else:
            out[k] = v
    return out


def _caption(data) -> str | None:
    if not isinstance(data, dict):
        return None
    caps = data.get("captions")
    if isinstance(caps, list) and caps and isinstance(caps[0], dict) and caps[0].get("content"):
        return str(caps[0]["content"])
    return data.get("caption") or None


async def region_answer(q, board_id: str, x1, y1, x2, y2, limit: int, frame: str | None, what: str) -> str:
    r = (await q("SELECT bd_region_svg($1::uuid, $2::float8, $3::float8, $4::float8, $5::float8, $6::int, $7::uuid) AS r", board_id, x1, y1, x2, y2, limit, frame))[0]["r"]
    if r is None:
        raise not_found(what)
    svg = r["svg"]
    if len(svg.encode("utf-8")) > REGION_MAX_BYTES:
        raise Refusal("too_large", f"That {what} is more than 64 kB of SVG ({r['items']} items): narrow the box, or read a frame at a time.")
    note = "More items are in this area than the limit: narrow the box, or read a frame at a time." if r["truncated"] else None
    return detail(board_id=board_id, items=r["items"], total=r["total"], truncated=r["truncated"], svg=svg, **({"note": note} if note else {}))


def register(mcp, q) -> None:
    @mcp.tool(name="board_overview", annotations={"title": "A board's shape", **RO})
    @answers
    async def board_overview(params: BoardIn) -> str:
        """ALWAYS the first read of a board (Q2, Q3): its frames (title, box, counts by type, up to three sample texts), the clusters of unframed items in 1,000-unit cells, the extent of the whole canvas, the totals by type, the budget, the last change
        and who worked on it in the last hour. Use it to choose a frame or an area, then board_search, frame_read or board_read_region. Boxes are [x1, y1, x2, y2] in canvas units."""
        b = await board_row(q, params.board)
        o = (await q("SELECT bd_board_overview($1::uuid) AS o", str(b["board_id"])))[0]["o"]
        if o is None:
            raise not_found("board")
        return detail(**o, url=url_of(b["board_id"]))

    @mcp.tool(name="board_search", annotations={"title": "Find items on a board", **RO})
    @answers
    async def board_search(params: SearchIn) -> str:
        """Where is 'pricing' on this board (Q4): items whose text or frame title contains every word — item_id, type, frame_id, frame_title, a 160-character excerpt, the centre (cx, cy) and the box (x1, y1, x2, y2), best match first (frame titles, then
        texts). Read around a hit with board_read_region (around = its item_id) or its frame with frame_read; act on it with the item's id."""
        p = params
        b = await board_row(q, p.board)
        w = Where().add("i.board_id = {}::uuid", str(b["board_id"]))
        hay = "(COALESCE(i.plain_text, '') || ' ' || COALESCE(i.data->>'title', ''))"
        for word in words_of(p.text):
            w.add(f"{hay} ILIKE {{}}", like(word))
        if p.type:
            w.add("i.type = {}", p.type)
        if p.frame:
            w.add("i.parent_id = {}::uuid", uid(p.frame, "frame"))
        phrase = w.p(like(p.text.strip()))
        rows = await q(f"""SELECT i.item_id, i.type, i.parent_id AS frame_id, f.data->>'title' AS frame_title, COALESCE(NULLIF(i.plain_text, ''), i.data->>'title', '') AS words,
                                  round(((i.x1 + i.x2) / 2)::numeric, 1) AS cx, round(((i.y1 + i.y2) / 2)::numeric, 1) AS cy,
                                  round(i.x1::numeric, 1) AS x1, round(i.y1::numeric, 1) AS y1, round(i.x2::numeric, 1) AS x2, round(i.y2::numeric, 1) AS y2
                             FROM mcp_board_items i LEFT JOIN mcp_board_items f ON f.item_id = i.parent_id
                            WHERE {w.sql()}
                            ORDER BY (i.type = 'frame') DESC, ({hay} ILIKE {phrase}) DESC, i.z, i.item_id LIMIT {p.limit}""", *w.args)
        for r in rows:
            r["excerpt"] = excerpt(r.pop("words"), 160)
        return dumps({"ok": True, "board_id": b["board_id"], "count": len(rows), "rows": rows})

    @mcp.tool(name="board_read_region", annotations={"title": "Read an area of a board", **RO})
    @answers
    async def board_read_region(params: RegionIn) -> str:
        """Read an area of the canvas as SVG (Q5): give a box (x1, y1, x2, y2) or around = an item id (its box padded by pad). Frames come first, then items by z; at most 400 items and 64 kB. Each element carries data-id, data-type, data-cx/data-cy (the
        CENTRE — what actions take) and its text; a frame is a group whose children are frame-relative; connectors are lines between centres. Past the cap the answer says truncated or too_large: narrow the box. Writes never go back as SVG."""
        p = params
        b = await board_row(q, p.board)
        if p.around:
            it = await item_row(q, p.around, str(b["board_id"]))
            x1, y1, x2, y2 = float(it["x1"]) - p.pad, float(it["y1"]) - p.pad, float(it["x2"]) + p.pad, float(it["y2"]) + p.pad
        else:
            vals = [p.x1, p.y1, p.x2, p.y2]
            if any(v is None for v in vals):
                raise Refusal("invalid_argument", "Give a box (x1, y1, x2, y2) or around (an item id).", fields=["x1", "y1", "x2", "y2", "around"])
            if not all(math.isfinite(v) for v in vals):
                raise Refusal("invalid_argument", "The box must be finite numbers.", fields=["x1", "y1", "x2", "y2"])
            x1, x2 = sorted((p.x1, p.x2))
            y1, y2 = sorted((p.y1, p.y2))
        return await region_answer(q, str(b["board_id"]), x1, y1, x2, y2, p.limit, None, "area")

    @mcp.tool(name="frame_read", annotations={"title": "Read a frame", **RO})
    @answers
    async def frame_read(params: FrameIn) -> str:
        """Read one frame as SVG (Q5): its children and the connectors between them, FRAME-RELATIVE (the frame's top-left is 0, 0) — a data-cx/data-cy is still the absolute centre an action takes. At most 400 items and 64 kB. Get the frame's item_id from
        board_overview or board_search."""
        p = params
        b = await board_row(q, p.board)
        return await region_answer(q, str(b["board_id"]), 0.0, 0.0, 0.0, 0.0, p.limit, uid(p.frame, "frame"), "frame")

    @mcp.tool(name="item_get", annotations={"title": "One item", **RO})
    @answers
    async def item_get(params: ItemIn) -> str:
        """One item in full (Q5 detail): type, frame, centre (cx, cy), size, rotation, z, style, data, its text as plain text and as runs, whether it is locked, its version, who made and last changed it and when, and the connectors that end at it. A drawing's points
        are counted, not listed. Use after board_search or board_read_region for the exact text and style before changing an item."""
        i = await item_row(q, params.item)
        cx, cy = _centre(i)
        names = {r["member_id"]: r["display_name"] for r in await q("SELECT member_id, display_name FROM mcp_members WHERE member_id = ANY($1::bigint[])", [m for m in (i["created_by"], i["updated_by"]) if m is not None])}
        parent = (await q("SELECT data->>'title' AS title FROM mcp_board_items WHERE item_id = $1::uuid", str(i["parent_id"])))[0]["title"] if i["parent_id"] else None
        conns = await q("""SELECT c.item_id AS connector_id, c.start_item_id, c.end_item_id, c.data FROM mcp_board_items c
                            WHERE c.type = 'connector' AND (c.start_item_id = $1::uuid OR c.end_item_id = $1::uuid) ORDER BY c.z, c.item_id""", str(i["item_id"]))
        data = i["data"] if isinstance(i["data"], dict) else {}
        connectors = [{"connector_id": c["connector_id"], "from_id": c["start_item_id"], "to_id": c["end_item_id"], "shape": (c["data"] or {}).get("shape", "straight") if isinstance(c["data"], dict) else "straight", "caption": _caption(c["data"])} for c in conns]
        return detail(item_id=i["item_id"], board_id=i["board_id"], type=i["type"], frame_id=i["parent_id"], frame_title=parent, cx=cx, cy=cy, x1=round(i["x1"], 1), y1=round(i["y1"], 1), x2=round(i["x2"], 1), y2=round(i["y2"], 1),
                      width=i["width"], height=i["height"], rotation=i["rotation"], z=i["z"], style=i["style"], data=_drop_heavy(data), text=i["plain_text"], runs=data.get("content"), locked=i["locked"], version=i["version"],
                      from_id=i["start_item_id"], to_id=i["end_item_id"], created_by=names.get(i["created_by"]), created_at=i["created_at"], updated_by=names.get(i["updated_by"]), updated_at=i["updated_at"],
                      connectors=connectors, url=url_of(i["board_id"], "?item=" + str(i["item_id"])))

    @mcp.tool(name="item_connections", annotations={"title": "What an item is connected to", **RO})
    @answers
    async def item_connections(params: ItemIn) -> str:
        """What is connected to an item (Q7): incoming (connector_id, from_id, from_type, from_excerpt, caption) and outgoing (connector_id, to_id, to_type, to_excerpt, caption). A connector with a free end has no id on that side. Follow with item_get or
        board_read_region (around = that id)."""
        i = await item_row(q, params.item)
        rows = await q("""SELECT c.item_id AS connector_id, c.start_item_id, c.end_item_id, c.data,
                                 s.type AS s_type, COALESCE(NULLIF(s.plain_text, ''), s.data->>'title') AS s_words, e.type AS e_type, COALESCE(NULLIF(e.plain_text, ''), e.data->>'title') AS e_words
                            FROM mcp_board_items c LEFT JOIN mcp_board_items s ON s.item_id = c.start_item_id LEFT JOIN mcp_board_items e ON e.item_id = c.end_item_id
                           WHERE c.type = 'connector' AND (c.start_item_id = $1::uuid OR c.end_item_id = $1::uuid) ORDER BY c.z, c.item_id""", str(i["item_id"]))
        incoming = [{"connector_id": r["connector_id"], "from_id": r["start_item_id"], "from_type": r["s_type"], "from_excerpt": excerpt(r["s_words"], 80), "caption": _caption(r["data"])} for r in rows if str(r["end_item_id"]) == str(i["item_id"])]
        outgoing = [{"connector_id": r["connector_id"], "to_id": r["end_item_id"], "to_type": r["e_type"], "to_excerpt": excerpt(r["e_words"], 80), "caption": _caption(r["data"])} for r in rows if str(r["start_item_id"]) == str(i["item_id"])]
        return detail(item_id=i["item_id"], type=i["type"], incoming=incoming, outgoing=outgoing)

    @mcp.tool(name="find_attachments", annotations={"title": "Images and files on a board", **RO})
    @answers
    async def find_attachments(params: FindAttachmentsIn) -> str:
        """The images and files on a board (Q14), newest first: attachment_id, item_id (the item that shows it), filename, mime_type, byte_size, width, height, uploaded_by, created_at. Never a path: a person opens a file from the board."""
        p = params
        b = await board_row(q, p.board)
        w = Where().add("x.board_id = {}::uuid", str(b["board_id"])).raw("x.record_type IN ('item', 'comment')")
        if p.kind == "image":
            w.raw("x.mime_type LIKE 'image/%'")
        elif p.kind == "document":
            w.raw("x.mime_type NOT LIKE 'image/%'")
        rows = await q(f"""SELECT x.attachment_id, CASE WHEN x.record_type = 'item' THEN x.record_uuid END AS item_id, x.filename, x.mime_type, x.byte_size, x.width, x.height, u.display_name AS uploaded_by, x.created_at
                             FROM mcp_attachments x LEFT JOIN mcp_members u ON u.member_id = x.uploaded_by WHERE {w.sql()} ORDER BY x.created_at DESC, x.attachment_id DESC LIMIT {p.limit + 1}""", *w.args)
        return listing(rows, p.limit)

    @mcp.tool(name="board_changes", annotations={"title": "What changed on a board", **RO})
    @answers
    async def board_changes(params: ChangesIn) -> str:
        """What changed on a board (Q8), newest first: one row per committed batch — seq, at, member_name, member_kind, source (web, agent, command_bar or restore), counts by verb (create, update, delete, restore), types, the batch's box and the seq it undoes.
        Without `since` the answer also says how many changes others made since the asker last opened the board (since_my_last_visit). Filter by time, person or source."""
        p = params
        b = await board_row(q, p.board)
        w = Where().add("c.board_id = {}::uuid", str(b["board_id"]))
        if p.since:
            w.add("c.at >= {}", when(p.since, "since"))
        if p.until:
            w.add("c.at < {}", when(p.until, "until", end_of_day=True))
        if p.member not in (None, ""):
            w.add("c.member_id = {}", await member_id_of(q, p.member))
        if p.source:
            w.add("c.source = {}", p.source)
        rows = await q(f"""SELECT c.seq, c.at, c.member_name, c.member_kind, c.source, c.summary->'counts' AS counts, c.summary->'types' AS types, c.summary->'bbox' AS box, c.undoes_seq AS undoes, c.agent_run_id
                             FROM mcp_board_changes c WHERE {w.sql()} ORDER BY c.seq DESC LIMIT {p.limit + 1}""", *w.args)
        extra = {}
        if not p.since:
            seen = b["my_last_visit"]
            if seen is None:
                extra["since_my_last_visit"] = {"last_visit": None, "changes_by_others": None, "note": "You have not opened this board before."}
            else:
                r = (await q("""SELECT count(*)::int AS n, COALESCE(array_agg(DISTINCT c.member_name) FILTER (WHERE c.member_name IS NOT NULL), '{}') AS who FROM mcp_board_changes c
                                 WHERE c.board_id = $1::uuid AND c.at > $2 AND c.member_id IS DISTINCT FROM app_current_member_id()""", str(b["board_id"]), seen))[0]
                extra["since_my_last_visit"] = {"last_visit": seen, "changes_by_others": r["n"], "by": r["who"]}
        return listing(rows, p.limit, board_id=b["board_id"], **extra)

    @mcp.tool(name="board_versions", annotations={"title": "A board's versions", **RO})
    @answers
    async def board_versions(params: VersionsIn) -> str:
        """The saved versions of a board (Q11), newest first: version_no, label, reason (named, interval, daily, before_restore …), item_count, saved_by, created_at. Read what one looked like with version_outline."""
        p = params
        b = await board_row(q, p.board)
        rows = await q(f"""SELECT v.version_no, v.label, v.reason, v.item_count, s.display_name AS saved_by, v.created_at FROM mcp_board_versions v LEFT JOIN mcp_members s ON s.member_id = v.saved_by
                            WHERE v.board_id = $1::uuid ORDER BY v.version_no DESC LIMIT {p.limit + 1}""", str(b["board_id"]))
        return listing(rows, p.limit, board_id=b["board_id"])

    @mcp.tool(name="version_outline", annotations={"title": "A version as text", **RO})
    @answers
    async def version_outline(params: VersionOutlineIn) -> str:
        """What a board looked like in a saved version (Q11 — 'what did it look like on Friday'): the same Markdown outline board_outline writes for the live board, at most 256 kB. Get version numbers from board_versions."""
        p = params
        b = await board_row(q, p.board)
        text = (await q("SELECT bd_version_outline($1::uuid, $2::int) AS o", str(b["board_id"]), p.version))[0]["o"]
        if text is None:
            raise not_found("version")
        if len(text.encode("utf-8")) > OUTLINE_MAX_BYTES:
            raise Refusal("too_large", "That version's outline is more than 256 kB: read the live board a frame at a time.")
        return detail(board_id=b["board_id"], title=b["title"], version=p.version, outline=text)
