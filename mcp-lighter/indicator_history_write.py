#!/usr/bin/env python3
"""Append/upsert candles + indicator history (MySQL via db.py).

Default mode=append: INSERT … ON DUPLICATE KEY UPDATE (no full delete).
Legacy mode=replace: DELETE per replace_scopes, then insert.
"""
from __future__ import annotations

import argparse
import json
import sys
import time
from pathlib import Path

from db import connect


def watermarks(db_path: str) -> dict:
    """Per market+resolution: max bar_ts for candles and snapshots separately."""
    con = connect(db_path)
    try:
        candle_wm: dict[tuple[int, str], dict] = {}
        try:
            cur = con.execute(
                """
                SELECT market_id, resolution, MAX(bar_ts) AS max_bar_ts, COUNT(*) AS row_count
                FROM indicator_candles
                GROUP BY market_id, resolution
                """
            )
            for r in cur.fetchall():
                d = dict(r)
                candle_wm[(int(d["market_id"]), str(d["resolution"]))] = d
        except Exception:  # noqa: BLE001
            pass

        snap_wm: dict[tuple[int, str], dict] = {}
        cur2 = con.execute(
            """
            SELECT market_id, resolution, MAX(bar_ts) AS max_bar_ts, COUNT(*) AS row_count
            FROM indicator_snapshots
            GROUP BY market_id, resolution
            """
        )
        for r in cur2.fetchall():
            d = dict(r)
            snap_wm[(int(d["market_id"]), str(d["resolution"]))] = d

        keys = set(candle_wm) | set(snap_wm)
        rows = []
        for key in sorted(keys):
            c = candle_wm.get(key)
            s = snap_wm.get(key)
            rows.append(
                {
                    "market_id": key[0],
                    "resolution": key[1],
                    "max_candle_ts": int(c["max_bar_ts"]) if c and c.get("max_bar_ts") else None,
                    "max_snap_ts": int(s["max_bar_ts"]) if s and s.get("max_bar_ts") else None,
                    # legacy field for older PHP: prefer candle, else snap
                    "max_bar_ts": int(
                        (c["max_bar_ts"] if c and c.get("max_bar_ts") else None)
                        or (s["max_bar_ts"] if s and s.get("max_bar_ts") else 0)
                        or 0
                    ),
                    "candle_rows": int((c or {}).get("row_count") or 0),
                    "snap_rows": int((s or {}).get("row_count") or 0),
                }
            )
        return {"ok": True, "watermarks": rows}
    finally:
        con.close()


def write_payload(path: str) -> dict:
    data = json.loads(Path(path).read_text(encoding="utf-8"))
    db_path = data["db_path"]
    candles = data.get("candles") or []
    rows = data.get("rows") or []
    stats = data.get("stats") or []
    replace_scopes = data.get("replace_scopes") or []
    mode = str(data.get("mode") or "append").strip().lower()
    if mode not in ("append", "replace", "upsert"):
        mode = "append"
    if mode == "upsert":
        mode = "append"

    now = int(time.time())
    con = connect(db_path)
    deleted = 0
    deleted_stats = 0
    deleted_candles = 0
    inserted = 0
    updated = 0
    inserted_candles = 0
    updated_candles = 0
    inserted_stats = 0
    try:
        if mode == "replace":
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
                cur3 = con.execute(
                    "DELETE FROM indicator_candles WHERE market_id=? AND resolution=?",
                    (mid, res),
                )
                deleted_candles += cur3.rowcount if cur3.rowcount is not None else 0

        candle_sql = """
            INSERT INTO indicator_candles
                (market_id, symbol, resolution, bar_ts, open, high, low, close, volume, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                symbol=VALUES(symbol),
                open=VALUES(open),
                high=VALUES(high),
                low=VALUES(low),
                close=VALUES(close),
                volume=VALUES(volume),
                created_at=VALUES(created_at)
        """
        for c in candles:
            cur = con.execute(
                candle_sql,
                (
                    int(c["market_id"]),
                    c.get("symbol"),
                    str(c["resolution"]),
                    int(c["bar_ts"]),
                    c.get("open"),
                    c.get("high"),
                    c.get("low"),
                    c.get("close"),
                    c.get("volume"),
                    now,
                ),
            )
            rc = cur.rowcount if cur.rowcount is not None else 0
            if rc == 1:
                inserted_candles += 1
            elif rc >= 2:
                updated_candles += 1
            else:
                inserted_candles += 1

        snap_sql = """
            INSERT INTO indicator_snapshots
                (market_id, symbol, resolution, bar_ts, indicator, value, signal, close, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                symbol=VALUES(symbol),
                value=VALUES(value),
                signal=VALUES(signal),
                close=VALUES(close),
                created_at=VALUES(created_at)
        """
        for r in rows:
            cur = con.execute(
                snap_sql,
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
            rc = cur.rowcount if cur.rowcount is not None else 0
            if rc == 1:
                inserted += 1
            elif rc >= 2:
                updated += 1
            else:
                inserted += 1

        stats_sql = """
            INSERT INTO indicator_stats
                (market_id, symbol, resolution, indicator, bar_ts,
                 strategy_return_pct, profit_factor, accuracy,
                 signals, wins, losses, last_signal, last_value, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                symbol=VALUES(symbol),
                bar_ts=VALUES(bar_ts),
                strategy_return_pct=VALUES(strategy_return_pct),
                profit_factor=VALUES(profit_factor),
                accuracy=VALUES(accuracy),
                signals=VALUES(signals),
                wins=VALUES(wins),
                losses=VALUES(losses),
                last_signal=VALUES(last_signal),
                last_value=VALUES(last_value),
                created_at=VALUES(created_at)
        """
        for s in stats:
            con.execute(
                stats_sql,
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
        "mode": mode,
        "inserted": inserted,
        "updated": updated,
        "inserted_candles": inserted_candles,
        "updated_candles": updated_candles,
        "deleted": deleted,
        "deleted_candles": deleted_candles,
        "inserted_stats": inserted_stats,
        "deleted_stats": deleted_stats,
        "db_path": db_path,
        "scopes": len(replace_scopes),
        "candles_in": len(candles),
        "rows_in": len(rows),
        "stats_in": len(stats),
    }


def read_rows(db_path: str, limit: int, market_id: int | None, resolution: str | None) -> dict:
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
        rows = [dict(row) for row in cur.fetchall()]

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
        stats = [dict(row) for row in cur2.fetchall()]
        return {"ok": True, "rows": rows, "stats": stats}
    finally:
        con.close()


def main() -> int:
    if len(sys.argv) >= 2 and sys.argv[1] == "--watermarks":
        ap = argparse.ArgumentParser()
        ap.add_argument("--watermarks", action="store_true")
        ap.add_argument("db_path")
        ns = ap.parse_args()
        print(json.dumps(watermarks(ns.db_path), ensure_ascii=False))
        return 0

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
