#!/usr/bin/env python3
"""Build trading status JSON: position + all indicators + pos/session PnL."""
from __future__ import annotations

import argparse
import asyncio
import json
import sqlite3
import time
from pathlib import Path

ROOT = Path(__file__).resolve().parent
DEFAULT_DB = ROOT.parent / "data" / "indicator_history.sqlite"
SESSION = ROOT / "_lit_session_state.json"
CONTROL = ROOT / "_tg_control.json"


def _load_json(path: Path, default):
    if not path.is_file():
        return default
    return json.loads(path.read_text(encoding="utf-8-sig"))


def _lit_position(positions: list) -> dict | None:
    for p in positions:
        try:
            if int(p.get("market_id") or -1) == 120:
                return p
        except (TypeError, ValueError):
            pass
        if str(p.get("symbol") or "").upper() == "LIT":
            return p
    return None


def _notional(p: dict) -> float:
    try:
        size = abs(float(p.get("position") or p.get("size") or 0))
        entry = float(p.get("avg_entry_price") or p.get("entry_price") or 0)
        return size * entry
    except (TypeError, ValueError):
        return 0.0


def _indicators_from_db(db_path: Path, market_id: int = 120) -> dict:
    if not db_path.is_file():
        return {"ok": False, "error": "db missing", "by_tf": {}, "stats": []}
    con = sqlite3.connect(str(db_path))
    con.row_factory = sqlite3.Row
    try:
        stats = [
            dict(r)
            for r in con.execute(
                """
                SELECT resolution, indicator, strategy_return_pct, profit_factor,
                       accuracy, signals, last_signal, last_value, bar_ts
                FROM indicator_stats
                WHERE market_id=?
                ORDER BY resolution, indicator
                """,
                (market_id,),
            )
        ]
        by_tf: dict = {}
        for res_row in con.execute(
            "SELECT DISTINCT resolution FROM indicator_snapshots WHERE market_id=?",
            (market_id,),
        ):
            res = res_row[0]
            last_ts = con.execute(
                """
                SELECT MAX(bar_ts) FROM indicator_snapshots
                WHERE market_id=? AND resolution=?
                """,
                (market_id, res),
            ).fetchone()[0]
            if last_ts is None:
                continue
            rows = con.execute(
                """
                SELECT indicator, value, signal, close, bar_ts
                FROM indicator_snapshots
                WHERE market_id=? AND resolution=? AND bar_ts=?
                ORDER BY indicator
                """,
                (market_id, res, last_ts),
            ).fetchall()
            by_tf[res] = {
                "bar_ts": last_ts,
                "close": rows[0]["close"] if rows else None,
                "indicators": [
                    {
                        "name": r["indicator"],
                        "value": r["value"],
                        "signal": r["signal"],
                    }
                    for r in rows
                ],
            }
        return {"ok": True, "by_tf": by_tf, "stats": stats}
    finally:
        con.close()


async def build(market_id: int = 120, db_path: Path | None = None) -> dict:
    import server

    db_path = db_path or DEFAULT_DB
    st = _load_json(SESSION, {})
    ctrl = _load_json(CONTROL, {})
    owner = str(st.get("owner") or "roc").lower()
    if owner not in ("sma", "roc"):
        owner = "roc"
    block = st.get(owner) or {}
    sess_pnl = float(block.get("session_pnl") or 0)
    lot = block.get("lot")
    tf = block.get("timeframe") or st.get("timeframe")
    ind = block.get("indicator") or st.get("indicator")

    await server.runtime.start()
    try:
        pos_raw = json.loads(await server.get_positions())
    finally:
        await server.runtime.stop()

    positions = pos_raw.get("positions") or []
    lit = _lit_position(positions)
    if lit:
        side = str(lit.get("side") or "").lower()
        if side not in ("long", "short"):
            side = "long" if int(lit.get("sign") or 0) > 0 else "short"
        try:
            upnl = float(lit.get("unrealized_pnl"))
        except (TypeError, ValueError):
            upnl = None
        position = {
            "symbol": "LIT",
            "market_id": 120,
            "side": side,
            "size_lit": float(lit.get("position") or lit.get("size") or 0),
            "volume_usd": round(_notional(lit), 2),
            "entry": lit.get("avg_entry_price") or lit.get("entry_price"),
            "unrealized_pnl": upnl,
            "flat": False,
        }
        line = (
            f"LIT ${position['volume_usd']:.0f} {side} "
            f"{'+' if (upnl or 0) > 0 else ''}{upnl:.2f}"
            f" / sess {sess_pnl:+.2f} | {ind or 'ROC(10)'} {tf or ''}"
        ).strip()
    else:
        position = {
            "symbol": "LIT",
            "market_id": 120,
            "side": None,
            "size_lit": 0,
            "volume_usd": 0,
            "entry": None,
            "unrealized_pnl": 0,
            "flat": True,
        }
        line = f"LIT flat / sess {sess_pnl:+.2f} | {ind or 'ROC(10)'} {tf or ''}".strip()

    indicators = _indicators_from_db(Path(db_path), market_id)

    return {
        "ok": True,
        "updated_at": int(time.time()),
        "line": line,
        "position": position,
        "session": {
            "owner": owner,
            "session_pnl": sess_pnl,
            "lot": lot,
            "timeframe": tf,
            "indicator": ind,
            "sma_session_pnl": float((st.get("sma") or {}).get("session_pnl") or 0),
            "roc_session_pnl": float((st.get("roc") or {}).get("session_pnl") or 0),
        },
        "control": {
            "paused": ctrl.get("paused"),
            "roc_paused": ctrl.get("roc_paused"),
            "sma_paused": ctrl.get("sma_paused"),
            "ticks_stopped": ctrl.get("ticks_stopped"),
        },
        "indicators": indicators,
    }


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--market-id", type=int, default=120)
    ap.add_argument("--db", default=str(DEFAULT_DB))
    ns = ap.parse_args()
    data = asyncio.run(build(ns.market_id, Path(ns.db)))
    print(json.dumps(data, ensure_ascii=True))
    return 0 if data.get("ok") else 1


if __name__ == "__main__":
    raise SystemExit(main())
