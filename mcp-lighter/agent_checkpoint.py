#!/usr/bin/env python3
"""Persist / restore agent critical state in MySQL (context compaction).

Usage:
  python agent_checkpoint.py save [--reason auto|manual|rotate] [--label "..."] [--notify]
  python agent_checkpoint.py rotate [--label "..."] [--source tg|cli|agent]
  python agent_checkpoint.py heavy-check [--force]
  python agent_checkpoint.py load [--id N | --latest] [--no-files] [--print-handoff]
  python agent_checkpoint.py list [--limit 20]
  python agent_checkpoint.py handoff [--id N | --latest]

Save packs: trade/session/control JSON + brief + report stub → table agent_checkpoints.
Load restores JSON files and prints a short handoff for a NEW Cursor chat.
Rotate / heavy-check can push Telegram bootstrap instructions.
"""
from __future__ import annotations

import argparse
import json
import sys
import time
from pathlib import Path
from typing import Any

from db import connect

ROOT = Path(__file__).resolve().parent
TRADE = ROOT / "_agent_trade_state.json"
SESSION = ROOT / "_lit_session_state.json"
CONTROL = ROOT / "_tg_control.json"
BRIEF = ROOT / "_agent_tick_brief.json"
HANDOFF_MD = ROOT / "_agent_handoff.md"
ROTATE_STATE = ROOT / "_agent_rotate_state.json"
KEEP_CHECKPOINTS = 80
# Wall-clock since last load/rotate → TG “heavy chat” nudge
HEAVY_AFTER_SEC = 90 * 60
HEAVY_COOLDOWN_SEC = 30 * 60
BOOTSTRAP_CMD = (
    "cd mcp-lighter\n"
    ".\\.venv\\Scripts\\python.exe agent_checkpoint.py load --latest --print-handoff"
)


def _load(path: Path) -> Any:
    if not path.is_file():
        return None
    try:
        return json.loads(path.read_text(encoding="utf-8-sig"))
    except (OSError, json.JSONDecodeError):
        return None


def _dump(path: Path, data: Any) -> None:
    path.write_text(json.dumps(data, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")


def _ensure_schema(con) -> None:
    # connect() already runs SCHEMA_SQL; keep explicit for older DBs mid-session
    con.executescript(
        """
        CREATE TABLE IF NOT EXISTS agent_checkpoints (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            label VARCHAR(128) NULL,
            reason VARCHAR(64) NOT NULL DEFAULT 'auto',
            conversation_id VARCHAR(64) NULL,
            market_id INT NULL DEFAULT 120,
            side VARCHAR(16) NULL,
            session_pnl DOUBLE NULL,
            line VARCHAR(255) NULL,
            payload_json MEDIUMTEXT NOT NULL,
            handoff_text MEDIUMTEXT NULL,
            created_at BIGINT NOT NULL,
            KEY idx_agent_ckpt_created (created_at),
            KEY idx_agent_ckpt_reason (reason, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        """
    )


def build_payload(*, reason: str, label: str | None, conversation_id: str | None) -> dict:
    trade = _load(TRADE) or {}
    session = _load(SESSION) or {}
    control = _load(CONTROL) or {}
    brief = _load(BRIEF) or {}
    report = (brief.get("report") or {}) if isinstance(brief, dict) else {}

    # Keep brief lean for DB (drop huge nested if any)
    brief_slim = None
    if isinstance(brief, dict) and brief.get("ok"):
        brief_slim = {
            "ts": brief.get("ts"),
            "line": brief.get("line"),
            "position": brief.get("position"),
            "session": brief.get("session"),
            "roc": brief.get("roc"),
            "bias": brief.get("bias"),
            "report": {
                "id": report.get("id"),
                "title": report.get("title"),
                "summary": (report.get("summary") or "")[:500],
                "is_new": report.get("is_new"),
            },
            "decision_hint": brief.get("decision_hint"),
            "control": brief.get("control"),
            "tg": {"pending_count": (brief.get("tg") or {}).get("pending_count", 0)},
        }

    return {
        "version": 1,
        "saved_at": int(time.time()),
        "reason": reason,
        "label": label,
        "conversation_id": conversation_id,
        "files": {
            "trade_state": trade,
            "session_state": session,
            "control": control,
        },
        "brief": brief_slim,
        "rules_reminder": {
            "instrument": "LIT",
            "market_id": 120,
            "working_tf": "1h",
            "bias_tf": "12h",
            "indicator": "ROC(10) zero-cross",
            "lot_base": 200,
            "lot_max": 400,
            "tp_pct": 0.5,
            "sl_mode": "equal_kill_room",
            "session_kill": -50,
            "session_take_profit_stop": 100,
            "flip_mode": "discretionary",
            "tick_file": "_agent_tick_brief.json",
        },
    }


def render_handoff(payload: dict, checkpoint_id: int | None = None) -> str:
    trade = (payload.get("files") or {}).get("trade_state") or {}
    brief = payload.get("brief") or {}
    pos = brief.get("position") or {}
    sess = brief.get("session") or trade
    hint = brief.get("decision_hint") or {}
    report = brief.get("report") or {}
    rules = payload.get("rules_reminder") or {}
    lines = [
        "# Agent handoff (restored from MySQL)",
        "",
        f"- checkpoint_id: `{checkpoint_id}`",
        f"- saved_at: `{payload.get('saved_at')}` reason=`{payload.get('reason')}`",
        f"- line: **{brief.get('line') or 'n/a'}**",
        f"- side: `{trade.get('side') or pos.get('side')}` entry=`{trade.get('entry') or pos.get('entry')}`",
        f"- session_pnl: `{sess.get('session_pnl') if isinstance(sess, dict) else trade.get('session_pnl')}` "
        f"kill_room=`{(brief.get('session') or {}).get('kill_room_usd')}` lot=`{(brief.get('session') or {}).get('lot') or trade.get('lot')}`",
        f"- decision_hint: `{hint.get('action')}` — {hint.get('reason')}",
        f"- last_report_id: `{trade.get('last_report_id') or report.get('id')}` "
        f"title: {(report.get('title') or '')[:80]}",
        f"- ROC: 1h sig=`{((brief.get('roc') or {}).get('1h') or {}).get('signal')}` "
        f"12h sig=`{((brief.get('roc') or {}).get('12h') or {}).get('signal')}`",
        "",
        "## Continue trading",
        f"- Read `mcp-lighter/_agent_tick_brief.json` each 2m (schtask refreshes it).",
        f"- Strategy: {rules.get('indicator')} on {rules.get('working_tf')}, bias {rules.get('bias_tf')}.",
        f"- Lot ${rules.get('lot_base')}…${rules.get('lot_max')}, TP {rules.get('tp_pct')}, SL=kill-room, kill {rules.get('session_kill')}/+{rules.get('session_take_profit_stop')}.",
        "- Idle: if brief action=hold and no TG → silent / one line. Do not re-fetch MCP.",
        "- Files restored: `_agent_trade_state.json`, `_lit_session_state.json`, `_tg_control.json`.",
        "",
        "Paste this into a **new** Cursor chat after context grew too large.",
    ]
    return "\n".join(lines) + "\n"


def prune_old(con, keep: int = KEEP_CHECKPOINTS) -> int:
    row = con.execute("SELECT COUNT(*) FROM agent_checkpoints").fetchone()
    n = int(row[0]) if row else 0
    if n <= keep:
        return 0
    drop = n - keep
    ids = [
        int(r[0])
        for r in con.execute(
            "SELECT id FROM agent_checkpoints ORDER BY id ASC LIMIT ?",
            (drop,),
        ).fetchall()
    ]
    if not ids:
        return 0
    placeholders = ",".join(["?"] * len(ids))
    con.execute(f"DELETE FROM agent_checkpoints WHERE id IN ({placeholders})", ids)
    con.commit()
    return len(ids)


LAST_AUTO = ROOT / "_agent_checkpoint_last.json"
AUTO_INTERVAL_SEC = 600


def _rotate_state() -> dict:
    st = _load(ROTATE_STATE) or {}
    st.setdefault("last_fresh_ts", int(time.time()))
    st.setdefault("last_heavy_notify_ts", 0)
    st.setdefault("last_checkpoint_id", None)
    st.setdefault("last_event", "init")
    return st


def mark_fresh(*, event: str, checkpoint_id: int | None = None) -> dict:
    """Reset heavy-chat timer (call on load / rotate save)."""
    st = _rotate_state()
    now = int(time.time())
    st["last_fresh_ts"] = now
    st["last_event"] = event
    if checkpoint_id is not None:
        st["last_checkpoint_id"] = checkpoint_id
    if event in ("load", "rotate"):
        # Allow an immediate heavy nudge again after the next long session
        st["last_heavy_notify_ts"] = 0
    _dump(ROTATE_STATE, st)
    return st


def bootstrap_tg_text(*, checkpoint_id: int | None, reason: str, line: str | None = None) -> str:
    """Short Telegram instructions for opening a fresh Cursor chat."""
    cid = checkpoint_id if checkpoint_id is not None else "?"
    bits = [
        f"ROTATE / heavy chat — checkpoint #{cid} saved ({reason}).",
        f"Line: {line}" if line else None,
        "",
        "1) Cursor → New Chat (this repo)",
        "2) Paste & run:",
        BOOTSTRAP_CMD,
        "",
        "Then agent continues /loop 2m from brief. IDE chat cannot be opened from TG.",
    ]
    return "\n".join(b for b in bits if b is not None)


def notify_telegram(text: str) -> dict:
    from telegram_notify import send_message

    return send_message(text)


def save_checkpoint(
    *,
    reason: str = "manual",
    label: str | None = None,
    conversation_id: str | None = None,
) -> dict:
    """Insert one checkpoint; returns {ok, id, ...}."""
    payload = build_payload(
        reason=reason,
        label=label,
        conversation_id=conversation_id,
    )
    handoff = render_handoff(payload, checkpoint_id=None)
    trade = payload["files"]["trade_state"] or {}
    brief = payload.get("brief") or {}
    now = int(time.time())
    con = connect()
    try:
        _ensure_schema(con)
        cur = con.execute(
            """
            INSERT INTO agent_checkpoints
                (label, reason, conversation_id, market_id, side, session_pnl, line,
                 payload_json, handoff_text, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            """,
            (
                label,
                reason,
                conversation_id,
                120,
                trade.get("side") or (brief.get("position") or {}).get("side"),
                (brief.get("session") or {}).get("session_pnl", trade.get("session_pnl")),
                (brief.get("line") or "")[:255],
                json.dumps(payload, ensure_ascii=False),
                handoff,
                now,
            ),
        )
        con.commit()
        cid = int(cur.lastrowid)
        handoff = render_handoff(payload, checkpoint_id=cid)
        con.execute(
            "UPDATE agent_checkpoints SET handoff_text=? WHERE id=?",
            (handoff, cid),
        )
        con.commit()
        pruned = prune_old(con)
        HANDOFF_MD.write_text(handoff, encoding="utf-8")
        out = {
            "ok": True,
            "id": cid,
            "reason": reason,
            "pruned": pruned,
            "handoff": str(HANDOFF_MD),
            "line": (brief.get("line") or "")[:255],
        }
        return out
    finally:
        con.close()


def save_rotate_and_notify(
    *,
    label: str | None = "tg-rotate",
    source: str = "manual",
) -> dict:
    """Save reason=rotate and push bootstrap instructions to Telegram."""
    out = save_checkpoint(reason="rotate", label=label or f"rotate-{source}")
    mark_fresh(event="rotate", checkpoint_id=out.get("id"))
    text = bootstrap_tg_text(
        checkpoint_id=out.get("id"),
        reason=f"rotate/{source}",
        line=out.get("line"),
    )
    try:
        notify_telegram(text)
        out["tg"] = "sent"
    except Exception as e:  # noqa: BLE001
        out["tg"] = f"error: {e!s}"[:160]
    out["bootstrap"] = BOOTSTRAP_CMD
    return out


def maybe_heavy_notify(
    *,
    force: bool = False,
    after_sec: int = HEAVY_AFTER_SEC,
    cooldown_sec: int = HEAVY_COOLDOWN_SEC,
) -> dict | None:
    """If Cursor session is old, save rotate checkpoint + TG nudge (throttled).

    Skip when ticks_stopped. Prefer open LIT position (trading chat in use).
    Does not reset the heavy timer — only load / explicit rotate does.
    """
    st = _rotate_state()
    now = int(time.time())
    age = now - int(st.get("last_fresh_ts") or now)
    brief = _load(BRIEF) or {}
    control = _load(CONTROL) or {}
    pos = brief.get("position") or {}
    flat = bool(pos.get("flat", True)) if pos else True

    if control.get("ticks_stopped"):
        return {"ok": True, "skipped": "ticks_stopped"}
    if not force:
        if age < after_sec:
            return None
        if now - int(st.get("last_heavy_notify_ts") or 0) < cooldown_sec:
            return {"ok": True, "skipped": "cooldown", "age_sec": age}
        # Idle flat market: still remind, but less often (2x cooldown)
        if flat and now - int(st.get("last_heavy_notify_ts") or 0) < cooldown_sec * 2:
            if st.get("last_heavy_notify_ts"):
                return {"ok": True, "skipped": "flat_cooldown", "age_sec": age}

    out = save_checkpoint(
        reason="rotate",
        label="heavy-chat" if not force else "heavy-force",
    )
    age_min = max(1, age // 60)
    text = bootstrap_tg_text(
        checkpoint_id=out.get("id"),
        reason=f"heavy ~{age_min}m since {st.get('last_event') or 'start'}",
        line=out.get("line") or brief.get("line"),
    )
    if force:
        text = "Agent flagged heavy context.\n" + text
    try:
        notify_telegram(text)
        tg = "sent"
    except Exception as e:  # noqa: BLE001
        tg = f"error: {e!s}"[:160]

    st = _rotate_state()
    st["last_heavy_notify_ts"] = now
    st["last_checkpoint_id"] = out.get("id")
    st["last_heavy_event"] = "heavy_notify"
    _dump(ROTATE_STATE, st)
    return {
        "ok": True,
        "heavy": True,
        "age_sec": age,
        "checkpoint": out,
        "tg": tg,
    }


def maybe_autosave(*, min_interval_sec: int = AUTO_INTERVAL_SEC) -> dict | None:
    """Throttle autosave from tick brief (default every 10m)."""
    last = _load(LAST_AUTO) or {}
    now = int(time.time())
    if now - int(last.get("ts") or 0) < min_interval_sec:
        return None
    out = save_checkpoint(reason="auto", label="tick-brief")
    _dump(LAST_AUTO, {"ts": now, "id": out.get("id")})
    return out


def cmd_save(args: argparse.Namespace) -> int:
    if getattr(args, "notify", False):
        out = save_rotate_and_notify(
            label=args.label or "cli-rotate",
            source="cli",
        )
        print(json.dumps(out, ensure_ascii=False))
        return 0 if out.get("ok") else 1
    out = save_checkpoint(
        reason=args.reason,
        label=args.label,
        conversation_id=args.conversation_id,
    )
    print(json.dumps(out, ensure_ascii=False))
    return 0


def cmd_rotate(args: argparse.Namespace) -> int:
    out = save_rotate_and_notify(
        label=args.label or "cli-rotate",
        source=args.source or "cli",
    )
    print(json.dumps(out, ensure_ascii=False))
    return 0 if out.get("ok") else 1


def cmd_heavy(args: argparse.Namespace) -> int:
    out = maybe_heavy_notify(force=bool(args.force)) or {
        "ok": True,
        "heavy": False,
        "skipped": "not_due",
    }
    print(json.dumps(out, ensure_ascii=False))
    return 0

def _fetch_checkpoint(con, checkpoint_id: int | None) -> dict | None:
    if checkpoint_id:
        row = con.execute(
            "SELECT id, payload_json, handoff_text, created_at, reason, label FROM agent_checkpoints WHERE id=?",
            (checkpoint_id,),
        ).fetchone()
    else:
        row = con.execute(
            """
            SELECT id, payload_json, handoff_text, created_at, reason, label
            FROM agent_checkpoints ORDER BY id DESC LIMIT 1
            """
        ).fetchone()
    if not row:
        return None
    return {
        "id": int(row["id"]),
        "payload": json.loads(row["payload_json"]),
        "handoff_text": row["handoff_text"],
        "created_at": row["created_at"],
        "reason": row["reason"],
        "label": row["label"],
    }


def cmd_load(args: argparse.Namespace) -> int:
    con = connect()
    try:
        _ensure_schema(con)
        ck = _fetch_checkpoint(con, args.id)
        if not ck:
            print(json.dumps({"ok": False, "error": "no checkpoint"}))
            return 1
        payload = ck["payload"]
        files = payload.get("files") or {}
        if not args.no_files:
            if files.get("trade_state") is not None:
                _dump(TRADE, files["trade_state"])
            if files.get("session_state") is not None:
                _dump(SESSION, files["session_state"])
            if files.get("control") is not None:
                _dump(CONTROL, files["control"])
            if payload.get("brief"):
                # refresh brief file as restored snapshot (agent can re-run agent_tick_brief next)
                brief = payload["brief"]
                brief["ok"] = True
                brief["restored_from_checkpoint"] = ck["id"]
                _dump(BRIEF, brief)
        handoff = ck.get("handoff_text") or render_handoff(payload, ck["id"])
        HANDOFF_MD.write_text(handoff, encoding="utf-8")
        mark_fresh(event="load", checkpoint_id=ck["id"])
        out = {
            "ok": True,
            "id": ck["id"],
            "reason": ck["reason"],
            "created_at": ck["created_at"],
            "files_written": not args.no_files,
            "handoff_path": str(HANDOFF_MD),
        }
        print(json.dumps(out, ensure_ascii=False))
        if args.print_handoff:
            print("---HANDOFF---")
            # Avoid Windows cp1251 crash on arrows in handoff
            try:
                print(handoff)
            except UnicodeEncodeError:
                sys.stdout.buffer.write((handoff + "\n").encode("utf-8", errors="replace"))
        return 0
    finally:
        con.close()


def cmd_list(args: argparse.Namespace) -> int:
    con = connect()
    try:
        _ensure_schema(con)
        rows = con.execute(
            """
            SELECT id, label, reason, side, session_pnl, line, created_at
            FROM agent_checkpoints
            ORDER BY id DESC LIMIT ?
            """,
            (max(1, min(100, args.limit)),),
        ).fetchall()
        items = [dict(r) for r in rows]
        print(json.dumps({"ok": True, "checkpoints": items}, ensure_ascii=False, indent=2))
        return 0
    finally:
        con.close()


def cmd_handoff(args: argparse.Namespace) -> int:
    con = connect()
    try:
        _ensure_schema(con)
        ck = _fetch_checkpoint(con, args.id)
        if not ck:
            print(json.dumps({"ok": False, "error": "no checkpoint"}))
            return 1
        text = ck.get("handoff_text") or render_handoff(ck["payload"], ck["id"])
        HANDOFF_MD.write_text(text, encoding="utf-8")
        print(json.dumps({"ok": True, "id": ck["id"], "path": str(HANDOFF_MD)}, ensure_ascii=False))
        print(text)
        return 0
    finally:
        con.close()


def main() -> int:
    ap = argparse.ArgumentParser(description="Agent critical-state checkpoints (MySQL)")
    sub = ap.add_subparsers(dest="cmd", required=True)

    p_save = sub.add_parser("save")
    p_save.add_argument("--reason", default="manual", choices=["auto", "manual", "rotate", "pre_trade"])
    p_save.add_argument("--label", default=None)
    p_save.add_argument("--conversation-id", default=None)
    p_save.add_argument(
        "--notify",
        action="store_true",
        help="save reason=rotate and send TG bootstrap (same as `rotate`)",
    )

    p_rot = sub.add_parser("rotate", help="save rotate checkpoint + Telegram bootstrap")
    p_rot.add_argument("--label", default=None)
    p_rot.add_argument("--source", default="cli")

    p_heavy = sub.add_parser(
        "heavy-check",
        help="if session old (>90m), save+TG heavy nudge (or --force)",
    )
    p_heavy.add_argument("--force", action="store_true")

    p_load = sub.add_parser("load")
    p_load.add_argument("--id", type=int, default=None)
    p_load.add_argument("--latest", action="store_true")
    p_load.add_argument("--no-files", action="store_true")
    p_load.add_argument("--print-handoff", action="store_true")

    p_list = sub.add_parser("list")
    p_list.add_argument("--limit", type=int, default=20)

    p_h = sub.add_parser("handoff")
    p_h.add_argument("--id", type=int, default=None)
    p_h.add_argument("--latest", action="store_true")

    ns = ap.parse_args()
    if ns.cmd == "save":
        return cmd_save(ns)
    if ns.cmd == "rotate":
        return cmd_rotate(ns)
    if ns.cmd == "heavy-check":
        return cmd_heavy(ns)
    if ns.cmd == "load":
        if not ns.id and not ns.latest:
            ns.latest = True
        return cmd_load(ns)
    if ns.cmd == "list":
        return cmd_list(ns)
    if ns.cmd == "handoff":
        if not ns.id and not ns.latest:
            ns.latest = True
        return cmd_handoff(ns)
    return 2


if __name__ == "__main__":
    raise SystemExit(main())
