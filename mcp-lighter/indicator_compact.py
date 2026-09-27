#!/usr/bin/env python3
"""Build compact indicator snapshot JSON for LLM agents."""
from __future__ import annotations

import argparse
import json
import sys
import time

from db import connect

# Short indicator codes for token savings
CODE = {
    "MACD(12,26,9) cross": "macd",
    "RSI(14) 30/70": "rsi",
    "SMA(10/30) cross": "sma",
    "EMA(12/26) cross": "ema",
    "Bollinger(20,2) bounce": "bb",
    "ROC(10) zero-cross": "roc",
    "Momentum(10) flip": "mom",
    "ATR(14) pct": "atr",
    "RV(48) log": "rv",
    "Bollinger(20,2) width": "bbw",
}
VOL = {"atr", "rv", "bbw"}


def short(name: str) -> str:
    return CODE.get(name, name)


def rnum(v, nd=4):
    if v is None:
        return None
    try:
        return round(float(v), nd)
    except (TypeError, ValueError):
        return None


def build(db_path: str, market_id: int | None, events_per_tf: int) -> dict:
    try:
        con = connect(db_path)
    except Exception as e:
        return {"ok": False, "error": str(e), "db_path": db_path}
    try:
        q = "SELECT DISTINCT market_id, symbol FROM indicator_stats"
        args: list = []
        if market_id is not None:
            q += " WHERE market_id=?"
            args.append(market_id)
        markets = con.execute(q, args).fetchall()
        if not markets:
            # fallback from snapshots
            q2 = "SELECT DISTINCT market_id, symbol FROM indicator_snapshots"
            args2: list = []
            if market_id is not None:
                q2 += " WHERE market_id=?"
                args2.append(market_id)
            markets = con.execute(q2, args2).fetchall()

        payload = {
            "ok": True,
            "v": 1,
            "gen": int(time.time()),
            "note": "keys: r=return% pf=profit_factor a=accuracy n=signals ls=last_signal e=events[t,i,s,v,c]",
            "m": [],
        }

        for m in markets:
            mid = int(m["market_id"])
            sym = m["symbol"] or f"ID:{mid}"
            tfs = {}
            resolutions = [
                r[0]
                for r in con.execute(
                    "SELECT DISTINCT resolution FROM indicator_stats WHERE market_id=? ORDER BY resolution",
                    (mid,),
                ).fetchall()
            ]
            if not resolutions:
                resolutions = [
                    r[0]
                    for r in con.execute(
                        "SELECT DISTINCT resolution FROM indicator_snapshots WHERE market_id=? ORDER BY resolution",
                        (mid,),
                    ).fetchall()
                ]

            for res in resolutions:
                stats_rows = con.execute(
                    """
                    SELECT indicator, strategy_return_pct, profit_factor, accuracy,
                           signals, wins, losses, last_signal, last_value, bar_ts
                    FROM indicator_stats
                    WHERE market_id=? AND resolution=?
                    """,
                    (mid, res),
                ).fetchall()

                vol = {}
                rank = []
                for s in stats_rows:
                    code = short(s["indicator"])
                    if code in VOL:
                        vol[code] = rnum(s["last_value"], 4)
                        continue
                    rank.append(
                        {
                            "i": code,
                            "r": rnum(s["strategy_return_pct"], 2),
                            "pf": rnum(s["profit_factor"], 3),
                            "a": rnum(s["accuracy"], 3),
                            "n": s["signals"],
                            "ls": s["last_signal"],
                        }
                    )
                rank.sort(key=lambda x: (x["r"] is None, -(x["r"] or 0)))

                last_close = con.execute(
                    """
                    SELECT close FROM indicator_snapshots
                    WHERE market_id=? AND resolution=?
                    ORDER BY bar_ts DESC LIMIT 1
                    """,
                    (mid, res),
                ).fetchone()
                bars = con.execute(
                    """
                    SELECT COUNT(DISTINCT bar_ts) FROM indicator_snapshots
                    WHERE market_id=? AND resolution=?
                    """,
                    (mid, res),
                ).fetchone()[0]

                # non-zero signal events, newest first, capped
                ev_rows = con.execute(
                    """
                    SELECT bar_ts, indicator, signal, value, close
                    FROM indicator_snapshots
                    WHERE market_id=? AND resolution=? AND signal IS NOT NULL AND signal != 0
                    ORDER BY bar_ts DESC
                    LIMIT ?
                    """,
                    (mid, res, events_per_tf),
                ).fetchall()
                events = [
                    {
                        "t": int(e["bar_ts"]),
                        "i": short(e["indicator"]),
                        "s": int(e["signal"]),
                        "v": rnum(e["value"], 4),
                        "c": rnum(e["close"], 4),
                    }
                    for e in reversed(ev_rows)
                ]

                tfs[res] = {
                    "bars": bars,
                    "px": rnum(last_close["close"], 4) if last_close else None,
                    "vol": vol,
                    "rank": rank,
                    "e": events,
                }

            payload["m"].append({"id": mid, "sym": sym, "tf": tfs})

        # size hint
        raw = json.dumps(payload, ensure_ascii=False, separators=(",", ":"))
        payload["bytes"] = len(raw.encode("utf-8"))
        return payload
    finally:
        con.close()


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("db_path")
    ap.add_argument("--market-id", type=int, default=None)
    ap.add_argument("--events", type=int, default=40)
    ns = ap.parse_args()
    print(json.dumps(build(ns.db_path, ns.market_id, ns.events), ensure_ascii=False, separators=(",", ":")))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
