"""Shared pieces for Boards' tool modules: annotations, the input base, the where-builder, the refusals (errors are answers, not exceptions — the surface's "Errors"), the
board / member / folder resolvers (through the mcp_* views, so a record the caller may not see does not exist for them), the list envelope the kernel's entity resolver reads,
and the few questions asked of the database about the caller. Every query runs as the asking member (db.fetch_scoped sets app.member_id from the verified bearer): the views and
the bd_* read functions decide the rows and the wall; nothing here filters by who is asking. The tools never render SVG or Markdown themselves — bd_region_svg() and
bd_board_outline() do (db/013).
"""
from __future__ import annotations

import datetime
import decimal
import functools
import re
import uuid as _uuid

import asyncpg
from pydantic import BaseModel, ConfigDict

import db

RO = {"readOnlyHint": True, "openWorldHint": False}
Ref = int | str          # an id or a name

REGION_MAX_BYTES = 64 * 1024          # a region or a frame's SVG (the surface: over it, `too_large`)
OUTLINE_MAX_BYTES = 256 * 1024        # an outline (live or a version's)


class _Base(BaseModel):
    model_config = ConfigDict(str_strip_whitespace=True, extra="forbid", populate_by_name=True)


def _fmt(o):
    if isinstance(o, float) and o.is_integer() and abs(o) < 10 ** 15:
        return int(o)
    return o


def _clean(obj):
    if isinstance(obj, dict):
        return {k: _clean(v) for k, v in obj.items()}
    if isinstance(obj, (list, tuple)):
        return [_clean(x) for x in obj]
    if isinstance(obj, decimal.Decimal):
        return int(obj) if obj == obj.to_integral_value() and abs(obj) < 10 ** 15 else float(obj)
    if isinstance(obj, _uuid.UUID):
        return str(obj)
    return _fmt(obj)


def dumps(obj) -> str:
    return db.to_json(_clean(obj))


class Refusal(Exception):
    """An answer, not a crash: the code, the sentence, whether trying again could help, and any extra keys (fields, candidates)."""

    def __init__(self, code: str, message: str, retry: bool = False, **extra) -> None:
        super().__init__(message)
        self.code, self.message, self.retry, self.extra = code, message, retry, extra


def fail(code: str, message: str, retry: bool = False, **extra) -> str:
    return dumps({"ok": False, "error": {"code": code, "message": message, "retry": retry, **extra}})


def answers(fn):
    """Turn a Refusal (and the database's refusals) into the tool's error answer; the tool never raises."""
    @functools.wraps(fn)
    async def inner(*a, **k):
        try:
            return await fn(*a, **k)
        except Refusal as r:
            return fail(r.code, r.message, r.retry, **r.extra)
        except asyncpg.NoDataFoundError:
            return fail("not_found", "No record you can read matches that.")
        except asyncpg.InsufficientPrivilegeError:
            return fail("not_allowed", "That is not yours to read.")
        except asyncpg.PostgresError as ex:
            if isinstance(ex, (asyncpg.DataError, asyncpg.InvalidTextRepresentationError, asyncpg.CheckViolationError, asyncpg.RaiseError)):
                return fail("invalid_argument", "A parameter is out of range or not of the right kind.")
            return fail("server_error", "The question could not be answered: " + ex.__class__.__name__ + ".", retry=True)
    return inner


def lim(n: int | None, default: int = 25, top: int = 100) -> int:
    return max(1, min(int(n or default), top))


def off(n: int | None) -> int:
    return max(0, min(int(n or 0), 10000))


class Where:
    """Builds `a = $1 AND b = ANY($2)` with positional parameters in order."""

    def __init__(self) -> None:
        self.parts: list[str] = []
        self.args: list = []

    def p(self, value) -> str:
        self.args.append(value)
        return f"${len(self.args)}"

    def add(self, sql: str, *values) -> "Where":
        self.parts.append(sql.format(*[self.p(v) for v in values]))
        return self

    def raw(self, sql: str) -> "Where":
        self.parts.append(sql)
        return self

    def sql(self) -> str:
        return " AND ".join(self.parts) if self.parts else "TRUE"


def like(s: str) -> str:
    return "%" + s.replace("\\", "\\\\").replace("%", "\\%").replace("_", "\\_") + "%"


def words_of(s: str, top: int = 8) -> list[str]:
    return [w for w in re.split(r"\s+", s.strip()) if w][:top]


_UUID = re.compile(r"^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$")


def is_uuid(v) -> bool:
    return isinstance(v, str) and bool(_UUID.match(v.strip()))


def uid(v, what: str = "id") -> str:
    """A UUID as lowercase text, or an invalid_argument answer naming the field."""
    s = str(v).strip() if v is not None else ""
    if not _UUID.match(s):
        raise Refusal("invalid_argument", f"{what} is an id (a UUID).", fields=[what])
    return s.lower()


def is_int(v) -> bool:
    return isinstance(v, int) or (isinstance(v, str) and v.strip().isdigit())


def resolving(p) -> bool:
    """True when only `q` (and perhaps `limit`) was given: the kernel's entity resolver's call. It wants ONE row for an exact name — more than one is 'several match' and the action stops."""
    return bool(getattr(p, "q", None)) and set(p.model_fields_set) <= {"q", "limit"}


def exact_first(rows: list[dict], q: str | None, *cols: str) -> list[dict]:
    """The rows that equal q exactly (case-insensitive) in one of $cols, when there are any; else the rows as they are."""
    t = (q or "").strip().lower()
    hit = [r for r in rows if t and any(str(r.get(c) if r.get(c) is not None else "").lower() == t for c in cols)]
    return hit if hit else rows


def listing(rows: list[dict], limit: int, **more) -> str:
    """The list envelope: {ok, count, rows, truncated} — the kernel's resolver reads `rows`. $rows may hold one more than $limit (the probe for `truncated`)."""
    truncated = len(rows) > limit
    rows = rows[:limit]
    return dumps({"ok": True, "count": len(rows), "rows": rows, "truncated": truncated, **more})


def detail(**fields) -> str:
    return dumps({"ok": True, **fields})


def cut(s: str | None, n: int) -> str | None:
    return None if s is None else (s if len(s) <= n else s[: n - 1].rstrip() + "…")


def when(s, what: str = "The time", end_of_day: bool = False) -> datetime.datetime | None:
    """A YYYY-MM-DD day or an ISO 8601 time as an aware datetime (None stays None); anything else is a sentence."""
    if s is None or s == "":
        return None
    if isinstance(s, datetime.datetime):
        return s if s.tzinfo else s.replace(tzinfo=datetime.timezone.utc)
    t = str(s).strip()
    try:
        if re.fullmatch(r"\d{4}-\d{2}-\d{2}", t):
            d = datetime.date.fromisoformat(t)
            return datetime.datetime.combine(d + datetime.timedelta(days=1 if end_of_day else 0), datetime.time.min, tzinfo=datetime.timezone.utc)
        v = datetime.datetime.fromisoformat(t.replace("Z", "+00:00"))
    except ValueError as ex:
        raise Refusal("invalid_argument", f"{what} is a day (2026-03-02) or an ISO time (2026-03-02T09:30:00Z).", fields=[what]) from ex
    return v if v.tzinfo else v.replace(tzinfo=datetime.timezone.utc)


def ago(days: int) -> datetime.datetime:
    return datetime.datetime.now(datetime.timezone.utc) - datetime.timedelta(days=days)


def url_of(board_id, query: str = "") -> str:
    base = (db.ENV.get("APP_URL") or "").rstrip("/")
    return f"{base}/boards/{board_id}{query}"


async def flag(q, sql: str, *args) -> bool:
    rows = await q("SELECT " + sql + " AS ok", *args)
    return bool(rows and rows[0]["ok"])


async def me(q) -> int | None:
    rows = await q("SELECT app_current_member_id() AS m")
    return rows[0]["m"] if rows else None


async def is_insider(q) -> bool:
    return await flag(q, "bd_is_member_here()")


async def is_boards_admin(q) -> bool:
    return await flag(q, "bd_is_boards_admin(app_current_member_id())")


def not_found(what: str = "record") -> Refusal:
    """The same words for a missing record and one the caller may not see — a tool never confirms that a private board exists."""
    return Refusal("not_found", f"No {what} you can read matches that.")


def ambiguous(what: str, v, rows: list[dict], id_field: str, label_field: str) -> Refusal:
    return Refusal("ambiguous", f"More than one {what} matches '{v}'; ask which (or give its id).", candidates=[{"id": r[id_field], "label": r[label_field]} for r in rows[:6]])


# ---- the board, the member, the folder: through the views ----------------------------------------------------------------------------------

def _boards_first(rows: list[dict]) -> list[dict]:
    """A title that matches both a board and a template means the board (a template is named by its id, or by find_templates, when nothing else answers to the name)."""
    real = [r for r in rows if not r["is_template"]]
    return real or rows


async def board_row(q, board: Ref | None, what: str = "board") -> dict:
    """The mcp_boards row (the wall's) a call is about. `board` is a uuid or a title (an exact title first, then a fragment); two matches → ambiguous; one the wall hides → not_found,
    in the words of one that does not exist."""
    if board in (None, ""):
        raise Refusal("invalid_argument", f"Name the {what} (its id or its title).", fields=[what])
    s = str(board).strip()
    if is_uuid(s):
        rows = await q("SELECT * FROM mcp_boards WHERE board_id = $1::uuid", s.lower())
        if rows:
            return rows[0]
        raise not_found("board")
    rows = _boards_first(await q("SELECT * FROM mcp_boards WHERE lower(title) = lower($1) ORDER BY last_activity_at DESC LIMIT 7", s))
    if len(rows) > 1:
        raise ambiguous("board", s, rows, "board_id", "title")
    if rows:
        return rows[0]
    rows = _boards_first(await q("SELECT * FROM mcp_boards WHERE title ILIKE $1 ORDER BY last_activity_at DESC LIMIT 7", like(s)))
    if len(rows) > 1:
        raise ambiguous("board", s, rows, "board_id", "title")
    if rows:
        return rows[0]
    raise not_found("board")


async def member_id_of(q, member: Ref | None, what: str = "member") -> int:
    """A member named by id, by 'me', or by name (an exact name first); through mcp_members, so an external sees only themselves."""
    if member in (None, ""):
        raise Refusal("invalid_argument", f"Name the {what} (an id or a name).", fields=[what])
    s = str(member).strip()
    if s.lower() == "me":
        m = await me(q)
        if m is None:
            raise not_found("member")
        return int(m)
    if is_int(s):
        rows = await q("SELECT member_id FROM mcp_members WHERE member_id = $1", int(s))
        if rows:
            return int(rows[0]["member_id"])
        raise not_found("member")
    rows = await q("SELECT member_id, display_name FROM mcp_members WHERE lower(display_name) = lower($1) ORDER BY member_id LIMIT 7", s)
    if not rows:
        rows = await q("SELECT member_id, display_name FROM mcp_members WHERE display_name ILIKE $1 ORDER BY display_name LIMIT 7", like(s))
    if len(rows) > 1:
        raise ambiguous("member", s, rows, "member_id", "display_name")
    if rows:
        return int(rows[0]["member_id"])
    raise not_found("member")


async def folder_id_of(q, folder: Ref | None) -> int:
    if folder in (None, ""):
        raise Refusal("invalid_argument", "Name the folder (an id or a name).", fields=["folder"])
    s = str(folder).strip()
    if is_int(s):
        rows = await q("SELECT folder_id FROM mcp_folders WHERE folder_id = $1", int(s))
        if rows:
            return int(rows[0]["folder_id"])
        raise not_found("folder")
    rows = await q("SELECT folder_id, name FROM mcp_folders WHERE lower(name) = lower($1) ORDER BY folder_id LIMIT 7", s)
    if not rows:
        rows = await q("SELECT folder_id, name FROM mcp_folders WHERE name ILIKE $1 ORDER BY name LIMIT 7", like(s))
    if len(rows) > 1:
        raise ambiguous("folder", s, rows, "folder_id", "name")
    if rows:
        return int(rows[0]["folder_id"])
    raise not_found("folder")


async def department_id_of(q, department: Ref | None) -> int:
    s = str(department).strip()
    if is_int(s):
        rows = await q("SELECT department_id FROM mcp_departments WHERE department_id = $1", int(s))
    else:
        rows = await q("SELECT department_id, name FROM mcp_departments WHERE lower(name) = lower($1) OR name ILIKE $2 ORDER BY (lower(name) = lower($1)) DESC, name LIMIT 7", s, like(s))
        if len(rows) > 1 and str(rows[0]["name"]).lower() != s.lower():
            raise ambiguous("department", s, rows, "department_id", "name")
    if rows:
        return int(rows[0]["department_id"])
    raise not_found("department")


async def item_row(q, item: str, board: str | None = None) -> dict:
    """The mcp_board_items row of a live item the caller may see (optionally on a given board)."""
    iid = uid(item, "item")
    rows = await q("SELECT * FROM mcp_board_items WHERE item_id = $1::uuid" + (" AND board_id = $2::uuid" if board else ""), iid, *([board] if board else []))
    if not rows:
        raise not_found("item")
    return rows[0]


def excerpt(text: str | None, n: int = 160) -> str | None:
    if text is None:
        return None
    t = re.sub(r"\s+", " ", text).strip()
    return t if len(t) <= n else t[: n - 1].rstrip() + "…"
