#!/usr/bin/env python3
"""One-shot Telegram queue poll for Windows Task Scheduler (every 2 min).

No infinite loop — run once per trigger.
Writes agent handoff: mcp-lighter/_tg_agent_inbox.json

Exit codes:
  0 — no pending
  1 — has pending (for agents / monitors)
  2 — error
"""
from __future__ import annotations

import json
import time
from pathlib import Path

import tg_queue

ROOT = Path(__file__).resolve().parent
INBOX = ROOT / "_tg_agent_inbox.json"
INBOX_LOG = ROOT / "_tg_agent_inbox.log"


def main() -> int:
    try:
        pending = tg_queue.pending_items()
        control = tg_queue.load_control()
        payload = {
            "ok": True,
            "checked_at": int(time.time()),
            "checked_at_iso": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
            "pending_count": len(pending),
            "pending": pending,
            "control": {
                "paused": control.get("paused"),
                "roc_paused": control.get("roc_paused"),
                "sma_paused": control.get("sma_paused"),
                "ticks_stopped": control.get("ticks_stopped"),
                "telegram_loops_stopped": control.get("telegram_loops_stopped"),
                "pause_note": control.get("pause_note"),
            },
            "handoff": "Cursor agent: read this file for TG queue; reply via tg_queue.py --ack <id>",
        }
        INBOX.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
        line = (
            f"{payload['checked_at_iso']} pending={len(pending)} "
            f"ids={[i.get('id') for i in pending]} "
            f"texts={[i.get('text') for i in pending]}\n"
        )
        with INBOX_LOG.open("a", encoding="utf-8") as f:
            f.write(line)
        print(json.dumps(payload, ensure_ascii=False))
        return 1 if pending else 0
    except Exception as e:
        err = {"ok": False, "error": str(e), "checked_at": int(time.time())}
        INBOX.write_text(json.dumps(err, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
        print(json.dumps(err, ensure_ascii=False))
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
