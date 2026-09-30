#!/usr/bin/env python3
"""Build compact indicator snapshot JSON for LLM agents (Flash/Pro)."""
from __future__ import annotations

import argparse
import json
import sys
import time
from pathlib import Path

from db import connect

ROOT = Path(__file__).resolve().parent
TRADE = ROOT / "_agent_trade_state.json"
SESSION = ROOT / "_lit_session_state.json"

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


def _load_json(path: Path) -> dict:
    try:
        if path.is_file():
            data = json.loads(path.read_text(encoding="utf-8"))
            return data if isinstance(data, dict) else {}
    except (OSError, json.JSONDecodeError):
        pass
    return {}


def _position_stub(now: int) -> dict | None:
    trade = _load_json(TRADE)
    sess = _load_json(SESSION)
    pos = trade.get("position") if isinstance(trade.get("position"), dict) else {}
    side = pos.get("side") or trade.get("side")
    if not side and not sess:
        return None
    out: dict = {}
    if side:
        out["side"] = side
        out["size"] = rnum(pos.get("size") or pos.get("base_amount"), 4)
        out["entry"] = rnum(pos.get("entry") or pos.get("avg_entry"), 4)
        out["upnl"] = rnum(pos.get("unrealized_pnl") or pos.get("upnl"), 2)
    out["sess_pnl"] = rnum(
        sess.get("session_pnl") if sess.get("session_pnl") is not None else trade.get("session_pnl"),
        2,
    )
    out["lot"] = rnum(trade.get("lot") or trade.get("lot_usd"), 0)
    out["gen"] = now
    return out


def build(
    db_path: str,
    market_id: int | None,
    events_per_tf: int,
    candles_per_tf: int = 24,
) -> dict:
    try:
        con = connect(db_path)
    except Exception as e:
        return {"ok": False, "error": str(e), "db_path": db_path}
    try:
        now = int(time.time())
        q = "SELECT DISTINCT market_id, symbol FROM indicator_stats"
        args: list = []
        if market_id is not None:
            q += " WHERE market_id=?"
            args.append(market_id)
        markets = con.execute(q, args).fetchall()
        if not markets:
            q2 = "SELECT DISTINCT market_id, symbol FROM indicator_snapshots"
            args2: list = []
            if market_id is not None:
                q2 += " WHERE market_id=?"
                args2.append(market_id)
            markets = con.execute(q2, args2).fetchall()

        payload: dict = {
            "ok": True,
            "v": 2,
            "gen": now,
            "note": (
                "keys: r=return% pf=profit_factor a=accuracy n=signals ls=last_signal "
                "e=events[t,i,s,v,c,age] ohlc=[t,o,h,l,c,v] vs=vol_summary age=sec_since_bar "
                "pos=position/sess stub"
            ),
            "m": [],
        }
        pos = _position_stub(now)
        if pos:
            payload["pos"] = pos

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
                    age = None
                    if s["bar_ts"]:
                        try:
                            age = max(0, now - int(int(s["bar_ts"]) / 1000))
                        except (TypeError, ValueError):
                            age = None
                    if code in VOL:
                        vol[code] = {"v": rnum(s["last_value"], 4), "age": age}
                        continue
                    rank.append(
                        {
                            "i": code,
                            "r": rnum(s["strategy_return_pct"], 2),
                            "pf": rnum(s["profit_factor"], 3),
                            "a": rnum(s["accuracy"], 3),
                            "n": s["signals"],
                            "ls": s["last_signal"],
                            "age": age,
                            "warm": bool(s["signals"] is not None and int(s["signals"] or 0) >= 10),
                        }
                    )
                rank.sort(key=lambda x: (x["r"] is None, -(x["r"] or 0)))

                last_close = con.execute(
                    """
                    SELECT close, bar_ts FROM indicator_snapshots
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

                # OHLCV: newest first from DB, reverse to oldest→newest for LLM
                ohlc_rows = []
                try:
                    ohlc_rows = con.execute(
                        """
                        SELECT bar_ts, open, high, low, close, volume
                        FROM indicator_candles
                        WHERE market_id=? AND resolution=?
                        ORDER BY bar_ts DESC
                        LIMIT ?
                        """,
                        (mid, res, candles_per_tf),
                    ).fetchall()
                except Exception:  # noqa: BLE001
                    ohlc_rows = []

                ohlc = []
                for row in reversed(ohlc_rows):
                    ohlc.append(
                        [
                            int(row["bar_ts"]),
                            rnum(row["open"], 4),
                            rnum(row["high"], 4),
                            rnum(row["low"], 4),
                            rnum(row["close"], 4),
                            rnum(row["volume"], 2),
                        ]
                    )

                vs = None
                if ohlc:
                    vols = [x[5] for x in ohlc if x[5] is not None]
                    closes = [x[4] for x in ohlc if x[4] is not None]
                    highs = [x[2] for x in ohlc if x[2] is not None]
                    lows = [x[3] for x in ohlc if x[3] is not None]
                    vs = {
                        "n": len(ohlc),
                        "v_sum": rnum(sum(vols), 2) if vols else None,
                        "v_last": vols[-1] if vols else None,
                        "v_avg": rnum(sum(vols) / len(vols), 2) if vols else None,
                        "range": rnum((max(highs) - min(lows)) / closes[-1] * 100, 3)
                        if highs and lows and closes and closes[-1]
                        else None,
                        "ret": rnum((closes[-1] / closes[0] - 1) * 100, 3)
                        if len(closes) >= 2 and closes[0]
                        else None,
                    }

                last_bar_ts = int(ohlc[-1][0]) if ohlc else (
                    int(last_close["bar_ts"]) if last_close and last_close["bar_ts"] else None
                )
                age_bar = max(0, now - int(last_bar_ts / 1000)) if last_bar_ts else None

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
                events = []
                for e in reversed(ev_rows):
                    ts = int(e["bar_ts"])
                    events.append(
                        {
                            "t": ts,
                            "i": short(e["indicator"]),
                            "s": int(e["signal"]),
                            "v": rnum(e["value"], 4),
                            "c": rnum(e["close"], 4),
                            "age": max(0, now - int(ts / 1000)),
                        }
                    )

                tfs[res] = {
                    "bars": bars,
                    "px": rnum(last_close["close"], 4) if last_close else None,
                    "age": age_bar,
                    "vol": vol,
                    "rank": rank,
                    "vs": vs,
                    "ohlc": ohlc,
                    "e": events,
                }

            payload["m"].append({"id": mid, "sym": sym, "tf": tfs})

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
    ap.add_argument("--candles", type=int, default=24, help="OHLCV bars per TF (oldest→newest)")
    ns = ap.parse_args()
    candles = max(5, min(120, int(ns.candles)))
    events = max(5, min(200, int(ns.events)))
    print(
        json.dumps(
            build(ns.db_path, ns.market_id, events, candles),
            ensure_ascii=False,
            separators=(",", ":"),
        )
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
