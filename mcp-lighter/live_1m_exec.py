#!/usr/bin/env python3
"""CLI bridge: live 1m session → Lighter open/close/tp_sl (mcp-lighter server)."""
from __future__ import annotations

import argparse
import asyncio
import json
import sys
from typing import Any

# Ensure local imports
from pathlib import Path

ROOT = Path(__file__).resolve().parent
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))


def _loads(s: str) -> Any:
    if isinstance(s, (dict, list)):
        return s
    return json.loads(s)


async def _run(op: str, args: argparse.Namespace) -> dict:
    import server as srv

    await srv.runtime.start()
    mid = int(args.market_id or 120)
    dry = bool(args.dry_run)

    try:
        if op == "open_long":
            raw = await srv.open_long(
                market_id=mid,
                quote_usd=float(args.quote_usd) if args.quote_usd is not None else None,
                size=float(args.size) if args.size is not None else None,
                dry_run=dry,
            )
            return _loads(raw)

        if op == "open_short":
            raw = await srv.open_short(
                market_id=mid,
                quote_usd=float(args.quote_usd) if args.quote_usd is not None else None,
                size=float(args.size) if args.size is not None else None,
                dry_run=dry,
            )
            return _loads(raw)

        if op == "close":
            raw = await srv.close_position(
                market_id=mid,
                size=float(args.size) if args.size is not None else None,
                dry_run=dry,
            )
            return _loads(raw)

        if op == "place_tp_sl":
            raw = await srv.place_tp_sl(
                take_profit=float(args.tp),
                stop_loss=float(args.sl),
                market_id=mid,
                size=float(args.size) if args.size is not None else None,
                dry_run=dry,
            )
            return _loads(raw)

        if op == "place_tp":
            raw = await srv.place_take_profit(
                trigger_price=float(args.tp),
                market_id=mid,
                size=float(args.size) if args.size is not None else None,
                dry_run=dry,
            )
            return _loads(raw)

        if op == "place_sl":
            raw = await srv.place_stop_loss(
                trigger_price=float(args.sl),
                market_id=mid,
                size=float(args.size) if args.size is not None else None,
                dry_run=dry,
            )
            return _loads(raw)

        if op == "orders":
            raw = await srv.get_active_orders(market_id=mid)
            return _loads(raw)

        if op == "cancel":
            raw = await srv.cancel_order(
                order_index=int(args.order_index),
                market_id=mid,
            )
            return _loads(raw)

        if op == "positions":
            raw = await srv.get_positions(active_only=True)
            return _loads(raw)

        if op == "flatten":
            # Close position + cancel all orders on market_id; verify once more.
            mid = int(args.market_id or 120)
            return await _flatten_market(srv, mid, dry=dry)

        if op == "flatten_all":
            # Close every open position and cancel orders on those markets; verify.
            return await _flatten_all(srv, dry=dry)

        return {"ok": False, "error": f"unknown op {op}"}
    finally:
        await srv.runtime.stop()


async def _cancel_all_orders(srv: Any, market_id: int) -> list[dict[str, Any]]:
    raw = _loads(await srv.get_active_orders(market_id=market_id))
    orders = raw.get("orders") or []
    cancelled: list[dict[str, Any]] = []
    for o in orders:
        if not isinstance(o, dict):
            continue
        oi = o.get("order_index") or o.get("order_id")
        if oi is None:
            continue
        try:
            r = _loads(await srv.cancel_order(order_index=int(oi), market_id=market_id))
            cancelled.append({"order_index": int(oi), "result": r})
        except Exception as e:  # noqa: BLE001
            cancelled.append({"order_index": int(oi), "error": str(e)})
    return cancelled


async def _flatten_market(srv: Any, market_id: int, *, dry: bool = False) -> dict[str, Any]:
    steps: list[dict[str, Any]] = []
    # 1) cancel orders first (TP/SL won't fire during close)
    cancelled = await _cancel_all_orders(srv, market_id)
    steps.append({"cancel_orders": cancelled, "n": len(cancelled)})

    # 2) close full position if any
    close_res = None
    try:
        close_res = _loads(
            await srv.close_position(market_id=market_id, size=None, dry_run=dry)
        )
    except Exception as e:  # noqa: BLE001
        close_res = {"ok": False, "error": str(e)}
    steps.append({"close": close_res})

    # brief settle
    await asyncio.sleep(1.2)

    # 3) cancel leftovers again
    cancelled2 = await _cancel_all_orders(srv, market_id)
    steps.append({"cancel_orders_again": cancelled2, "n": len(cancelled2)})

    await asyncio.sleep(0.8)

    # 4) verify
    pos_raw = _loads(await srv.get_positions(active_only=True))
    still_pos = []
    for p in pos_raw.get("positions") or []:
        if int(p.get("market_id") or -1) != market_id:
            continue
        try:
            sz = abs(float(p.get("position") or 0))
        except (TypeError, ValueError):
            sz = 0.0
        if sz > 1e-12:
            still_pos.append(p)
    ord_raw = _loads(await srv.get_active_orders(market_id=market_id))
    still_ord = ord_raw.get("orders") or []

    # 5) one more pass if anything left
    verify_retry = None
    if still_pos or still_ord:
        if still_ord:
            await _cancel_all_orders(srv, market_id)
        if still_pos and not dry:
            try:
                verify_retry = _loads(await srv.close_position(market_id=market_id, dry_run=False))
            except Exception as e:  # noqa: BLE001
                verify_retry = {"ok": False, "error": str(e)}
        await asyncio.sleep(1.0)
        pos_raw = _loads(await srv.get_positions(active_only=True))
        still_pos = []
        for p in pos_raw.get("positions") or []:
            if int(p.get("market_id") or -1) != market_id:
                continue
            try:
                sz = abs(float(p.get("position") or 0))
            except (TypeError, ValueError):
                sz = 0.0
            if sz > 1e-12:
                still_pos.append(p)
        ord_raw = _loads(await srv.get_active_orders(market_id=market_id))
        still_ord = ord_raw.get("orders") or []

    flat = len(still_pos) == 0 and len(still_ord) == 0
    return {
        "ok": flat,
        "flat": flat,
        "market_id": market_id,
        "steps": steps,
        "verify_retry": verify_retry,
        "remaining_positions": still_pos,
        "remaining_orders": still_ord,
    }


async def _flatten_all(srv: Any, *, dry: bool = False) -> dict[str, Any]:
    pos_raw = _loads(await srv.get_positions(active_only=True))
    markets: set[int] = set()
    for p in pos_raw.get("positions") or []:
        try:
            sz = abs(float(p.get("position") or 0))
        except (TypeError, ValueError):
            sz = 0.0
        if sz > 1e-12:
            markets.add(int(p.get("market_id")))
    # always include 120 (live default) so TP/SL without position are cleared
    markets.add(120)
    results = []
    for mid in sorted(markets):
        results.append(await _flatten_market(srv, mid, dry=dry))
    flat = all(bool(r.get("flat")) for r in results)
    return {"ok": flat, "flat": flat, "markets": sorted(markets), "results": results}


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument(
        "op",
        choices=[
            "open_long",
            "open_short",
            "close",
            "place_tp_sl",
            "place_tp",
            "place_sl",
            "orders",
            "cancel",
            "positions",
            "flatten",
            "flatten_all",
        ],
    )
    ap.add_argument("--market-id", type=int, default=120)
    ap.add_argument("--quote-usd", type=float, default=None)
    ap.add_argument("--size", type=float, default=None)
    ap.add_argument("--tp", type=float, default=None)
    ap.add_argument("--sl", type=float, default=None)
    ap.add_argument("--order-index", type=int, default=None)
    ap.add_argument("--dry-run", action="store_true")
    ns = ap.parse_args()
    try:
        out = asyncio.run(_run(ns.op, ns))
        if not isinstance(out, dict):
            out = {"ok": True, "data": out}
        if "ok" not in out:
            out["ok"] = True
        print(json.dumps(out, ensure_ascii=True, default=str))
        return 0
    except Exception as e:  # noqa: BLE001
        print(json.dumps({"ok": False, "error": str(e)}, ensure_ascii=True))
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
