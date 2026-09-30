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

        if op == "positions":
            raw = await srv.get_positions(active_only=True)
            return _loads(raw)

        return {"ok": False, "error": f"unknown op {op}"}
    finally:
        await srv.runtime.stop()


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument(
        "op",
        choices=["open_long", "open_short", "close", "place_tp_sl", "place_tp", "place_sl", "positions"],
    )
    ap.add_argument("--market-id", type=int, default=120)
    ap.add_argument("--quote-usd", type=float, default=None)
    ap.add_argument("--size", type=float, default=None)
    ap.add_argument("--tp", type=float, default=None)
    ap.add_argument("--sl", type=float, default=None)
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
