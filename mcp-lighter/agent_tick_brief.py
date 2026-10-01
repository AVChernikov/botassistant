#!/usr/bin/env python3
"""Build one JSON brief for Cursor 2m agent tick (minimize token burn).

Writes: mcp-lighter/_agent_tick_brief.json
Called from scripts/cron_tg_queue.ps1 after tg_queue_cron_once.py.
"""
from __future__ import annotations

import asyncio
import json
import time
from pathlib import Path

from db import DEFAULT_DSN, connect

ROOT = Path(__file__).resolve().parent
OUT = ROOT / "_agent_tick_brief.json"
INBOX = ROOT / "_tg_agent_inbox.json"
SESSION = ROOT / "_lit_session_state.json"
CONTROL = ROOT / "_tg_control.json"
TRADE = ROOT / "_agent_trade_state.json"
KILL_ALERT_STATE = ROOT / "_tg_kill_alert.json"
IND = "ROC(10) zero-cross"
KILL_LO = -50.0
KILL_HI = 100.0
KILL_ALERT_USD = 15.0
KILL_ALERT_COOLDOWN_SEC = 20 * 60
BOOK_LEVELS = 3


def _load(path: Path, default):
    if not path.is_file():
        return default
    try:
        return json.loads(path.read_text(encoding="utf-8-sig"))
    except (OSError, json.JSONDecodeError):
        return default


def _bar_short(ts) -> str | None:
    if ts is None:
        return None
    try:
        return time.strftime("%m-%d %H:%M", time.gmtime(int(ts)))
    except (TypeError, ValueError, OSError):
        return None


def _f(v) -> float | None:
    try:
        if v is None:
            return None
        return float(v)
    except (TypeError, ValueError):
        return None


def _roc(market_id: int = 120) -> dict:
    con = connect()
    out: dict = {}
    try:
        for res in ("1h", "12h"):
            last_ts = con.execute(
                "SELECT MAX(bar_ts) FROM indicator_snapshots WHERE market_id=? AND resolution=?",
                (market_id, res),
            ).fetchone()[0]
            row = con.execute(
                """
                SELECT value, signal, close, bar_ts FROM indicator_snapshots
                WHERE market_id=? AND resolution=? AND indicator=? AND bar_ts=?
                """,
                (market_id, res, IND, last_ts),
            ).fetchone()
            prev = con.execute(
                """
                SELECT value, signal, close, bar_ts FROM indicator_snapshots
                WHERE market_id=? AND resolution=? AND indicator=? AND bar_ts < ?
                ORDER BY bar_ts DESC LIMIT 1
                """,
                (market_id, res, IND, last_ts or 0),
            ).fetchone()
            out[res] = {
                "bar": _bar_short(last_ts),
                "bar_ts": int(last_ts) if last_ts is not None else None,
                "value": float(row["value"]) if row and row["value"] is not None else None,
                "signal": int(row["signal"]) if row and row["signal"] is not None else None,
                "close": float(row["close"]) if row and row["close"] is not None else None,
                "prev_value": float(prev["value"]) if prev and prev["value"] is not None else None,
                "prev_signal": int(prev["signal"]) if prev and prev["signal"] is not None else None,
            }
    finally:
        con.close()
    return out


async def _market_snapshot(market_id: int = 120) -> dict:
    """Volume/OI/price/spread + top-of-book + funding (Lighter)."""
    import lighter
    import server as srv

    out: dict = {"market_id": market_id, "ok": False}
    started_here = False
    try:
        if srv.runtime.api_client is None:
            await srv.runtime.start()
            started_here = True
        assert srv.runtime.order_api is not None
        m = await srv.runtime.find_market(market_id=market_id)
        last = _f(m.get("last_trade_price"))
        mark = _f(m.get("mark_price"))
        index = _f(m.get("index_price"))
        vol_quote = _f(m.get("daily_quote_token_volume"))
        vol_base = _f(m.get("daily_base_token_volume"))
        oi = _f(m.get("open_interest"))

        book = await srv.runtime.order_api.order_book_orders(
            market_id=market_id, limit=BOOK_LEVELS
        )
        bids_raw = getattr(book, "bids", None) or []
        asks_raw = getattr(book, "asks", None) or []

        def _lvl(rows, n: int) -> list[dict]:
            levels: list[dict] = []
            for r in rows[:n]:
                d = r.to_dict() if hasattr(r, "to_dict") else (
                    r.model_dump() if hasattr(r, "model_dump") else dict(r)
                )
                levels.append(
                    {
                        "px": _f(d.get("price")),
                        "sz": _f(d.get("remaining_base_amount") or d.get("initial_base_amount")),
                    }
                )
            return levels

        bids = _lvl(bids_raw, BOOK_LEVELS)
        asks = _lvl(asks_raw, BOOK_LEVELS)
        best_bid = bids[0]["px"] if bids else None
        best_ask = asks[0]["px"] if asks else None
        mid = None
        spread = None
        spread_bps = None
        if best_bid is not None and best_ask is not None:
            mid = (best_bid + best_ask) / 2.0
            spread = best_ask - best_bid
            if mid > 0:
                spread_bps = round(spread / mid * 10000.0, 2)

        funding_rate = None
        funding_pct = None
        try:
            fa = lighter.FundingApi(srv.runtime.api_client)
            fr = await fa.funding_rates()
            plain = fr.to_dict() if hasattr(fr, "to_dict") else (
                fr.model_dump() if hasattr(fr, "model_dump") else {}
            )
            rows = plain.get("funding_rates") or []
            lit = [r for r in rows if int(r.get("market_id") or -1) == int(market_id)]
            pick = next((r for r in lit if str(r.get("exchange") or "").lower() == "lighter"), None)
            if pick is None and lit:
                pick = lit[0]
            if pick is not None:
                funding_rate = _f(pick.get("rate"))
                if funding_rate is not None:
                    funding_pct = round(funding_rate * 100.0, 6)
        except Exception as e:  # noqa: BLE001
            out["funding_error"] = str(e)[:120]

        out.update(
            {
                "ok": True,
                "symbol": m.get("symbol") or "LIT",
                "last": last,
                "mark": mark,
                "index": index,
                "best_bid": best_bid,
                "best_ask": best_ask,
                "mid": round(mid, 6) if mid is not None else None,
                "spread": round(spread, 6) if spread is not None else None,
                "spread_bps": spread_bps,
                "daily_volume_usd": round(vol_quote, 2) if vol_quote is not None else None,
                "daily_volume_base": round(vol_base, 2) if vol_base is not None else None,
                "open_interest": round(oi, 2) if oi is not None else None,
                "daily_trades": m.get("daily_trades_count"),
                "daily_change_pct": _f(m.get("daily_price_change")),
                "funding_rate": funding_rate,
                "funding_pct": funding_pct,
                "book": {"bids": bids, "asks": asks, "levels": BOOK_LEVELS},
            }
        )
        return out
    except Exception as e:  # noqa: BLE001
        out["error"] = str(e)[:200]
        return out
    finally:
        if started_here:
            try:
                await srv.runtime.stop()
            except Exception:
                pass


def _maybe_kill_alert(
    *,
    kill_room: float,
    sess_pnl: float,
    kill: float,
    lot: float,
) -> dict:
    """Loud TG when kill-room < $15; cooldown to avoid spam."""
    state = _load(KILL_ALERT_STATE, {})
    now = int(time.time())
    alert: dict = {
        "threshold_usd": KILL_ALERT_USD,
        "kill_room_usd": round(kill_room, 2),
        "due": False,
        "sent": False,
        "critical": kill_room < KILL_ALERT_USD,
    }
    if kill_room >= KILL_ALERT_USD:
        if state.get("latched"):
            KILL_ALERT_STATE.write_text(
                json.dumps({"latched": False, "cleared_ts": now, "ts": now}, indent=2) + "\n",
                encoding="utf-8",
            )
        alert["status"] = "ok"
        return alert

    last = int(state.get("last_sent_ts") or 0)
    cooled = (now - last) >= KILL_ALERT_COOLDOWN_SEC
    alert["due"] = True
    alert["status"] = "critical"
    alert["cooldown_left_sec"] = max(0, KILL_ALERT_COOLDOWN_SEC - (now - last)) if last else 0
    if not cooled:
        return alert

    text = (
        f"⚠️ KILL-ROOM CRITICAL: осталось ${kill_room:.2f} "
        f"(порог алерта ${KILL_ALERT_USD:.0f})\n"
        f"session_pnl={sess_pnl:+.2f}$  kill={kill:.0f}$  lot=${lot:.0f}\n"
        f"Близко к stop-session — без новых входов, следи / закрой при необходимости."
    )
    try:
        from telegram_notify import send_message

        send_message(text, log_source="kill_alert")
        alert["sent"] = True
        KILL_ALERT_STATE.write_text(
            json.dumps(
                {
                    "latched": True,
                    "last_sent_ts": now,
                    "last_kill_room": round(kill_room, 2),
                    "ts": now,
                },
                indent=2,
            )
            + "\n",
            encoding="utf-8",
        )
    except Exception as e:  # noqa: BLE001
        alert["error"] = str(e)[:160]
    return alert


def _latest_report(market_id: int = 120) -> dict:
    con = connect()
    try:
        row = con.execute(
            """
            SELECT id, market_id, symbol, model, title, summary, created_at
            FROM indicator_reports
            WHERE market_id=? OR market_id IS NULL
            ORDER BY id DESC LIMIT 1
            """,
            (market_id,),
        ).fetchone()
        if not row:
            return {"id": None}
        return {
            "id": int(row["id"]),
            "title": row["title"],
            "summary": (row["summary"] or "")[:400],
            "model": row["model"],
            "created_at": row["created_at"],
        }
    finally:
        con.close()


def _position_block() -> tuple[dict, str | None]:
    """Live position via lighter server; fallback to trade state on error."""
    trade = _load(TRADE, {})
    try:
        from trading_status import build

        st = asyncio.run(build(120, DEFAULT_DSN))
        pos = st.get("position") or {}
        return pos, st.get("line")
    except Exception as e:
        side = trade.get("side")
        flat = side not in ("long", "short")
        return {
            "symbol": "LIT",
            "market_id": 120,
            "side": None if flat else side,
            "size_lit": float(trade.get("size") or 0),
            "volume_usd": None,
            "entry": trade.get("entry"),
            "unrealized_pnl": trade.get("upnl"),
            "flat": flat,
            "error": str(e)[:200],
        }, None


def _decision_hint(
    *,
    pos: dict,
    roc: dict,
    session_pnl: float,
    lot: float,
    control: dict,
    pending_count: int,
) -> dict:
    if control.get("ticks_stopped"):
        return {"action": "hold", "reason": "ticks_stopped", "attention": pending_count > 0}

    kill_lo = float((_load(TRADE, {}) or {}).get("session_kill") or KILL_LO)
    if session_pnl <= kill_lo or session_pnl >= KILL_HI:
        return {
            "action": "close_only",
            "reason": f"kill-switch sess={session_pnl:.2f}",
            "attention": True,
        }

    r1 = roc.get("1h") or {}
    r12 = roc.get("12h") or {}
    sig1 = r1.get("signal")
    sig12 = r12.get("signal")
    # bias: use last non-zero-ish signal on 12h, else sign of value
    bias = sig12
    if bias not in (-1, 1):
        v12 = r12.get("value")
        if v12 is not None:
            bias = 1 if v12 > 0 else (-1 if v12 < 0 else 0)
        else:
            bias = 0

    side = (pos.get("side") or "").lower() if not pos.get("flat") else None
    flat = bool(pos.get("flat") or side not in ("long", "short"))

    # Formal working-TF signal only (0 = no cross)
    if sig1 == 1:
        if flat and bias >= 0:
            return {"action": "open_long", "reason": "1h ROC +1, 12h not short", "attention": True}
        if side == "short":
            return {
                "action": "flip",
                "reason": "1h ROC +1 vs short (discretionary)",
                "attention": True,
            }
        return {"action": "hold", "reason": "already long", "attention": pending_count > 0}

    if sig1 == -1:
        if flat and bias <= 0:
            return {"action": "open_short", "reason": "1h ROC -1, 12h not long", "attention": True}
        if side == "long":
            return {
                "action": "flip",
                "reason": "1h ROC -1 vs long (discretionary)",
                "attention": True,
            }
        return {"action": "hold", "reason": "already short", "attention": pending_count > 0}

    return {
        "action": "hold",
        "reason": f"no 1h formal cross (sig={sig1}), side={side or 'flat'}",
        "attention": pending_count > 0,
    }


def build_brief() -> dict:
    now = int(time.time())
    inbox = _load(INBOX, {})
    session = _load(SESSION, {})
    control = _load(CONTROL, {})
    trade = _load(TRADE, {})

    owner = str(session.get("owner") or "roc").lower()
    if owner not in ("sma", "roc", "agent"):
        owner = "roc"
    # agent mode may store pnl under roc key historically
    block = session.get("roc") or session.get(owner) or {}
    if owner == "agent":
        block = session.get("roc") or session.get("agent") or block
    sess_pnl = float(block.get("session_pnl") if block.get("session_pnl") is not None else trade.get("session_pnl") or 0)
    lot = float(trade.get("lot") or block.get("lot") or session.get("base_lot") or 200)
    kill = float(trade.get("session_kill") or KILL_LO)
    kill_room = abs(kill - sess_pnl)
    sl_pct = (kill_room / lot) if lot else None

    pending = inbox.get("pending") if isinstance(inbox.get("pending"), list) else []
    pending_count = int(inbox.get("pending_count") or len(pending))

    roc = _roc(120)
    report = _latest_report(120)
    last_seen = trade.get("last_report_id")
    is_new = bool(report.get("id") and last_seen is not None and int(report["id"]) != int(last_seen))
    if report.get("id") and last_seen is None:
        is_new = True

    pos, line = _position_block()
    try:
        market = asyncio.run(_market_snapshot(120))
    except Exception as e:  # noqa: BLE001
        market = {"ok": False, "market_id": 120, "error": str(e)[:200]}

    kill_alert = _maybe_kill_alert(
        kill_room=kill_room, sess_pnl=sess_pnl, kill=kill, lot=lot
    )

    hint = _decision_hint(
        pos=pos,
        roc=roc,
        session_pnl=sess_pnl,
        lot=lot,
        control=control,
        pending_count=pending_count,
    )
    if is_new:
        hint["report_new"] = True
    if kill_alert.get("critical"):
        hint["kill_room_critical"] = True
        hint["no_new_opens"] = True
    # Idle-friendly: don't wake agent solely for a new report while holding
    hint["attention"] = bool(
        pending_count > 0
        or hint.get("action") != "hold"
        or kill_alert.get("critical")
    )

    last_px = market.get("last") or market.get("mark")
    line_core = line or (
        f"LIT {pos.get('side') or 'flat'} "
        f"uPnL={pos.get('unrealized_pnl')} / sess {sess_pnl:+.2f}"
    )
    if last_px is not None and " @" not in str(line_core):
        line_core = f"{line_core} @ {float(last_px):.4f}"

    brief = {
        "ok": True,
        "ts": now,
        "ts_iso": time.strftime("%m-%d %H:%M", time.gmtime(now)),
        "age_sec": 0,
        "line": line_core,
        "tg": {
            "pending_count": pending_count,
            "pending": [
                {"id": p.get("id"), "text": (p.get("text") or "")[:200]}
                for p in pending[:10]
            ],
        },
        "position": pos,
        "market": market,
        "session": {
            "session_pnl": sess_pnl,
            "kill": kill,
            "kill_room_usd": round(kill_room, 2),
            "kill_alert_usd": KILL_ALERT_USD,
            "lot": lot,
            "sl_pct": round(sl_pct, 4) if sl_pct is not None else None,
            "tp_pct": float(trade.get("tp_pct") or 0.5),
        },
        "kill_alert": kill_alert,
        "roc": roc,
        "bias": {
            "working_tf": "1h",
            "bias_tf": "12h",
            "indicator": IND,
            "sig_1h": (roc.get("1h") or {}).get("signal"),
            "sig_12h": (roc.get("12h") or {}).get("signal"),
        },
        "report": {
            **report,
            "last_report_id_state": last_seen,
            "is_new": is_new,
        },
        "control": {
            "paused": control.get("paused"),
            "roc_paused": control.get("roc_paused"),
            "sma_paused": control.get("sma_paused"),
            "ticks_stopped": control.get("ticks_stopped"),
        },
        "decision_hint": hint,
        "handoff": (
            "Cursor agent: read _agent_tick_brief.json first. "
            "If tg.pending_count=0 and decision_hint.action=hold and age_sec<180 → silent/one-liner. "
            "MCP trade only if action≠hold or TG pending. Ack via tg_queue.py --ack <id>."
        ),
    }
    return brief


def main() -> int:
    try:
        brief = build_brief()
        OUT.write_text(json.dumps(brief, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
        ck = None
        try:
            import agent_checkpoint as ac

            ck = ac.maybe_autosave()
        except Exception as e:
            ck = {"ok": False, "error": str(e)[:160]}

        # Trading decisions: deepseek_trader_agent.py (schtask), not Cursor.
        print(
            json.dumps(
                {
                    "ok": True,
                    "ts": brief.get("ts"),
                    "pending": (brief.get("tg") or {}).get("pending_count"),
                    "action": (brief.get("decision_hint") or {}).get("action"),
                    "line": brief.get("line"),
                    "attention": (brief.get("decision_hint") or {}).get("attention"),
                    "checkpoint": ck,
                    "trader": "deepseek_trader_agent",
                },
                ensure_ascii=False,
            )
        )
        return 0
    except Exception as e:
        err = {"ok": False, "error": str(e), "ts": int(time.time())}
        OUT.write_text(json.dumps(err, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
        print(json.dumps(err, ensure_ascii=False))
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
