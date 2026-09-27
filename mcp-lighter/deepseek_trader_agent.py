#!/usr/bin/env python3
"""Autonomous DeepSeek Pro trader tick (no Cursor loop).

Every ~2m (schtask): load latest agent_prompts row + brief + TG pending →
DeepSeek Pro with Lighter tools → execute → telegram replies → ack TG.

  python deepseek_trader_agent.py
  python deepseek_trader_agent.py --dry-run
"""
from __future__ import annotations

import argparse
import asyncio
import json
import time
import urllib.error
import urllib.request
from pathlib import Path
from typing import Any

from db import connect
from agent_prompt import ensure_table, latest_prompt, DEFAULT_PROMPT, add_prompt
from tg_queue import pending_items, ack as tg_ack
from telegram_notify import send_message
from tg_chat import recent_for_prompt

ROOT = Path(__file__).resolve().parent
BRIEF = ROOT / "_agent_tick_brief.json"
TRADE = ROOT / "_agent_trade_state.json"
SESSION = ROOT / "_lit_session_state.json"
OUT = ROOT / "_deepseek_trader_last.json"
REPORT_SLOTS = ROOT / "_tg_pos_report_slots.json"
ENV_PATH = ROOT / ".env"

TOOLS: list[dict[str, Any]] = [
    {
        "type": "function",
        "function": {
            "name": "get_positions",
            "description": "List open LIT/perp positions",
            "parameters": {
                "type": "object",
                "properties": {"active_only": {"type": "boolean", "default": True}},
            },
        },
    },
    {
        "type": "function",
        "function": {
            "name": "get_account",
            "description": "Account summary",
            "parameters": {"type": "object", "properties": {}},
        },
    },
    {
        "type": "function",
        "function": {
            "name": "get_active_orders",
            "description": "Active orders for market",
            "parameters": {
                "type": "object",
                "properties": {"market_id": {"type": "integer", "default": 120}},
            },
        },
    },
    {
        "type": "function",
        "function": {
            "name": "open_long",
            "description": "Open LIT long; prefer quote_usd",
            "parameters": {
                "type": "object",
                "properties": {
                    "market_id": {"type": "integer", "default": 120},
                    "quote_usd": {"type": "number"},
                    "size": {"type": "number"},
                    "dry_run": {"type": "boolean", "default": False},
                },
            },
        },
    },
    {
        "type": "function",
        "function": {
            "name": "open_short",
            "description": "Open LIT short; prefer quote_usd",
            "parameters": {
                "type": "object",
                "properties": {
                    "market_id": {"type": "integer", "default": 120},
                    "quote_usd": {"type": "number"},
                    "size": {"type": "number"},
                    "dry_run": {"type": "boolean", "default": False},
                },
            },
        },
    },
    {
        "type": "function",
        "function": {
            "name": "close_position",
            "description": "Close LIT position (full or size)",
            "parameters": {
                "type": "object",
                "properties": {
                    "market_id": {"type": "integer", "default": 120},
                    "size": {"type": "number"},
                    "dry_run": {"type": "boolean", "default": False},
                },
            },
        },
    },
    {
        "type": "function",
        "function": {
            "name": "place_tp_sl",
            "description": "Place take-profit and stop-loss on open position",
            "parameters": {
                "type": "object",
                "properties": {
                    "market_id": {"type": "integer", "default": 120},
                    "take_profit": {"type": "number"},
                    "stop_loss": {"type": "number"},
                    "size": {"type": "number"},
                },
                "required": ["take_profit", "stop_loss"],
            },
        },
    },
    {
        "type": "function",
        "function": {
            "name": "telegram_reply",
            "description": "Send a Telegram message to the user",
            "parameters": {
                "type": "object",
                "properties": {"text": {"type": "string"}},
                "required": ["text"],
            },
        },
    },
    {
        "type": "function",
        "function": {
            "name": "record_trade_pnl",
            "description": "After closing a trade, record realized pnl for wrong-entry lot policy",
            "parameters": {
                "type": "object",
                "properties": {"pnl": {"type": "number"}},
                "required": ["pnl"],
            },
        },
    },
    {
        "type": "function",
        "function": {
            "name": "mysql_list_tables",
            "description": "List MySQL tables (indicators, reports, tg_messages, …)",
            "parameters": {"type": "object", "properties": {}},
        },
    },
    {
        "type": "function",
        "function": {
            "name": "mysql_describe_table",
            "description": "Describe columns of one MySQL table",
            "parameters": {
                "type": "object",
                "properties": {"table": {"type": "string"}},
                "required": ["table"],
            },
        },
    },
    {
        "type": "function",
        "function": {
            "name": "mysql_query",
            "description": "Read-only SQL (SELECT/SHOW/DESCRIBE) on botassistant MySQL for market analysis",
            "parameters": {
                "type": "object",
                "properties": {
                    "sql": {"type": "string"},
                    "limit": {"type": "integer", "default": 100},
                },
                "required": ["sql"],
            },
        },
    },
]


def load_env(path: Path) -> dict[str, str]:
    out: dict[str, str] = {}
    if not path.is_file():
        return out
    for line in path.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        k, v = line.split("=", 1)
        out[k.strip()] = v.strip()
    return out


def _load_json(path: Path) -> dict[str, Any]:
    if not path.is_file():
        return {}
    try:
        d = json.loads(path.read_text(encoding="utf-8-sig"))
        return d if isinstance(d, dict) else {}
    except (OSError, json.JSONDecodeError):
        return {}


def get_or_seed_prompt() -> dict[str, Any]:
    con = connect()
    try:
        ensure_table(con)
        row = latest_prompt(con)
        if not row:
            pid = add_prompt(con, body=DEFAULT_PROMPT, name="default-trader", role="system")
            row = latest_prompt(con)
            assert row
            row["_seeded_id"] = pid
        return row
    finally:
        con.close()


def _position_report_gate() -> dict[str, Any]:
    """Due once per hour at :10 and :40 (2-minute window so 2m cron can hit it)."""
    from datetime import datetime

    now = datetime.now().astimezone()
    minute = now.minute
    slot_min: int | None = None
    if 10 <= minute < 12:
        slot_min = 10
    elif 40 <= minute < 42:
        slot_min = 40
    slot_id = now.strftime(f"%Y-%m-%dT%H:{slot_min:02d}") if slot_min is not None else None
    sent = _load_json(REPORT_SLOTS)
    already = bool(slot_id and sent.get("last_slot_id") == slot_id)
    due = bool(slot_id and not already)
    return {
        "due": due,
        "slot_id": slot_id,
        "slot_minute": slot_min,
        "already_sent": already,
        "local_time": now.strftime("%Y-%m-%d %H:%M:%S %z"),
    }


def mark_position_report_sent(slot_id: str | None) -> None:
    if not slot_id:
        return
    REPORT_SLOTS.write_text(
        json.dumps({"last_slot_id": slot_id, "ts": int(time.time())}, indent=2) + "\n",
        encoding="utf-8",
    )


def build_user_payload(pending: list[dict]) -> dict[str, Any]:
    brief = _load_json(BRIEF)
    trade = _load_json(TRADE)
    session = _load_json(SESSION)
    roc = session.get("roc") if isinstance(session.get("roc"), dict) else {}
    wrong = int(roc.get("consecutive_wrong_entries") or trade.get("consecutive_wrong_entries") or 0)
    base = float(session.get("base_lot") or 200)
    mx = float(session.get("max_lot_after_drawdown") or 400)
    lot = mx if wrong >= 3 else base
    from datetime import datetime

    now = datetime.now().astimezone()
    pos_rep = _position_report_gate()
    ask = "Decide actions via tools. Hold if unclear."
    if pending:
        ask = (
            f"PRIORITY: pending_tg has {len(pending)} message(s) — "
            "IMMEDIATELY telegram_reply to the user (same turn), then trade tools if needed. "
        ) + ask
    if pos_rep.get("due"):
        ask += (
            " REQUIRED: position_report.due=true — send one telegram_reply position report "
            f"for slot {pos_rep.get('slot_id')} (do not skip)."
        )
    return {
        "fresh_context": True,
        "clock": {
            "local_time": pos_rep.get("local_time"),
            "minute": now.minute,
            "hour": now.hour,
        },
        "position_report": pos_rep,
        "tick_brief": {
            "line": brief.get("line"),
            "position": brief.get("position"),
            "session": brief.get("session"),
            "roc": brief.get("roc"),
            "bias": brief.get("bias"),
            "report": {
                "id": (brief.get("report") or {}).get("id"),
                "title": (brief.get("report") or {}).get("title"),
                "summary": ((brief.get("report") or {}).get("summary") or "")[:900],
                "model": (brief.get("report") or {}).get("model"),
                "is_new": (brief.get("report") or {}).get("is_new"),
                "created_at": (brief.get("report") or {}).get("created_at"),
                "source": "DeepSeek Flash → MySQL indicator_reports → tick_brief.report",
            },
            "control": brief.get("control"),
        },
        "lot_policy": {
            "base_lot": base,
            "max_lot": mx,
            "consecutive_wrong_entries": wrong,
            "lot_usd_effective": lot,
            "wrong_entries_for_bump": 3,
        },
        "trade_state": {
            "side": trade.get("side"),
            "entry": trade.get("entry"),
            "session_pnl": trade.get("session_pnl"),
            "session_kill": trade.get("session_kill"),
            "sl_usd": trade.get("sl_usd"),
            "last_trade_pnl": trade.get("last_trade_pnl"),
        },
        "pending_tg": [
            {"id": p.get("id"), "text": p.get("text"), "ts": p.get("ts")} for p in pending[:10]
        ],
        "tg_history": recent_for_prompt(50),
        "ask": ask,
    }


def chat_completion(
    env: dict[str, str],
    messages: list[dict[str, Any]],
    *,
    tools: list[dict] | None = None,
) -> dict[str, Any]:
    key = (env.get("DEEPSEEK_API_KEY") or "").strip()
    base = (env.get("DEEPSEEK_BASE_URL") or "https://api.deepseek.com").rstrip("/")
    model = (env.get("DEEPSEEK_MODEL_PRO") or "deepseek-v4-pro").strip()
    if not key:
        raise RuntimeError("DEEPSEEK_API_KEY missing")
    body: dict[str, Any] = {
        "model": model,
        "messages": messages,
        "max_tokens": 2000,
        "thinking": {"type": "disabled"},
    }
    if tools:
        body["tools"] = tools
        body["tool_choice"] = "auto"
    req = urllib.request.Request(
        base + "/chat/completions",
        data=json.dumps(body).encode("utf-8"),
        headers={
            "Authorization": f"Bearer {key}",
            "Content-Type": "application/json",
            "Accept": "application/json",
        },
        method="POST",
    )
    with urllib.request.urlopen(req, timeout=180) as resp:
        return json.loads(resp.read().decode("utf-8"))


async def exec_tool(name: str, args: dict[str, Any], *, dry_run: bool) -> str:
    import server as srv

    if name == "telegram_reply":
        text = str(args.get("text") or "")[:4000]
        if not text:
            return json.dumps({"ok": False, "error": "empty text"})
        if dry_run:
            return json.dumps({"ok": True, "dry_run": True, "text": text[:200]})
        send_message(text, log_source="trader")
        return json.dumps({"ok": True, "sent": True})

    if name == "mysql_list_tables":
        from mysql_mcp_server import list_tables

        return list_tables()
    if name == "mysql_describe_table":
        from mysql_mcp_server import describe_table

        return describe_table(str(args.get("table") or ""))
    if name == "mysql_query":
        from mysql_mcp_server import query as mysql_query

        lim = args.get("limit")
        return mysql_query(str(args.get("sql") or ""), limit=None if lim is None else int(lim))

    if name == "record_trade_pnl":
        import deepseek_loop as dsl

        pnl = float(args.get("pnl") or 0)
        if dry_run:
            return json.dumps({"ok": True, "dry_run": True, "pnl": pnl})
        return json.dumps(dsl.record_entry_result(pnl=pnl), ensure_ascii=False)

    # Ensure runtime
    if srv.runtime.api_client is None:
        await srv.runtime.start()

    mid = int(args.get("market_id") or 120)
    force_dry = bool(dry_run or args.get("dry_run"))

    if name == "get_positions":
        return await srv.get_positions(active_only=bool(args.get("active_only", True)))
    if name == "get_account":
        return await srv.get_account(active_only=True)
    if name == "get_active_orders":
        return await srv.get_active_orders(market=None, market_id=mid)
    if name == "open_long":
        return await srv.open_long(
            market=None,
            market_id=mid,
            size=args.get("size"),
            quote_usd=args.get("quote_usd"),
            dry_run=force_dry,
        )
    if name == "open_short":
        return await srv.open_short(
            market=None,
            market_id=mid,
            size=args.get("size"),
            quote_usd=args.get("quote_usd"),
            dry_run=force_dry,
        )
    if name == "close_position":
        return await srv.close_position(
            market=None,
            market_id=mid,
            size=args.get("size"),
            dry_run=force_dry,
        )
    if name == "place_tp_sl":
        return await srv.place_tp_sl(
            take_profit=float(args["take_profit"]),
            stop_loss=float(args["stop_loss"]),
            market=None,
            market_id=mid,
            size=args.get("size"),
            dry_run=force_dry,
        )
    return json.dumps({"ok": False, "error": f"unknown tool {name}"})


async def run_agent(*, dry_run: bool = False, max_rounds: int = 4) -> dict[str, Any]:
    env = load_env(ENV_PATH)
    prompt_row = get_or_seed_prompt()
    pending = pending_items()
    user_payload = build_user_payload(pending)
    report_due = bool((user_payload.get("position_report") or {}).get("due"))
    report_slot = (user_payload.get("position_report") or {}).get("slot_id")

    messages: list[dict[str, Any]] = [
        {"role": "system", "content": prompt_row["body"]},
        {
            "role": "user",
            "content": "NEW CONTEXT.\n" + json.dumps(user_payload, ensure_ascii=False),
        },
    ]

    tool_log: list[dict[str, Any]] = []
    final_text = ""
    usage_acc: dict[str, int] = {}
    tg_sent = False

    import server as srv

    try:
        await srv.runtime.start()
        for _round in range(max_rounds):
            raw = await asyncio.to_thread(chat_completion, env, messages, tools=TOOLS)
            u = raw.get("usage") or {}
            for k in ("prompt_tokens", "completion_tokens", "total_tokens"):
                usage_acc[k] = usage_acc.get(k, 0) + int(u.get(k) or 0)

            msg = (raw.get("choices") or [{}])[0].get("message") or {}
            tool_calls = msg.get("tool_calls") or []
            content = (msg.get("content") or "").strip()
            if content:
                final_text = content

            messages.append(
                {
                    "role": "assistant",
                    "content": content or None,
                    "tool_calls": tool_calls or None,
                }
            )
            # Clean None fields for API
            messages[-1] = {k: v for k, v in messages[-1].items() if v is not None}

            if not tool_calls:
                break

            for tc in tool_calls:
                fn = (tc.get("function") or {})
                name = str(fn.get("name") or "")
                try:
                    args = json.loads(fn.get("arguments") or "{}")
                except json.JSONDecodeError:
                    args = {}
                if not isinstance(args, dict):
                    args = {}
                try:
                    result = await exec_tool(name, args, dry_run=dry_run)
                except Exception as e:  # noqa: BLE001
                    result = json.dumps({"ok": False, "error": str(e)[:400]})
                if name == "telegram_reply":
                    try:
                        parsed = json.loads(result)
                        if parsed.get("ok") and not parsed.get("dry_run"):
                            tg_sent = True
                    except json.JSONDecodeError:
                        pass
                tool_log.append({"name": name, "args": args, "result_preview": result[:500]})
                messages.append(
                    {
                        "role": "tool",
                        "tool_call_id": tc.get("id") or name,
                        "content": result[:8000],
                    }
                )
    finally:
        try:
            await srv.runtime.stop()
        except Exception:
            pass

    if report_due and tg_sent and not dry_run:
        mark_position_report_sent(report_slot)

    # Ack all pending TG that we attempted to handle this tick
    acked: list[int] = []
    for p in pending:
        pid = int(p.get("id") or 0)
        if pid:
            tg_ack(pid, result="trader_agent_tick")
            acked.append(pid)

    out = {
        "ok": True,
        "ts": int(time.time()),
        "prompt_id": prompt_row.get("id"),
        "model": env.get("DEEPSEEK_MODEL_PRO") or "deepseek-v4-pro",
        "dry_run": dry_run,
        "pending_tg": len(pending),
        "acked": acked,
        "position_report": user_payload.get("position_report"),
        "tool_calls": tool_log,
        "final_text": final_text[:1000],
        "usage": usage_acc,
    }
    OUT.write_text(json.dumps(out, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    return out


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--dry-run", action="store_true")
    ns = ap.parse_args()
    try:
        result = asyncio.run(run_agent(dry_run=ns.dry_run))
        print(json.dumps({k: result[k] for k in result if k != "final_text"}, ensure_ascii=False))
        if result.get("final_text"):
            print("FINAL:", result["final_text"][:300])
        return 0
    except Exception as e:  # noqa: BLE001
        err = {"ok": False, "error": str(e)[:400], "ts": int(time.time())}
        OUT.write_text(json.dumps(err, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
        print(json.dumps(err, ensure_ascii=False))
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
