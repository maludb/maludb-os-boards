"""boards_records_mcp — Boards' record-memory server (PostgreSQL, the mcp_* views and the bd_* read functions, read-only).

Tool surface: docs/boards-mcp-tool-surface.md (spec: docs/build-specs/mcp-servers.md). Every tool reads as the asking member, so a person or an agent sees what the screens show them: the boards the wall (bd_my_board_levels) admits,
nothing of a board it hides — `not_found`, the same words as a board that never existed. The views and the functions decide; the server only resolves names, trims, and chooses the error. A board is read search → region, never whole;
the SVG dialect is bd_region_svg()'s and the outline bd_board_outline()'s. An agent sees only the tools the kernel granted it (server_common). The kernel's own token reaches app_roles and the two shares (boards_index,
board_outline). Writes are never here: they are the kernel's Actions MCP, built from mcp/action_registry.json. The one write this server makes is the trail of a share read (db/019 bd_log_mcp_read).
"""
from __future__ import annotations

from mcp.server.fastmcp import FastMCP

import db
from bd_common import RO, dumps

mcp = FastMCP("boards_records_mcp")


async def _pool():
    return await db.get_pool(db.ENV["MCP_RECORDS_DB_USER"], db.ENV["MCP_RECORDS_DB_PASSWORD"])


async def q(sql: str, *args) -> list[dict]:
    return await db.fetch_scoped(await _pool(), sql, *args)


@mcp.tool(name="app_roles", annotations={"title": "Boards' roles and rights", **RO})
async def app_roles() -> str:
    """The roles Boards offers and the rights each gives — what the Business OS kernel reads to let a super-admin grant them (schema os.app-roles/1, maludb-os-integration 0.4.x): Viewer, Member (user) and Boards admin, with their rights. The catalogue
    is not about anyone, so the kernel's own token may read it; so may any person or agent."""
    pool = await _pool()
    async with pool.acquire() as con:
        rights = await con.fetch("SELECT right_key, description FROM bd_rights ORDER BY sort_order, right_key")
        roles = await con.fetch("SELECT role_key, name, description, capability, is_admin, sort_order, rights FROM mcp_app_roles ORDER BY sort_order")
    return dumps({
        "schema": "os.app-roles/1",
        "application": "boards",
        "rights": [{"key": r["right_key"], "description": r["description"]} for r in rights],
        "roles": [{"key": r["role_key"], "name": r["name"], "description": r["description"], "capability": r["capability"], "is_admin": r["is_admin"], "rights": list(r["rights"])} for r in roles],
    })


import bd_boards  # noqa: E402
import bd_canvas  # noqa: E402
import bd_comments  # noqa: E402
import bd_shares  # noqa: E402

for module in (bd_boards, bd_canvas, bd_comments, bd_shares):
    module.register(mcp, q)


if __name__ == "__main__":
    import server_common
    server_common.run(mcp, "MCP_RECORDS_DB_USER", "MCP_RECORDS_DB_PASSWORD", port=int(db.ENV["MCP_RECORDS_PORT"]), endpoint_name="Records MCP")
