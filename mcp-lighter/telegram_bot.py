"""Telegram bot: /status live + enqueue → DeepSeek trader immediately.

Queue: _tg_queue.json (via tg_queue.py)
Trader: deepseek_trader_agent.py (Pro, tools)

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
PY = ROOT / ".venv" / "Scripts" / "python.exe"
TRADER = ROOT / "deepseek_trader_agent.py"

sys.path.insert(0, str(ROOT))
from telegram_notify import build_status_text, send_message, telegram_config  # noqa: E402
from tg_queue import enqueue, load_control  # noqa: E402

HELP = """LIT agent bridge

Live:
/status — positions now
/help — this text
rotate | новый чат | checkpoint | обрезать — save checkpoint + TG bootstrap

DeepSeek Pro (сразу + каждые 2м):
стоп / stop — pause all
старт / start / продолжить — resume
стоп sma | старт sma
стоп roc | старт roc
закрыть / close — close LIT
Любой другой текст — сразу в DeepSeek (ответ в этом же чате).
"""

_trader_lock = asyncio.Lock()


ROTATE_CMDS = {
    "rotate",
    "/rotate",
    "новый чат",
    "новыйчат",
    "checkpoint",
    "/checkpoint",
    "обрезать",
    "обрезать контекст",
    "компакт",
}


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


def _handle_rotate() -> str:
    from agent_checkpoint import save_rotate_and_notify

    out = save_rotate_and_notify(label="tg-rotate", source="tg")
    if not out.get("ok"):
        return f"rotate failed: {out}"
    return (
        f"checkpoint #{out.get('id')} saved (tg={out.get('tg')}).\n"
        "Open New Chat in Cursor, then paste:\n"
        f"{out.get('bootstrap')}"
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

    try:
        from tg_chat import log_in

        log_in(
            text,
            chat_id=str(chat.get("id") or ""),
            tg_message_id=int(msg["message_id"]) if msg.get("message_id") is not None else None,
            source="user",
        )
    except Exception:  # noqa: BLE001
        pass

    low = text.lower().strip()
    # Telegram Start button / help — do not treat as resume
    if low in ("/start", "/help", "help", "помощь"):
        send_message(_help_text(), log_source="help")
        return

    if low in ("/status", "status", "статус"):
        try:
            send_message(await build_status_text(), log_source="status")
        except Exception as exc:  # noqa: BLE001
            send_message(f"status error: {exc}", log_source="status")
        return

    # Strip leading slash for rotate aliases
    norm = low[1:] if low.startswith("/") else low
    if low in ROTATE_CMDS or norm in ROTATE_CMDS:
        try:
            send_message(_handle_rotate(), log_source="rotate")
        except Exception as exc:  # noqa: BLE001
            send_message(f"rotate error: {exc}", log_source="rotate")
        return

    item = enqueue(text)
    extra = item.get("fast") or ""
    # Fast control ack only; DeepSeek answers the rest immediately via telegram_reply
    if extra:
        send_message(f"#{item['id']}: {extra}", log_source="control")

    async with _trader_lock:
        try:
            await _run_trader_now()
        except Exception as exc:  # noqa: BLE001
            send_message(f"DeepSeek tick error: {exc}")


async def _run_trader_now() -> None:
    """Run DeepSeek Pro trader once so pending_tg gets an immediate reply."""
    py = str(PY if PY.is_file() else sys.executable)
    proc = await asyncio.create_subprocess_exec(
        py,
        str(TRADER),
        cwd=str(ROOT),
        stdout=asyncio.subprocess.PIPE,
        stderr=asyncio.subprocess.STDOUT,
    )
    out_b, _ = await asyncio.wait_for(proc.communicate(), timeout=180)
    out = (out_b or b"").decode("utf-8", errors="replace")
    if proc.returncode not in (0, None):
        raise RuntimeError((out or f"exit {proc.returncode}")[:500])
    print(f"trader_now ok: {out[:200].replace(chr(10), ' ')}", flush=True)


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
