#!/usr/bin/env python3
"""Read-only MySQL MCP for Cursor / DeepSeek Pro agent loop.

Exposes: list_tables, describe_table, query (SELECT/SHOW/DESCRIBE only).
DSN from mcp-lighter/.env DB_DSN or config/indicators.env.
"""
from __future__ import annotations

import re
from typing import Any, Optional

from dotenv import load_dotenv
from mcp.server.fastmcp import FastMCP
from pathlib import Path

_ROOT = Path(__file__).resolve().parent
# Only mcp-lighter/.env (indicators.env has @markers dotenv cannot parse)
load_dotenv(_ROOT / ".env")

from db import connect  # noqa: E402

mcp = FastMCP("botassistant-mysql")

_WRITE_RE = re.compile(
    r"\b(INSERT|UPDATE|DELETE|DROP|ALTER|CREATE|TRUNCATE|REPLACE|GRANT|REVOKE|"
    r"LOAD\s+DATA|CALL|LOCK|UNLOCK|RENAME|ATTACH|DETACH)\b",
    re.IGNORECASE,
)


def _json(data: Any) -> str:
    import json

    return json.dumps(data, ensure_ascii=False, indent=2, default=str)


def _assert_readonly(sql: str) -> str:
    s = sql.strip().rstrip(";")
    if not s:
        raise ValueError("empty SQL")
    if _WRITE_RE.search(s):
        raise ValueError("write/DDL statements are blocked (read-only MCP)")
    head = s.lstrip("(").lstrip().split(None, 1)[0].upper()
    if head not in {"SELECT", "SHOW", "DESCRIBE", "DESC", "EXPLAIN", "WITH"}:
        raise ValueError(f"only SELECT/SHOW/DESCRIBE/EXPLAIN/WITH allowed, got {head}")
    return s


@mcp.tool()
def list_tables() -> str:
    """List tables in the botassistant MySQL database."""
    con = connect()
    try:
        rows = con.execute(
            "SELECT TABLE_NAME AS name, TABLE_ROWS AS approx_rows, "
            "DATA_LENGTH AS data_length, UPDATE_TIME AS update_time "
            "FROM information_schema.TABLES "
            "WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME"
        ).fetchall()
        return _json({"ok": True, "tables": [dict(r) for r in rows]})
    finally:
        con.close()


@mcp.tool()
def describe_table(table: str) -> str:
    """Describe columns for one table (name must be alphanumeric/_)."""
    if not re.fullmatch(r"[A-Za-z0-9_]+", table or ""):
        return _json({"ok": False, "error": "invalid table name"})
    con = connect()
    try:
        rows = con.execute(f"DESCRIBE `{table}`").fetchall()
        return _json({"ok": True, "table": table, "columns": [dict(r) for r in rows]})
    finally:
        con.close()


@mcp.tool()
def query(sql: str, limit: Optional[int] = 100) -> str:
    """Run a read-only SQL query. Default LIMIT 100 if none present on SELECT."""
    try:
        s = _assert_readonly(sql)
    except ValueError as e:
        return _json({"ok": False, "error": str(e)})
    lim = 100 if limit is None else max(1, min(500, int(limit)))
    upper = s.upper()
    if upper.startswith("SELECT") and " LIMIT " not in upper:
        s = f"{s} LIMIT {lim}"
    con = connect()
    try:
        cur = con.execute(s)
        rows = cur.fetchall()
        return _json(
            {
                "ok": True,
                "rowcount": len(rows),
                "rows": [dict(r) for r in rows],
            }
        )
    except Exception as e:  # noqa: BLE001
        return _json({"ok": False, "error": str(e)[:400]})
    finally:
        con.close()


if __name__ == "__main__":
    mcp.run()
