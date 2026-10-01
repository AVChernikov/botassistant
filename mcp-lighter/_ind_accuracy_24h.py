"""Compare LIT indicator signal accuracy over last 24h and 12h; send TG summary."""
from __future__ import annotations

import asyncio
import time
from typing import Optional

import httpx

import server
from telegram_notify import send_message


def sma(vals: list[float], p: int) -> list[Optional[float]]:
    out: list[Optional[float]] = [None] * len(vals)
    for i in range(p - 1, len(vals)):
        out[i] = sum(vals[i - p + 1 : i + 1]) / p
    return out


def roc(closes: list[float], p: int = 10) -> list[Optional[float]]:
    out: list[Optional[float]] = [None] * len(closes)
    for i in range(p, len(closes)):
        if closes[i - p]:
            out[i] = (closes[i] - closes[i - p]) / closes[i - p] * 100
    return out


def zero_cross(r: list[Optional[float]], i: int) -> Optional[str]:
    if i < 1 or r[i] is None or r[i - 1] is None:
        return None
    if r[i - 1] <= 0 and r[i] > 0:
        return "UP"
    if r[i - 1] >= 0 and r[i] < 0:
        return "DOWN"
    return None


def sma_cross(f, s, i) -> Optional[str]:
    if i < 1 or f[i] is None or s[i] is None or f[i - 1] is None or s[i - 1] is None:
        return None
    if f[i - 1] <= s[i - 1] and f[i] > s[i]:
        return "UP"
    if f[i - 1] >= s[i - 1] and f[i] < s[i]:
        return "DOWN"
    return None


async def fetch(res: str, hours_back: int, count: int):
    end = int(time.time() * 1000)
    async with httpx.AsyncClient(timeout=30) as hx:
        r = await hx.get(
            server._base_url() + "/api/v1/candles",
            params={
                "market_id": 120,
                "resolution": res,
                "start_timestamp": end - hours_back * 3600 * 1000,
                "end_timestamp": end,
                "count_back": count,
            },
        )
        r.raise_for_status()
        data = r.json()
    c = data.get("c") or []
    if c and isinstance(c[0], dict):
        closes = [float(x["c"]) for x in c]
    else:
        closes = [float(x) for x in c]
    return closes


def eval_signals(closes, signals, horizon: int):
    rows = []
    for i, sig in enumerate(signals):
        if not sig or i + horizon >= len(closes):
            continue
        ret = (closes[i + horizon] - closes[i]) / closes[i] * 100
        signed = ret if sig == "UP" else -ret
        rows.append(signed)
    return rows


def score(name: str, rows: list[float]) -> str:
    if not rows:
        return f"• {name}: нет сигналов"
    wins = sum(1 for x in rows if x > 0)
    avg = sum(rows) / len(rows)
    total = sum(rows)
    return (
        f"• {name}: n={len(rows)} WR={100 * wins / len(rows):.0f}% "
        f"sum={total:+.2f}% avg={avg:+.2f}%"
    )


def window_signals(closes, make_sig, bars: int):
    """Fill signals only on last `bars` closed bars (exclude forming)."""
    end = len(closes) - 2
    start = max(1, end - bars + 1)
    sigs = [None] * len(closes)
    for i in range(start, end + 1):
        sigs[i] = make_sig(i)
    return sigs


def rank_block(title: str, scored_rows: list[tuple[str, list[float]]]) -> list[str]:
    lines = [title]
    for name, rows in scored_rows:
        lines.append(score(name, rows))
    ranked = [(sum(rows), name) for name, rows in scored_rows if rows]
    if ranked:
        ranked.sort(reverse=True)
        lines.append("Рейтинг (по sum %):")
        for i, (total, name) in enumerate(ranked, 1):
            lines.append(f"  {i}. {name} ({total:+.2f}%)")
        lines.append(f"Лучший: {ranked[0][1]}")
    return lines


async def build_summary() -> str:
    await server.runtime.start()
    try:
        c1 = await fetch("1h", 48, 60)
        c15 = await fetch("15m", 30, 140)
        c30 = await fetch("30m", 30, 80)

        r1 = roc(c1, 10)
        r15 = roc(c15, 10)
        r30 = roc(c30, 10)
        f = sma(c15, 10)
        s = sma(c15, 30)

        def pack(hours: int) -> list[tuple[str, list[float]]]:
            # bars ≈ hours / tf
            b15 = hours * 4
            b30 = hours * 2
            b1 = hours
            sigs_roc15 = window_signals(c15, lambda i: zero_cross(r15, i), b15)
            sigs_sma = window_signals(c15, lambda i: sma_cross(f, s, i), b15)
            sigs30 = window_signals(c30, lambda i: zero_cross(r30, i), b30)
            sigs1 = window_signals(c1, lambda i: zero_cross(r1, i), b1)
            return [
                ("ROC(10) 15m", eval_signals(c15, sigs_roc15, 4)),
                ("SMA(10/30) 15m", eval_signals(c15, sigs_sma, 4)),
                ("ROC(10) 30m", eval_signals(c30, sigs30, 2)),
                ("ROC(10) 1h", eval_signals(c1, sigs1, 3)),
            ]

        lines: list[str] = [
            "Рейтинг индикаторов LIT (мат. анализ, closed, fwd по направлению)",
            "",
        ]
        lines.extend(rank_block("—— 24 часа ——", pack(24)))
        lines.append("")
        lines.extend(rank_block("—— 12 часов ——", pack(12)))
        lines.append("")
        lines.append("Сейчас торгуем: ROC(10) 15m.")
        return "\n".join(lines)
    finally:
        await server.runtime.stop()


async def main() -> None:
    text = await build_summary()
    send_message(text)
    print(text)


if __name__ == "__main__":
    asyncio.run(main())
