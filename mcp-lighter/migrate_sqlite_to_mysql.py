#!/usr/bin/env python3
"""One-shot: copy SQLite indicator_history.sqlite → MySQL botassistant."""
from __future__ import annotations

import sqlite3
import sys
from pathlib import Path

from db import DEFAULT_DSN, connect

ROOT = Path(__file__).resolve().parent.parent
SQLITE = ROOT / "data" / "indicator_history.sqlite"

TABLES = [
    "indicator_snapshots",
    "indicator_stats",
    "indicator_reports",
    "deepseek_queries",
]

COLS = {
    "indicator_snapshots": [
        "id", "market_id", "symbol", "resolution", "bar_ts", "indicator",
        "value", "signal", "close", "created_at",
    ],
    "indicator_stats": [
        "id", "market_id", "symbol", "resolution", "indicator", "bar_ts",
        "strategy_return_pct", "profit_factor", "accuracy", "signals",
        "wins", "losses", "last_signal", "last_value", "created_at",
    ],
    "indicator_reports": [
        "id", "market_id", "symbol", "model", "title", "summary",
        "findings_json", "compact_bytes", "raw_response", "created_at",
    ],
    "deepseek_queries": [
        "id", "purpose", "model", "market_id", "report_id", "parent_query_id",
        "prompt_text", "messages_json", "response_text", "reasoning_text",
        "usage_json", "prompt_tokens", "completion_tokens", "total_tokens",
        "duration_ms", "status", "error_text", "meta_json", "created_at",
    ],
}


def migrate(sqlite_path: Path = SQLITE, dsn: str = DEFAULT_DSN, batch: int = 500) -> dict:
    if not sqlite_path.is_file():
        return {"ok": False, "error": f"sqlite missing: {sqlite_path}"}

    src = sqlite3.connect(str(sqlite_path))
    src.row_factory = sqlite3.Row
    dst = connect(dsn, ensure_schema=True)
    counts: dict[str, int] = {}
    try:
        for table in TABLES:
            cols = COLS[table]
            col_sql = ", ".join(f"`{c}`" if c == "signal" else c for c in cols)
            placeholders = ", ".join(["?"] * len(cols))
            dst.execute(f"DELETE FROM {table}")
            n = 0
            rows = src.execute(f"SELECT {', '.join(cols)} FROM {table}").fetchall()
            for i in range(0, len(rows), batch):
                chunk = rows[i : i + batch]
                for r in chunk:
                    vals = tuple(r[c] for c in cols)
                    dst.execute(
                        f"INSERT INTO {table} ({col_sql}) VALUES ({placeholders})",
                        vals,
                    )
                    n += 1
                dst.commit()
            # bump AUTO_INCREMENT past max id
            max_id = src.execute(f"SELECT MAX(id) FROM {table}").fetchone()[0]
            if max_id:
                next_id = int(max_id) + 1
                with dst._raw.cursor() as cur:
                    cur.execute(f"ALTER TABLE {table} AUTO_INCREMENT = {next_id}")
            counts[table] = n
        dst.commit()
        return {"ok": True, "counts": counts, "sqlite": str(sqlite_path), "dsn": dsn}
    finally:
        src.close()
        dst.close()


def main() -> int:
    result = migrate()
    print(result)
    return 0 if result.get("ok") else 1


if __name__ == "__main__":
    raise SystemExit(main())
