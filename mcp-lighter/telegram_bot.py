"""Telegram bot: /status live + enqueue commands for the Cursor agent.

Queue: _tg_queue.json (via tg_queue.py)
Control (fast stop/start): _tg_control.json

Run:
  .\\.venv\\Scripts\\python.exe telegram_bot.py
"""

from __future__ import annotations

import asyncio
import sys
from pathlib import Path
from typing import Any, Optional

import httpx
from dotenv import load_dotenv

ROOT = Path(__file__).resolve().parent
load_dotenv(ROOT / ".env")

TG_API = "https://api.telegram.org"

sys.path.insert(0, str(ROOT))
from telegram_notify import build_status_text, send_message, telegram_config  # noqa: E402
from tg_queue import enqueue, load_control  # noqa: E402

HELP = """LIT agent bridge

Live:
/status — positions now
/help — this text

Queue → agent (next tick ~2m):
стоп / stop — pause all
старт / start / продолжить — resume
стоп sma | старт sma
стоп roc | старт roc
закрыть / close — close LIT
Any other text is queued for the agent.
"""


def _allowed_chat(chat_id: Any) -> bool:
    _, expected = telegram_config()
    return str(chat_id) == str(expected)


def _help_text() -> str:
    ctrl = load_control()
    return (
        HELP
        + f"\nControl: paused={ctrl.get('paused')} "
        + f"sma={ctrl.get('sma_paused')} roc={ctrl.get('roc_paused')}"
    )


async def handle_update(update: dict[str, Any]) -> None:
    msg = update.get("message") or update.get("edited_message")
    if not msg:
        return
    chat = msg.get("chat") or {}
    if not _allowed_chat(chat.get("id")):
        return
    text = (msg.get("text") or "").strip()
    if not text:
        return

    low = text.lower().strip()
    # Telegram Start button / help — do not treat as resume
    if low in ("/start", "/help", "help", "помощь"):
        send_message(_help_text())
        return

    if low in ("/status", "status", "статус"):
        try:
            send_message(await build_status_text())
        except Exception as exc:  # noqa: BLE001
            send_message(f"status error: {exc}")
        return

    item = enqueue(text)
    extra = item.get("fast") or ""
    send_message(
        f"queued #{item['id']}: {item['norm']}"
        + (f"\n{extra}" if extra else "")
        + "\nAgent picks up on next tick (~2m)."
    )


async def poll_loop() -> None:
    token, _ = telegram_config()
    offset: Optional[int] = None
    print("telegram_bot: queue bridge polling… (Ctrl+C to stop)", flush=True)
    async with httpx.AsyncClient(timeout=60) as client:
        while True:
            params: dict[str, Any] = {"timeout": 50}
            if offset is not None:
                params["offset"] = offset
            try:
                r = await client.get(f"{TG_API}/bot{token}/getUpdates", params=params)
                r.raise_for_status()
                data = r.json()
            except Exception as exc:  # noqa: BLE001
                print(f"poll error: {exc}", flush=True)
                await asyncio.sleep(3)
                continue

            for upd in data.get("result") or []:
                offset = int(upd["update_id"]) + 1
                try:
                    await handle_update(upd)
                except Exception as exc:  # noqa: BLE001
                    print(f"handle error: {exc}", flush=True)


def main() -> int:
    telegram_config()
    try:
        asyncio.run(poll_loop())
    except KeyboardInterrupt:
        print("stopped", flush=True)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
