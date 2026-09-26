#!/usr/bin/env python3
"""Replace-snapshot / read indicator history SQLite (used by PHP endpoint)."""
from __future__ import annotations

import argparse
import json
import sqlite3
import sys
import time
from pathlib import Path


SCHEMA = """
CREATE TABLE IF NOT EXISTS indicator_snapshots (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    market_id INTEGER NOT NULL,
    symbol TEXT,
    resolution TEXT NOT NULL,
    bar_ts INTEGER NOT NULL,
    indicator TEXT NOT NULL,
    value REAL,
    signal INTEGER,
    close REAL,
    created_at INTEGER NOT NULL,
    UNIQUE(market_id, resolution, bar_ts, indicator)
);
CREATE INDEX IF NOT EXISTS idx_ind_snap_lookup
    ON indicator_snapshots(market_id, resolution, indicator, bar_ts DESC);

CREATE TABLE IF NOT EXISTS indicator_stats (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    market_id INTEGER NOT NULL,
    symbol TEXT,
    resolution TEXT NOT NULL,
    indicator TEXT NOT NULL,
    bar_ts INTEGER,
    strategy_return_pct REAL,
    profit_factor REAL,
    accuracy REAL,
    signals INTEGER,
    wins INTEGER,
    losses INTEGER,
    last_signal INTEGER,
    last_value REAL,
    created_at INTEGER NOT NULL,
    UNIQUE(market_id, resolution, indicator)
);
CREATE INDEX IF NOT EXISTS idx_ind_stats_lookup
    ON indicator_stats(market_id, resolution, strategy_return_pct DESC);
"""


def connect(db_path: str) -> sqlite3.Connection:
    Path(db_path).parent.mkdir(parents=True, exist_ok=True)
    con = sqlite3.connect(db_path)
    con.execute("PRAGMA journal_mode=WAL;")
    con.executescript(SCHEMA)
    return con


def write_payload(path: str) -> dict:
    data = json.loads(Path(path).read_text(encoding="utf-8"))
    db_path = data["db_path"]
    rows = data.get("rows") or []
    stats = data.get("stats") or []
    replace_scopes = data.get("replace_scopes") or []
    now = int(time.time())
    con = connect(db_path)
    deleted = 0
    deleted_stats = 0
    inserted = 0
    inserted_stats = 0
    try:
        for scope in replace_scopes:
            mid = int(scope["market_id"])
            res = str(scope["resolution"])
            cur = con.execute(
                "DELETE FROM indicator_snapshots WHERE market_id=? AND resolution=?",
                (mid, res),
            )
            deleted += cur.rowcount if cur.rowcount is not None else 0
            cur2 = con.execute(
                "DELETE FROM indicator_stats WHERE market_id=? AND resolution=?",
                (mid, res),
            )
            deleted_stats += cur2.rowcount if cur2.rowcount is not None else 0

        for r in rows:
            con.execute(
                """
                INSERT INTO indicator_snapshots
                    (market_id, symbol, resolution, bar_ts, indicator, value, signal, close, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                """,
                (
                    int(r["market_id"]),
                    r.get("symbol"),
                    str(r["resolution"]),
                    int(r["bar_ts"]),
                    str(r["indicator"]),
                    r.get("value"),
                    r.get("signal"),
                    r.get("close"),
                    now,
                ),
            )
            inserted += 1

        for s in stats:
            con.execute(
                """
                INSERT INTO indicator_stats
                    (market_id, symbol, resolution, indicator, bar_ts,
                     strategy_return_pct, profit_factor, accuracy,
                     signals, wins, losses, last_signal, last_value, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                """,
                (
                    int(s["market_id"]),
                    s.get("symbol"),
                    str(s["resolution"]),
                    str(s["indicator"]),
                    s.get("bar_ts"),
                    s.get("strategy_return_pct"),
                    s.get("profit_factor"),
                    s.get("accuracy"),
                    s.get("signals"),
                    s.get("wins"),
                    s.get("losses"),
                    s.get("last_signal"),
                    s.get("last_value"),
                    now,
                ),
            )
            inserted_stats += 1

        con.commit()
    finally:
        con.close()
    return {
        "ok": True,
        "inserted": inserted,
        "deleted": deleted,
        "inserted_stats": inserted_stats,
        "deleted_stats": deleted_stats,
        "db_path": db_path,
        "scopes": len(replace_scopes),
    }


def read_rows(db_path: str, limit: int, market_id: int | None, resolution: str | None) -> dict:
    if not Path(db_path).is_file():
        return {"ok": True, "rows": [], "stats": []}
    con = connect(db_path)
    try:
        sql = """
            SELECT market_id, symbol, resolution, bar_ts, indicator, value, signal, close, created_at
            FROM indicator_snapshots
            WHERE 1=1
        """
        args: list = []
        if market_id is not None:
            sql += " AND market_id=?"
            args.append(market_id)
        if resolution:
            sql += " AND resolution=?"
            args.append(resolution)
        sql += " ORDER BY bar_ts DESC, indicator ASC LIMIT ?"
        args.append(limit)
        cur = con.execute(sql, args)
        cols = [d[0] for d in cur.description]
        rows = [dict(zip(cols, row)) for row in cur.fetchall()]

        sql2 = """
            SELECT market_id, symbol, resolution, indicator, bar_ts,
                   strategy_return_pct, profit_factor, accuracy,
                   signals, wins, losses, last_signal, last_value, created_at
            FROM indicator_stats
            WHERE 1=1
        """
        args2: list = []
        if market_id is not None:
            sql2 += " AND market_id=?"
            args2.append(market_id)
        if resolution:
            sql2 += " AND resolution=?"
            args2.append(resolution)
        sql2 += " ORDER BY CASE WHEN strategy_return_pct IS NULL THEN 1 ELSE 0 END, strategy_return_pct DESC, indicator ASC"
        cur2 = con.execute(sql2, args2)
        cols2 = [d[0] for d in cur2.description]
        stats = [dict(zip(cols2, row)) for row in cur2.fetchall()]
        return {"ok": True, "rows": rows, "stats": stats}
    finally:
        con.close()


def main() -> int:
    if len(sys.argv) >= 2 and sys.argv[1] == "--read":
        ap = argparse.ArgumentParser()
        ap.add_argument("--read", action="store_true")
        ap.add_argument("db_path")
        ap.add_argument("--limit", type=int, default=50)
        ap.add_argument("--market-id", type=int, default=None)
        ap.add_argument("--resolution", default=None)
        ns = ap.parse_args()
        print(json.dumps(read_rows(ns.db_path, ns.limit, ns.market_id, ns.resolution), ensure_ascii=False))
        return 0

    if len(sys.argv) < 2:
        print(json.dumps({"ok": False, "error": "usage: indicator_history_write.py <payload.json>"}))
        return 1
    try:
        print(json.dumps(write_payload(sys.argv[1]), ensure_ascii=False))
        return 0
    except Exception as e:
        print(json.dumps({"ok": False, "error": str(e), "inserted": 0, "deleted": 0}))
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
