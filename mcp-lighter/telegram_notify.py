"""Send Telegram alerts about Lighter positions / session state.

CLI:
  python telegram_notify.py "custom text"
  python telegram_notify.py --status
  python telegram_notify.py --status --dry-run
"""

from __future__ import annotations

import argparse
import asyncio
import json
import os
import sys
from pathlib import Path
from typing import Any, Optional

import httpx
from dotenv import load_dotenv

ROOT = Path(__file__).resolve().parent
load_dotenv(ROOT / ".env")

TG_API = "https://api.telegram.org"


def _env(name: str, default: str = "") -> str:
    return (os.getenv(name) or default).strip()


def telegram_config() -> tuple[str, str]:
    token = _env("TELEGRAM_BOT_TOKEN")
    chat_id = _env("TELEGRAM_CHAT_ID")
    if not token or not chat_id:
        raise SystemExit(
            "Set TELEGRAM_BOT_TOKEN and TELEGRAM_CHAT_ID in mcp-lighter/.env"
        )
    return token, chat_id


def send_message(text: str, *, parse_mode: Optional[str] = None) -> dict[str, Any]:
    token, chat_id = telegram_config()
    payload: dict[str, Any] = {
        "chat_id": chat_id,
        "text": text[:4000],
        "disable_web_page_preview": True,
    }
    if parse_mode:
        payload["parse_mode"] = parse_mode
    last_exc: Optional[BaseException] = None
    for attempt in range(3):
        try:
            with httpx.Client(timeout=30) as client:
                r = client.post(f"{TG_API}/bot{token}/sendMessage", json=payload)
                r.raise_for_status()
                return r.json()
        except Exception as exc:  # noqa: BLE001
            last_exc = exc
            __import__("time").sleep(1.5 * (attempt + 1))
    assert last_exc is not None
    raise last_exc


def _load_session_state() -> dict[str, Any]:
    path = ROOT / "_lit_session_state.json"
    if not path.exists():
        return {"owner": "none", "sma": {"session_pnl": 0}, "roc": {"session_pnl": 0}}
    return json.loads(path.read_text(encoding="utf-8"))


def _fmt_num(v: Any, digits: int = 4) -> str:
    try:
        return f"{float(v):.{digits}f}"
    except (TypeError, ValueError):
        return str(v)


def _side_ru(side: str) -> str:
    s = (side or "").lower()
    if s == "long":
        return "лонг (покупка)"
    if s == "short":
        return "шорт (продажа)"
    return side or "нет"


def _owner_ru(owner: str) -> str:
    o = (owner or "none").lower()
    if o == "sma":
        return "стратегия SMA (15 минут)"
    if o == "roc":
        return "стратегия ROC (1 час)"
    if o == "none":
        return "свободный рынок (никто не держит сделку)"
    return owner


def _cross_ru(cross: Optional[str], *, forming: bool = False) -> str:
    prefix = "на текущей (ещё не закрытой) свече: " if forming else "на закрытой свече: "
    if cross == "up":
        return prefix + "пересечение вверх → сигнал на лонг"
    if cross == "down":
        return prefix + "пересечение вниз → сигнал на шорт"
    if forming:
        return "на текущей свече пересечения пока нет"
    return "на закрытой свече пересечения нет"


def _lit_from_positions(positions: list[dict]) -> Optional[dict]:
    for p in positions:
        try:
            if int(p.get("market_id") or -1) == 120:
                return p
        except (TypeError, ValueError):
            pass
        if str(p.get("symbol") or "").upper() == "LIT":
            return p
    return None


def _format_position_block(p: Optional[dict]) -> list[str]:
    if not p:
        return ["Открытых позиций по LIT нет."]
    side = p.get("side") or ("long" if int(p.get("sign") or 0) > 0 else "short")
    return [
        "Открытая позиция LIT:",
        f"• Направление: {_side_ru(str(side))}",
        f"• Размер: {_fmt_num(p.get('position') or p.get('size'), 4)} LIT",
        f"• Цена входа: {_fmt_num(p.get('avg_entry_price') or p.get('entry_price'))} USD",
        f"• Нереализованная прибыль/убыток: {_fmt_num(p.get('unrealized_pnl'), 4)} USD",
    ]


async def build_status_text() -> str:
    import server  # local MCP server helpers

    await server.runtime.start()
    try:
        pos_raw = json.loads(await server.get_positions())
        acct_raw = json.loads(await server.get_account(active_only=True))
    finally:
        await server.runtime.stop()

    st = _load_session_state()
    positions = pos_raw.get("positions") or []
    lines = [
        "Статус счёта Lighter",
        "",
    ]
    lines.extend(_format_position_block(_lit_from_positions(positions)))

    other = [p for p in positions if int(p.get("market_id") or -1) != 120]
    if other:
        lines.append("")
        lines.append(f"Другие позиции: {len(other)}")
        for p in other:
            sym = p.get("symbol") or p.get("market_id") or "?"
            side = p.get("side") or "?"
            lines.append(
                f"• {sym}: {_side_ru(str(side))}, размер {_fmt_num(p.get('position'), 4)}, "
                f"вход {_fmt_num(p.get('avg_entry_price'))}, "
                f"нереализ. P/L {_fmt_num(p.get('unrealized_pnl'), 4)} USD"
            )

    owner = st.get("owner", "none")
    sma = st.get("sma") or {}
    roc = st.get("roc") or {}
    lines += [
        "",
        f"Кто сейчас ведёт сделку: {_owner_ru(str(owner))}",
        f"Прибыль сессии SMA (с учётом комиссий в учёте стратегии): {float(sma.get('session_pnl') or 0):.2f} USD, лот ${sma.get('lot', 50)}",
        f"Прибыль сессии ROC (с учётом комиссий в учёте стратегии): {float(roc.get('session_pnl') or 0):.2f} USD, лот ${roc.get('lot', 50)}",
    ]

    accounts = acct_raw.get("accounts") if isinstance(acct_raw, dict) else None
    if not accounts and isinstance(acct_raw, dict):
        accounts = [acct_raw]
    if accounts:
        acc0 = accounts[0] if isinstance(accounts, list) else accounts
        if isinstance(acc0, dict):
            coll = acc0.get("collateral") or acc0.get("available_balance") or acc0.get("total_asset_value")
            if coll is not None:
                lines.append(f"Обеспечение / доступно на счёте: {_fmt_num(coll, 2)} USD")

    return "\n".join(lines)


async def notify_status(*, dry_run: bool = False) -> str:
    text = await build_status_text()
    if dry_run:
        return text
    send_message(text)
    return text


def _sma(vals: list[float], p: int) -> list[Optional[float]]:
    out: list[Optional[float]] = [None] * len(vals)
    if len(vals) < p:
        return out
    s = sum(vals[:p])
    out[p - 1] = s / p
    for i in range(p, len(vals)):
        s += vals[i] - vals[i - p]
        out[i] = s / p
    return out


def _sma_cross(f: list[Optional[float]], s: list[Optional[float]], i: int) -> Optional[str]:
    if f[i] is None or s[i] is None or f[i - 1] is None or s[i - 1] is None:
        return None
    prev = f[i - 1] - s[i - 1]  # type: ignore[operator]
    curr = f[i] - s[i]  # type: ignore[operator]
    if prev <= 0 and curr > 0:
        return "up"
    if prev >= 0 and curr < 0:
        return "down"
    return None


def _roc(closes: list[float], period: int = 10) -> list[Optional[float]]:
    out: list[Optional[float]] = [None] * len(closes)
    for i in range(period, len(closes)):
        prev = closes[i - period]
        if prev == 0:
            continue
        out[i] = (closes[i] - prev) / prev * 100
    return out


def _zero_cross(r: list[Optional[float]], i: int) -> Optional[str]:
    if r[i] is None or r[i - 1] is None:
        return None
    if r[i - 1] <= 0 and r[i] > 0:  # type: ignore[operator]
        return "up"
    if r[i - 1] >= 0 and r[i] < 0:  # type: ignore[operator]
        return "down"
    return None


def _pos_notional_usd(p: dict) -> float:
    try:
        size = abs(float(p.get("position") or p.get("size") or 0))
        entry = float(p.get("avg_entry_price") or p.get("entry_price") or 0)
        return size * entry
    except (TypeError, ValueError):
        return 0.0


def _pnl_signed(v: Any) -> str:
    try:
        x = float(v)
    except (TypeError, ValueError):
        return str(v)
    sign = "+" if x > 0 else ""
    return f"{sign}{x:.2f}$"


def _load_control() -> dict[str, Any]:
    path = ROOT / "_tg_control.json"
    if not path.exists():
        return {"paused": False, "sma_paused": False, "roc_paused": False}
    return json.loads(path.read_text(encoding="utf-8"))


async def build_tick_text(kind: str) -> str:
    """kind: sma | roc — короткий тик: стратегия, позиция $, P/L."""
    import server

    kind = kind.lower().strip()
    if kind not in ("sma", "roc"):
        raise SystemExit("--tick must be sma or roc")

    st = _load_session_state()
    ctrl = _load_control()
    await server.runtime.start()
    try:
        pos = json.loads(await server.get_positions())
        lit = _lit_from_positions(pos.get("positions") or [])
    finally:
        await server.runtime.stop()

    owner = str(st.get("owner") or "none").lower()
    sess = float((st.get(kind) or {}).get("session_pnl") or 0)
    all_paused = bool(ctrl.get("paused"))
    kind_paused = bool(ctrl.get(f"{kind}_paused")) or all_paused

    if kind_paused:
        status = "пауза"
    elif owner == kind:
        status = "ведёт"
    elif owner in ("sma", "roc"):
        status = f"ждёт ({owner.upper()} ведёт)"
    else:
        status = "работает"

    # Format: ASSET $VOL ±pos / sess ±sess | INDICATOR  (+ short status)
    if kind == "sma":
        indicator = "SMA(10/30) 15m"
    else:
        tf = str((st.get("roc") or {}).get("timeframe") or "15m")
        indicator = f"ROC(10) {tf}"

    if lit:
        notional = _pos_notional_usd(lit)
        upnl = lit.get("unrealized_pnl")
        try:
            u = float(upnl)
            pos_pnl = f"+{u:.2f}" if u > 0 else f"{u:.2f}"
        except (TypeError, ValueError):
            pos_pnl = str(upnl)
        vol = f"${notional:.0f}" if abs(notional - round(notional)) < 0.5 else f"${notional:.2f}"
        side = str(lit.get("side") or "").lower()
        if side not in ("long", "short"):
            side = "long" if int(lit.get("sign") or 0) > 0 else "short"
        core = f"LIT {vol} {side} {pos_pnl} / sess {_pnl_signed(sess).replace('$', '')} | {indicator}"
    else:
        core = f"LIT flat / sess {_pnl_signed(sess).replace('$', '')} | {indicator}"

    return f"{core}\n({status})"

async def build_position_report_text() -> str:
    """Compact 30m cron line: asset, $size, side, pos PnL, session PnL."""
    import server

    st = _load_session_state()
    owner = str(st.get("owner") or "roc").lower()
    if owner not in ("sma", "roc"):
        owner = "roc"
    block = st.get(owner) or {}
    sess = float(block.get("session_pnl") or 0)
    if owner == "sma":
        indicator = "SMA(10/30) 15m"
    else:
        tf = str(block.get("timeframe") or st.get("timeframe") or "30m")
        ind = str(block.get("indicator") or st.get("indicator") or "ROC(10)")
        if "ROC" in ind.upper() and " " not in ind.strip():
            indicator = f"{ind} {tf}"
        elif "ROC" in ind.upper():
            indicator = ind if tf in ind else f"ROC(10) {tf}"
        else:
            indicator = f"{ind} {tf}".strip()

    await server.runtime.start()
    try:
        pos = json.loads(await server.get_positions())
        lit = _lit_from_positions(pos.get("positions") or [])
    finally:
        await server.runtime.stop()

    if lit:
        notional = _pos_notional_usd(lit)
        vol = f"${notional:.0f}" if abs(notional - round(notional)) < 0.5 else f"${notional:.2f}"
        side = str(lit.get("side") or "").lower()
        if side not in ("long", "short"):
            side = "long" if int(lit.get("sign") or 0) > 0 else "short"
        try:
            u = float(lit.get("unrealized_pnl"))
            pos_pnl = f"+{u:.2f}" if u > 0 else f"{u:.2f}"
        except (TypeError, ValueError):
            pos_pnl = str(lit.get("unrealized_pnl"))
        sess_s = _pnl_signed(sess).replace("$", "")
        # Example: LIT $200 long +1.50 / sess −22.8 | ROC(10) 30m
        return f"LIT {vol} {side} {pos_pnl} / sess {sess_s} | {indicator}"

    sess_s = _pnl_signed(sess).replace("$", "")
    return f"LIT flat / sess {sess_s} | {indicator}"


async def notify_position_report(*, dry_run: bool = False) -> str:
    text = await build_position_report_text()
    if dry_run:
        return text
    send_message(text)
    return text


async def notify_tick(kind: str, *, dry_run: bool = False) -> str:
    text = await build_tick_text(kind)
    if dry_run:
        return text
    send_message(text)
    return text


def main(argv: Optional[list[str]] = None) -> int:
    parser = argparse.ArgumentParser(description="Telegram notify for Lighter positions")
    parser.add_argument("message", nargs="?", help="Custom text to send")
    parser.add_argument("--status", action="store_true", help="Fetch positions and send status")
    parser.add_argument("--tick", choices=("sma", "roc"), help="Compute strategy tick and send")
    parser.add_argument(
        "--position-report",
        action="store_true",
        help="30m cron line: asset $size side posPnL / sess | indicator",
    )
    parser.add_argument("--dry-run", action="store_true", help="Print only, do not send")
    args = parser.parse_args(argv)

    def _out(s: str) -> None:
        try:
            print(s)
        except UnicodeEncodeError:
            print(s.encode("ascii", "replace").decode("ascii"))

    if args.position_report:
        text = asyncio.run(notify_position_report(dry_run=args.dry_run))
        _out(text)
        return 0

    if args.tick:
        text = asyncio.run(notify_tick(args.tick, dry_run=args.dry_run))
        _out(text)
        return 0

    if args.status:
        text = asyncio.run(notify_status(dry_run=args.dry_run))
        _out(text)
        return 0

    if not args.message:
        parser.print_help()
        return 2

    if args.dry_run:
        print(args.message)
        return 0
    send_message(args.message)
    print("sent")
    return 0


if __name__ == "__main__":
    sys.exit(main())
