#!/usr/bin/env python3
"""Track latest Lighter position + orders for live-1m (adopt / resize / reconcile)."""
from __future__ import annotations

import argparse
import asyncio
import json
import os
import sys
import time
from pathlib import Path
from typing import Any, Optional

ROOT = Path(__file__).resolve().parent
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from db import connect  # noqa: E402
from dotenv import load_dotenv  # noqa: E402

load_dotenv(ROOT / ".env")

SCHEMA_EXTRA = """
CREATE TABLE IF NOT EXISTS live_ex_state (
    id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    account_index BIGINT NOT NULL,
    market_id INT NOT NULL,
    session_id BIGINT NULL,
    symbol VARCHAR(64) NULL,
    side VARCHAR(8) NULL,
    size DOUBLE NULL,
    entry_price DOUBLE NULL,
    position_value DOUBLE NULL,
    unrealized_pnl DOUBLE NULL,
    lot_usd DOUBLE NULL,
    mark_price DOUBLE NULL,
    liquidation_price DOUBLE NULL,
    open_order_count INT NULL,
    adopted TINYINT NOT NULL DEFAULT 0,
    source VARCHAR(32) NOT NULL DEFAULT 'sync',
    raw_json MEDIUMTEXT NULL,
    updated_at BIGINT NOT NULL,
    UNIQUE KEY uq_live_ex_state (account_index, market_id),
    KEY idx_live_ex_state_sess (session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS live_ex_orders (
    id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    account_index BIGINT NOT NULL,
    market_id INT NOT NULL,
    session_id BIGINT NULL,
    order_index BIGINT NOT NULL,
    client_order_index BIGINT NULL,
    order_type VARCHAR(64) NULL,
    is_ask TINYINT NULL,
    side VARCHAR(8) NULL,
    size DOUBLE NULL,
    remaining_size DOUBLE NULL,
    price DOUBLE NULL,
    trigger_price DOUBLE NULL,
    reduce_only TINYINT NULL,
    status VARCHAR(32) NULL,
    lot_usd DOUBLE NULL,
    raw_json MEDIUMTEXT NULL,
    updated_at BIGINT NOT NULL,
    UNIQUE KEY uq_live_ex_ord (account_index, market_id, order_index),
    KEY idx_live_ex_ord_mkt (account_index, market_id),
    KEY idx_live_ex_ord_sess (session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
"""


def _now() -> int:
    return int(time.time())


def _f(v: Any, default: Optional[float] = None) -> Optional[float]:
    if v is None or v == "":
        return default
    try:
        return float(v)
    except (TypeError, ValueError):
        return default


def _account_index() -> int:
    raw = (os.environ.get("LIGHTER_ACCOUNT_INDEX") or "").strip()
    if not raw:
        raise RuntimeError("LIGHTER_ACCOUNT_INDEX not set")
    return int(raw)


def _ensure_schema() -> None:
    con = connect(ensure_schema=True)
    try:
        con.executescript(SCHEMA_EXTRA)
        con.commit()
    finally:
        con.close()


def _order_detail(o: dict[str, Any]) -> dict[str, Any]:
    size = _f(o.get("initial_base_amount") or o.get("base_amount") or o.get("size"), 0.0) or 0.0
    rem = _f(o.get("remaining_base_amount") or o.get("remaining_size"), size) or 0.0
    px = _f(o.get("trigger_price")) or _f(o.get("price")) or 0.0
    lot = rem * px if rem and px else None
    is_ask = o.get("is_ask")
    if isinstance(is_ask, str):
        is_ask = is_ask.lower() in ("1", "true", "yes")
    side = o.get("side") or ("ask/sell" if is_ask else "bid/buy")
    return {
        "order_index": int(o["order_index"]) if o.get("order_index") is not None else None,
        "client_order_index": int(o["client_order_index"]) if o.get("client_order_index") not in (None, "") else None,
        "order_id": str(o.get("order_id") or o.get("order_index") or ""),
        "order_type": str(o.get("type") or o.get("order_type") or ""),
        "is_ask": bool(is_ask) if is_ask is not None else None,
        "side": str(side),
        "size": size,
        "remaining_size": rem,
        "price": _f(o.get("price")),
        "trigger_price": _f(o.get("trigger_price")),
        "reduce_only": bool(o.get("reduce_only")) if o.get("reduce_only") is not None else None,
        "status": str(o.get("status") or ""),
        "trigger_status": str(o.get("trigger_status") or ""),
        "time_in_force": str(o.get("time_in_force") or ""),
        "lot_usd": round(lot, 4) if lot is not None else None,
        "filled_base_amount": _f(o.get("filled_base_amount")),
        "filled_quote_amount": _f(o.get("filled_quote_amount")),
        "created_at": o.get("created_at") or o.get("timestamp"),
        "raw": o,
    }


async def fetch_exchange(market_id: int = 120) -> dict[str, Any]:
    import server as srv

    await srv.runtime.start()
    try:
        acc_idx = _account_index()
        pos_raw = json.loads(await srv.get_positions(active_only=True))
        orders_raw = json.loads(await srv.get_active_orders(market_id=market_id))
        positions = pos_raw.get("positions") or []
        pos = None
        for p in positions:
            if int(p.get("market_id") or -1) != market_id:
                continue
            size = abs(_f(p.get("position"), 0.0) or 0.0)
            if size < 1e-12:
                continue
            sign = int(p.get("sign") or 0)
            side = p.get("side") or ("long" if sign > 0 else "short" if sign < 0 else "flat")
            entry = _f(p.get("avg_entry_price"))
            lot = (size * entry) if entry else None
            pos = {
                "market_id": market_id,
                "symbol": p.get("symbol"),
                "side": side,
                "sign": sign,
                "size": size,
                "entry_price": entry,
                "position_value": _f(p.get("position_value")),
                "unrealized_pnl": _f(p.get("unrealized_pnl")),
                "realized_pnl": _f(p.get("realized_pnl")),
                "liquidation_price": _f(p.get("liquidation_price")),
                "lot_usd": round(lot, 4) if lot is not None else None,
                "open_order_count": int(p.get("open_order_count") or 0),
                "pending_order_count": int(p.get("pending_order_count") or 0),
                "raw": p,
            }
            break

        orders = []
        for o in orders_raw.get("orders") or []:
            if not isinstance(o, dict):
                continue
            mid = int(o.get("market_index") if o.get("market_index") is not None else o.get("market_id") or market_id)
            if mid != market_id:
                continue
            orders.append(_order_detail(o))

        orders_lot = round(sum((x.get("lot_usd") or 0.0) for x in orders), 4)
        return {
            "ok": True,
            "account_index": acc_idx,
            "market_id": market_id,
            "position": pos,
            "orders": orders,
            "summary": {
                "has_position": pos is not None,
                "position_lot_usd": (pos or {}).get("lot_usd"),
                "orders_count": len(orders),
                "orders_lot_usd": orders_lot,
                "tp_count": sum(1 for x in orders if "take-profit" in (x.get("order_type") or "").lower() or (x.get("order_type") or "").lower() == "tp"),
                "sl_count": sum(1 for x in orders if "stop-loss" in (x.get("order_type") or "").lower() or (x.get("order_type") or "").lower() == "sl"),
            },
            "fetched_at": _now(),
        }
    finally:
        await srv.runtime.stop()


def save_snapshot(
    snap: dict[str, Any],
    *,
    session_id: Optional[int] = None,
    source: str = "sync",
    adopted: bool = False,
) -> dict[str, Any]:
    _ensure_schema()
    acc = int(snap["account_index"])
    mid = int(snap["market_id"])
    pos = snap.get("position")
    now = _now()
    con = connect()
    try:
        if pos:
            con.execute(
                """
                INSERT INTO live_ex_state (
                    account_index, market_id, session_id, symbol, side, size, entry_price,
                    position_value, unrealized_pnl, lot_usd, mark_price, liquidation_price,
                    open_order_count, adopted, source, raw_json, updated_at
                ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE
                    session_id=VALUES(session_id), symbol=VALUES(symbol), side=VALUES(side),
                    size=VALUES(size), entry_price=VALUES(entry_price),
                    position_value=VALUES(position_value), unrealized_pnl=VALUES(unrealized_pnl),
                    lot_usd=VALUES(lot_usd), mark_price=VALUES(mark_price),
                    liquidation_price=VALUES(liquidation_price),
                    open_order_count=VALUES(open_order_count),
                    adopted=VALUES(adopted), source=VALUES(source),
                    raw_json=VALUES(raw_json), updated_at=VALUES(updated_at)
                """,
                (
                    acc,
                    mid,
                    session_id,
                    pos.get("symbol"),
                    pos.get("side"),
                    pos.get("size"),
                    pos.get("entry_price"),
                    pos.get("position_value"),
                    pos.get("unrealized_pnl"),
                    pos.get("lot_usd"),
                    None,
                    pos.get("liquidation_price"),
                    pos.get("open_order_count"),
                    1 if adopted else 0,
                    source,
                    json.dumps(pos.get("raw") or pos, ensure_ascii=True, default=str),
                    now,
                ),
            )
        else:
            # flat — clear position row but keep adopted flag false
            con.execute(
                """
                INSERT INTO live_ex_state (
                    account_index, market_id, session_id, symbol, side, size, entry_price,
                    position_value, unrealized_pnl, lot_usd, mark_price, liquidation_price,
                    open_order_count, adopted, source, raw_json, updated_at
                ) VALUES (?,?,?,?,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,?,?,?,?)
                ON DUPLICATE KEY UPDATE
                    session_id=VALUES(session_id), side=NULL, size=NULL, entry_price=NULL,
                    position_value=NULL, unrealized_pnl=NULL, lot_usd=NULL,
                    liquidation_price=NULL, open_order_count=0, adopted=VALUES(adopted),
                    source=VALUES(source), raw_json=NULL, updated_at=VALUES(updated_at)
                """,
                (acc, mid, session_id, snap.get("symbol"), 1 if adopted else 0, source, now),
            )

        # replace orders for this market
        con.execute(
            "DELETE FROM live_ex_orders WHERE account_index=? AND market_id=?",
            (acc, mid),
        )
        for o in snap.get("orders") or []:
            oi = o.get("order_index")
            if oi is None:
                continue
            con.execute(
                """
                INSERT INTO live_ex_orders (
                    account_index, market_id, session_id, order_index, client_order_index,
                    order_type, is_ask, side, size, remaining_size, price, trigger_price,
                    reduce_only, status, lot_usd, raw_json, updated_at
                ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                """,
                (
                    acc,
                    mid,
                    session_id,
                    int(oi),
                    o.get("client_order_index"),
                    o.get("order_type"),
                    1 if o.get("is_ask") else 0 if o.get("is_ask") is not None else None,
                    o.get("side"),
                    o.get("size"),
                    o.get("remaining_size"),
                    o.get("price"),
                    o.get("trigger_price"),
                    1 if o.get("reduce_only") else 0 if o.get("reduce_only") is not None else None,
                    o.get("status"),
                    o.get("lot_usd"),
                    json.dumps(o.get("raw") or o, ensure_ascii=True, default=str),
                    now,
                ),
            )
        con.commit()
        return {"ok": True, "account_index": acc, "market_id": mid, "orders_saved": len(snap.get("orders") or []), "updated_at": now}
    finally:
        con.close()


def load_db(market_id: int = 120) -> dict[str, Any]:
    _ensure_schema()
    acc = _account_index()
    con = connect()
    try:
        row = con.execute(
            "SELECT * FROM live_ex_state WHERE account_index=? AND market_id=?",
            (acc, market_id),
        ).fetchone()
        orders = con.execute(
            "SELECT * FROM live_ex_orders WHERE account_index=? AND market_id=? ORDER BY order_index",
            (acc, market_id),
        ).fetchall()
        pos = dict(row) if row else None
        ords = [dict(o) for o in orders]
        return {"ok": True, "account_index": acc, "market_id": market_id, "position": pos, "orders": ords}
    finally:
        con.close()


def reconcile(market_id: int, session_id: Optional[int] = None) -> dict[str, Any]:
    """Compare DB snapshot vs live exchange; refresh DB; return diffs."""
    live = asyncio.run(fetch_exchange(market_id))
    db = load_db(market_id)
    diffs: list[dict[str, Any]] = []

    lp = live.get("position")
    dp = db.get("position")
    if bool(lp) != bool(dp and dp.get("side") and (dp.get("size") or 0) > 0):
        diffs.append({
            "field": "position_presence",
            "db": bool(dp and dp.get("side")),
            "exchange": bool(lp),
        })
    if lp and dp:
        for field, key_l, key_d, tol in (
            ("side", "side", "side", None),
            ("size", "size", "size", 1e-6),
            ("entry_price", "entry_price", "entry_price", 1e-6),
            ("lot_usd", "lot_usd", "lot_usd", 0.05),
        ):
            a, b = lp.get(key_l), dp.get(key_d)
            if tol is None:
                if str(a) != str(b):
                    diffs.append({"field": field, "db": b, "exchange": a})
            else:
                try:
                    if abs(float(a or 0) - float(b or 0)) > tol:
                        diffs.append({"field": field, "db": b, "exchange": a})
                except (TypeError, ValueError):
                    diffs.append({"field": field, "db": b, "exchange": a})

    live_oids = {int(o["order_index"]) for o in live.get("orders") or [] if o.get("order_index") is not None}
    db_oids = {int(o["order_index"]) for o in db.get("orders") or [] if o.get("order_index") is not None}
    if live_oids != db_oids:
        diffs.append({
            "field": "order_indexes",
            "db_only": sorted(db_oids - live_oids),
            "exchange_only": sorted(live_oids - db_oids),
        })

    adopted = bool(dp and dp.get("adopted")) if dp else False
    save_snapshot(live, session_id=session_id, source="reconcile", adopted=adopted)
    return {
        "ok": True,
        "matched": len(diffs) == 0,
        "diffs": diffs,
        "exchange": live,
        "db_before": db,
    }


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("op", choices=["snapshot", "save", "load", "reconcile", "ensure_schema"])
    ap.add_argument("--market-id", type=int, default=120)
    ap.add_argument("--session-id", type=int, default=None)
    ap.add_argument("--source", default="sync")
    ap.add_argument("--adopted", action="store_true")
    ns = ap.parse_args()
    try:
        if ns.op == "ensure_schema":
            _ensure_schema()
            out = {"ok": True}
        elif ns.op == "snapshot":
            out = asyncio.run(fetch_exchange(ns.market_id))
        elif ns.op == "save":
            snap = asyncio.run(fetch_exchange(ns.market_id))
            saved = save_snapshot(
                snap,
                session_id=ns.session_id,
                source=ns.source,
                adopted=ns.adopted,
            )
            out = {**snap, "saved": saved}
        elif ns.op == "load":
            out = load_db(ns.market_id)
        else:
            out = reconcile(ns.market_id, ns.session_id)
        print(json.dumps(out, ensure_ascii=True, default=str))
        return 0
    except Exception as e:  # noqa: BLE001
        print(json.dumps({"ok": False, "error": str(e)}, ensure_ascii=True))
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
