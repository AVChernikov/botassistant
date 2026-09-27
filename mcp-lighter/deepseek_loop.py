#!/usr/bin/env python3
"""DeepSeek Pro — fresh-context decision every 2m agent tick.

Each call is a NEW conversation (no prior messages). Payload = slim brief +
resources + strategy (incl. lot bump after 3 wrong entries).

Usage:
  python deepseek_loop.py           # always call (default)
  python deepseek_loop.py --force
"""
from __future__ import annotations

import argparse
import json
import re
import time
import urllib.error
import urllib.request
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parent
BRIEF = ROOT / "_agent_tick_brief.json"
TRADE = ROOT / "_agent_trade_state.json"
SESSION = ROOT / "_lit_session_state.json"
OUT = ROOT / "_deepseek_loop_hint.json"
LAST = ROOT / "_deepseek_loop_last.json"
ENV_PATH = ROOT / ".env"

BASE_LOT = 200.0
MAX_LOT = 400.0
WRONG_ENTRIES_FOR_BUMP = 3

SYSTEM = """You are the LIT perpetual trading commander for Lighter (market_id=120, symbol LIT).
Each request is a BRAND-NEW context — you have NO memory of prior turns. Decide only from the JSON user message.

GOAL: maximize session profit (USD), respect kill-switch.

Reply STRICT JSON only (no markdown):
{
  "action": "hold|open_long|open_short|flip|close_only",
  "lot_usd": 200,
  "reason": "short why",
  "confidence": 0.0,
  "notes": "optional"
}

RESOURCES available to the executor (Cursor agent) after your command:
- MCP lighter-perps: get_positions, get_account, open_long, open_short, close_position, place_tp_sl, get_active_orders
- MCP botassistant-mysql (read-only): list_tables, describe_table, query — snapshots/stats/reports/checkpoints
- Local tick brief + MySQL indicator_reports (Flash pipeline ~5m) + ROC live in brief
- Telegram queue for user overrides (стоп / закрыть / rotate)
You do NOT call tools yourself — output the position command only.

STRATEGY:
- Working method: ROC(10) zero-cross on 1h; bias filter 12h ROC (prefer not fight strong opposite bias).
- Confluence: MACD ok. Avoid RSI/BB for entries.
- Flips: discretionary (not mandatory on every cross) — weigh overall setup + report.
- Lot: base $200. After 3 consecutive wrong/losing market entries → use $400 (see lot_usd_effective in JSON). Cap $400.
- After open: TP 50% from entry; SL = session kill-room (sl_usd / lot).
- Kill-switch: session_pnl ≤ -50 → close_only / no new opens; ≥ +100 → stop new opens.
- Same side already open → hold (do not add). Opposite → flip.
- If unclear or low confidence → hold.
"""


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
        data = json.loads(path.read_text(encoding="utf-8-sig"))
        return data if isinstance(data, dict) else {}
    except (OSError, json.JSONDecodeError):
        return {}


def load_brief() -> dict[str, Any]:
    b = _load_json(BRIEF)
    return b if b else {"ok": False, "error": "no brief"}


def consecutive_wrong_entries(session: dict[str, Any], trade: dict[str, Any]) -> int:
    roc = session.get("roc") if isinstance(session.get("roc"), dict) else {}
    n = roc.get("consecutive_wrong_entries")
    if n is None:
        n = trade.get("consecutive_wrong_entries")
    try:
        return max(0, int(n or 0))
    except (TypeError, ValueError):
        return 0


def effective_lot(wrong: int, session: dict[str, Any]) -> float:
    base = float(session.get("base_lot") or BASE_LOT)
    mx = float(session.get("max_lot_after_drawdown") or MAX_LOT)
    if wrong >= WRONG_ENTRIES_FOR_BUMP:
        return mx
    return base


def build_user_payload(brief: dict[str, Any]) -> dict[str, Any]:
    trade = _load_json(TRADE)
    session = _load_json(SESSION)
    wrong = consecutive_wrong_entries(session, trade)
    lot = effective_lot(wrong, session)
    return {
        "fresh_context": True,
        "tick_brief": {
            "line": brief.get("line"),
            "position": brief.get("position"),
            "session": brief.get("session"),
            "roc": brief.get("roc"),
            "bias": brief.get("bias"),
            "report": {
                "id": (brief.get("report") or {}).get("id"),
                "title": (brief.get("report") or {}).get("title"),
                "summary": ((brief.get("report") or {}).get("summary") or "")[:700],
                "is_new": (brief.get("report") or {}).get("is_new"),
            },
            "decision_hint_local": brief.get("decision_hint"),
            "control": brief.get("control"),
        },
        "lot_policy": {
            "base_lot": BASE_LOT,
            "max_lot": MAX_LOT,
            "wrong_entries_for_bump": WRONG_ENTRIES_FOR_BUMP,
            "consecutive_wrong_entries": wrong,
            "lot_usd_effective": lot,
        },
        "trade_state": {
            "side": trade.get("side"),
            "entry": trade.get("entry"),
            "lot": trade.get("lot"),
            "session_pnl": trade.get("session_pnl"),
            "session_kill": trade.get("session_kill"),
            "sl_usd": trade.get("sl_usd"),
            "last_trade_pnl": trade.get("last_trade_pnl"),
            "indicator": trade.get("indicator"),
            "working_tf": trade.get("working_tf"),
        },
        "resources": [
            "MCP lighter-perps (trade LIT)",
            "MCP botassistant-mysql read-only",
            "Flash pipeline reports in MySQL (~5m)",
            "Telegram user overrides",
        ],
        "ask": "Return one position command JSON now.",
    }


def call_pro(brief: dict[str, Any], env: dict[str, str]) -> dict[str, Any]:
    key = (env.get("DEEPSEEK_API_KEY") or "").strip()
    base = (env.get("DEEPSEEK_BASE_URL") or "https://api.deepseek.com").rstrip("/")
    model = (env.get("DEEPSEEK_MODEL_PRO") or env.get("DEEPSEEK_MODEL") or "deepseek-v4-pro").strip()
    if not key:
        return {"ok": False, "error": "DEEPSEEK_API_KEY missing"}

    payload = build_user_payload(brief)
    # Stateless: only system + this user message (new context every call)
    body = {
        "model": model,
        "messages": [
            {"role": "system", "content": SYSTEM},
            {
                "role": "user",
                "content": "NEW CONTEXT (no history).\n"
                + json.dumps(payload, ensure_ascii=False),
            },
        ],
        "max_tokens": 900,
        "thinking": {"type": "disabled"},
    }
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
    t0 = time.time()
    try:
        with urllib.request.urlopen(req, timeout=120) as resp:
            raw = json.loads(resp.read().decode("utf-8"))
    except urllib.error.HTTPError as e:
        err = e.read().decode("utf-8", errors="replace")[:500]
        return {"ok": False, "error": f"HTTP {e.code}: {err}", "model": model}
    except Exception as e:  # noqa: BLE001
        return {"ok": False, "error": str(e), "model": model}

    msg = (raw.get("choices") or [{}])[0].get("message") or {}
    content = (msg.get("content") or "").strip()
    parsed: Any = None
    try:
        parsed = json.loads(content)
    except json.JSONDecodeError:
        m = re.search(r"\{.*\}", content, re.S)
        if m:
            try:
                parsed = json.loads(m.group(0))
            except json.JSONDecodeError:
                parsed = None
    if not isinstance(parsed, dict):
        parsed = {"action": "hold", "reason": "unparsed", "raw": content[:400]}

    lot_eff = float((payload.get("lot_policy") or {}).get("lot_usd_effective") or BASE_LOT)
    try:
        lot_cmd = float(parsed.get("lot_usd") or lot_eff)
    except (TypeError, ValueError):
        lot_cmd = lot_eff
    lot_cmd = max(BASE_LOT, min(MAX_LOT, lot_cmd))

    action = str(parsed.get("action") or "hold").strip().lower()
    if action not in {"hold", "open_long", "open_short", "flip", "close_only"}:
        action = "hold"

    return {
        "ok": True,
        "model": raw.get("model") or model,
        "usage": raw.get("usage"),
        "duration_ms": int((time.time() - t0) * 1000),
        "fresh_context": True,
        "lot_policy": payload.get("lot_policy"),
        "hint": {
            "action": action,
            "lot_usd": lot_cmd,
            "reason": str(parsed.get("reason") or "")[:400],
            "confidence": parsed.get("confidence"),
            "notes": str(parsed.get("notes") or "")[:400],
        },
        "ts": int(time.time()),
    }


def run_tick(*, force: bool = True) -> dict[str, Any]:
    """Call Pro with fresh context; write OUT + merge into brief."""
    brief = load_brief()
    if brief.get("error") and not brief.get("line"):
        return {"ok": False, "error": brief.get("error")}
    env = load_env(ENV_PATH)
    result = call_pro(brief, env)
    result["call_reason"] = "every_2m" if force else "manual"
    OUT.write_text(json.dumps(result, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    if result.get("ok"):
        LAST.write_text(
            json.dumps(
                {
                    "ts": result["ts"],
                    "action": (result.get("hint") or {}).get("action"),
                    "lot_usd": (result.get("hint") or {}).get("lot_usd"),
                },
                indent=2,
            )
            + "\n",
            encoding="utf-8",
        )
        # merge into brief for Cursor agent
        brief["pro_hint"] = result.get("hint")
        brief["pro_model"] = result.get("model")
        brief["pro_fresh_context"] = True
        brief["lot_policy"] = result.get("lot_policy")
        BRIEF.write_text(json.dumps(brief, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    return result


def record_entry_result(*, pnl: float) -> dict[str, Any]:
    """Update consecutive_wrong_entries after a closed trade (call from agent)."""
    session = _load_json(SESSION)
    trade = _load_json(TRADE)
    roc = session.setdefault("roc", {})
    if not isinstance(roc, dict):
        roc = {}
        session["roc"] = roc
    n = consecutive_wrong_entries(session, trade)
    if pnl < 0:
        n += 1
    else:
        n = 0
    roc["consecutive_wrong_entries"] = n
    roc["lot"] = effective_lot(n, session)
    session["roc"] = roc
    trade["consecutive_wrong_entries"] = n
    trade["lot"] = roc["lot"]
    SESSION.write_text(json.dumps(session, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    TRADE.write_text(json.dumps(trade, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    return {"ok": True, "consecutive_wrong_entries": n, "lot": roc["lot"]}


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--force", action="store_true", help="ignored; always fresh call")
    ap.add_argument(
        "--record-pnl",
        type=float,
        default=None,
        help="record closed-trade PnL for wrong-entry counter",
    )
    args = ap.parse_args()
    if args.record_pnl is not None:
        print(json.dumps(record_entry_result(pnl=args.record_pnl), ensure_ascii=False))
        return 0
    result = run_tick(force=True)
    print(json.dumps(result, ensure_ascii=False))
    return 0 if result.get("ok") else 1


if __name__ == "__main__":
    raise SystemExit(main())
