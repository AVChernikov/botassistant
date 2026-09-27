#!/usr/bin/env python3
"""Telegram chat history in MySQL `tg_messages`.

  python tg_chat.py ensure
  python tg_chat.py recent --limit 50
  python tg_chat.py add --dir in --text "hello"
"""
from __future__ import annotations

import argparse
import json
import time
from datetime import datetime, timezone
from typing import Any, Optional

from db import connect

TABLE_SQL = """
CREATE TABLE IF NOT EXISTS tg_messages (
    id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    chat_id VARCHAR(64) NULL,
    direction VARCHAR(8) NOT NULL,
    role VARCHAR(16) NOT NULL,
    text MEDIUMTEXT NOT NULL,
    tg_message_id BIGINT NULL,
    source VARCHAR(64) NULL,
    created_at BIGINT NOT NULL,
    KEY idx_tg_messages_created (created_at),
    KEY idx_tg_messages_chat (chat_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
"""

MAX_TEXT = 8000


def ensure_table(con=None) -> None:
    own = con is None
    if own:
        con = connect()
    try:
        con.executescript(TABLE_SQL)
        if own:
            con.commit()
    finally:
        if own:
            con.close()


def _role_for(direction: str) -> str:
    d = (direction or "").strip().lower()
    if d in ("in", "inbound", "user"):
        return "user"
    return "bot"


def _norm_dir(direction: str) -> str:
    d = (direction or "").strip().lower()
    if d in ("in", "inbound", "user"):
        return "in"
    return "out"


def add_message(
    text: str,
    *,
    direction: str,
    source: str = "telegram",
    chat_id: Optional[str] = None,
    tg_message_id: Optional[int] = None,
    created_at: Optional[int] = None,
    con=None,
) -> int:
    """Insert one chat line. Returns row id (0 on skip/failure)."""
    body = (text or "").strip()
    if not body:
        return 0
    body = body[:MAX_TEXT]
    direction_n = _norm_dir(direction)
    role = _role_for(direction_n)
    ts = int(created_at or time.time())
    own = con is None
    if own:
        con = connect()
    try:
        ensure_table(con)
        cur = con.execute(
            """
            INSERT INTO tg_messages
                (chat_id, direction, role, text, tg_message_id, source, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            """,
            (
                str(chat_id) if chat_id is not None else None,
                direction_n,
                role,
                body,
                int(tg_message_id) if tg_message_id is not None else None,
                (source or "")[:64] or None,
                ts,
            ),
        )
        con.commit()
        return int(cur.lastrowid or 0)
    except Exception:  # noqa: BLE001
        return 0
    finally:
        if own:
            con.close()


def log_in(
    text: str,
    *,
    chat_id: Optional[str] = None,
    tg_message_id: Optional[int] = None,
    source: str = "user",
) -> int:
    return add_message(
        text,
        direction="in",
        source=source,
        chat_id=chat_id,
        tg_message_id=tg_message_id,
    )


def log_out(
    text: str,
    *,
    chat_id: Optional[str] = None,
    tg_message_id: Optional[int] = None,
    source: str = "bot",
) -> int:
    return add_message(
        text,
        direction="out",
        source=source,
        chat_id=chat_id,
        tg_message_id=tg_message_id,
    )


def _fmt_local(ts: int) -> str:
    try:
        return datetime.fromtimestamp(int(ts)).astimezone().strftime("%Y-%m-%d %H:%M:%S %z")
    except (OSError, OverflowError, ValueError):
        return datetime.fromtimestamp(int(ts), tz=timezone.utc).strftime("%Y-%m-%d %H:%M:%S UTC")


def recent_messages(limit: int = 50, *, con=None) -> list[dict[str, Any]]:
    """Last N messages, oldest → newest (conversation order)."""
    n = max(1, min(int(limit or 50), 200))
    own = con is None
    if own:
        con = connect()
    try:
        ensure_table(con)
        rows = con.execute(
            """
            SELECT id, chat_id, direction, role, text, tg_message_id, source, created_at
            FROM tg_messages
            ORDER BY id DESC
            LIMIT ?
            """,
            (n,),
        ).fetchall()
        out: list[dict[str, Any]] = []
        for r in reversed(list(rows)):
            ts = int(r["created_at"] or 0)
            out.append(
                {
                    "id": int(r["id"]),
                    "ts": ts,
                    "time": _fmt_local(ts),
                    "dir": r["direction"],
                    "role": r["role"],
                    "text": r["text"],
                    "source": r["source"],
                }
            )
        return out
    finally:
        if own:
            con.close()


def recent_for_prompt(limit: int = 50) -> list[dict[str, Any]]:
    """Slim rows for DeepSeek user JSON (keep tokens modest)."""
    rows = recent_messages(limit)
    return [
        {
            "time": r["time"],
            "ts": r["ts"],
            "dir": r["dir"],
            "text": (r.get("text") or "")[:1500],
        }
        for r in rows
    ]


def main() -> int:
    ap = argparse.ArgumentParser(description="Telegram chat history (MySQL)")
    sub = ap.add_subparsers(dest="cmd", required=True)

    sub.add_parser("ensure", help="CREATE TABLE IF NOT EXISTS")

    p_r = sub.add_parser("recent", help="Print last N messages")
    p_r.add_argument("--limit", type=int, default=50)

    p_a = sub.add_parser("add", help="Insert a test message")
    p_a.add_argument("--dir", choices=("in", "out"), required=True)
    p_a.add_argument("--text", required=True)
    p_a.add_argument("--source", default="cli")

    ns = ap.parse_args()
    if ns.cmd == "ensure":
        ensure_table()
        print(json.dumps({"ok": True, "table": "tg_messages"}))
        return 0
    if ns.cmd == "recent":
        print(json.dumps(recent_messages(ns.limit), ensure_ascii=False, indent=2))
        return 0
    if ns.cmd == "add":
        mid = add_message(ns.text, direction=ns.dir, source=ns.source)
        print(json.dumps({"ok": True, "id": mid}))
        return 0 if mid else 1
    return 2


if __name__ == "__main__":
    raise SystemExit(main())
