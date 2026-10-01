"""Send LIT 1h + ROC charts to Telegram."""
from __future__ import annotations

import asyncio
import json
import time
from datetime import datetime, timezone
from pathlib import Path

import httpx
import matplotlib

matplotlib.use("Agg")
import matplotlib.pyplot as plt

import server
from telegram_notify import telegram_config

ROOT = Path(__file__).resolve().parent
TG_API = "https://api.telegram.org"


def roc(closes: list[float], p: int = 10) -> list:
    out = [None] * len(closes)
    for i in range(p, len(closes)):
        if closes[i - p]:
            out[i] = (closes[i] - closes[i - p]) / closes[i - p] * 100
    return out


def send_photo(path: Path, caption: str = "") -> None:
    token, chat_id = telegram_config()
    with open(path, "rb") as f:
        files = {"photo": (path.name, f, "image/png")}
        data = {"chat_id": chat_id, "caption": caption[:1024]}
        r = httpx.post(f"{TG_API}/bot{token}/sendPhoto", data=data, files=files, timeout=60)
        r.raise_for_status()


async def main() -> None:
    await server.runtime.start()
    try:
        end = int(time.time() * 1000)
        async with httpx.AsyncClient(timeout=30) as hx:
            r = await hx.get(
                server._base_url() + "/api/v1/candles",
                params={
                    "market_id": 120,
                    "resolution": "1h",
                    "start_timestamp": end - 60 * 60 * 1000 * 80,
                    "end_timestamp": end,
                    "count_back": 80,
                },
            )
            data = r.json()

        c = data.get("c") or []
        if c and isinstance(c[0], dict):
            full_closes = [float(x["c"]) for x in c]
            times = [int(x.get("t") or x.get("timestamp") or 0) for x in c]
            highs = [float(x.get("h", x["c"])) for x in c]
            lows = [float(x.get("l", x["c"])) for x in c]
            opens = [float(x.get("o", x["c"])) for x in c]
        else:
            full_closes = [float(x) for x in c]
            times = [int(x) for x in (data.get("t") or list(range(len(full_closes))))]
            highs = [float(x) for x in (data.get("h") or full_closes)]
            lows = [float(x) for x in (data.get("l") or full_closes)]
            opens = [float(x) for x in (data.get("o") or full_closes)]

        n = 50
        closes = full_closes[-n:]
        times = times[-n:]
        highs = highs[-n:]
        lows = lows[-n:]
        opens = opens[-n:]
        xs = list(range(len(closes)))
        labels = []
        for t in times:
            try:
                if t > 1e12:
                    t = t / 1000
                labels.append(datetime.fromtimestamp(t, tz=timezone.utc).strftime("%m-%d %H"))
            except Exception:
                labels.append(str(t))

        outdir = ROOT / "_charts"
        outdir.mkdir(exist_ok=True)

        fig, ax = plt.subplots(figsize=(12, 5), dpi=120)
        for i in xs:
            color = "#26a69a" if closes[i] >= opens[i] else "#ef5350"
            ax.plot([i, i], [lows[i], highs[i]], color=color, lw=1)
            ax.plot([i, i], [opens[i], closes[i]], color=color, lw=3)
        ax.set_title("LIT 1h — last 50 candles")
        ax.set_ylabel("USD")
        step = max(1, len(xs) // 10)
        ax.set_xticks(xs[::step])
        ax.set_xticklabels(labels[::step], rotation=45, ha="right", fontsize=8)
        ax.grid(True, alpha=0.3)
        fig.tight_layout()
        p1 = outdir / "lit_1h_50.png"
        fig.savefig(p1)
        plt.close(fig)

        ro = roc(full_closes, 10)[-n:]
        vals = [v if v is not None else float("nan") for v in ro]
        fig2, ax2 = plt.subplots(figsize=(12, 4), dpi=120)
        ax2.plot(xs, vals, color="#42a5f5", lw=2, label="ROC(10)")
        ax2.axhline(0, color="#999", lw=1)
        ax2.fill_between(
            xs, vals, 0, where=[(v if v == v else 0) >= 0 for v in vals], alpha=0.2, color="#26a69a"
        )
        ax2.fill_between(
            xs, vals, 0, where=[(v if v == v else 0) < 0 for v in vals], alpha=0.2, color="#ef5350"
        )
        ax2.set_title("LIT ROC(10) 1h — last 50")
        ax2.set_ylabel("%")
        ax2.set_xticks(xs[::step])
        ax2.set_xticklabels(labels[::step], rotation=45, ha="right", fontsize=8)
        ax2.grid(True, alpha=0.3)
        ax2.legend()
        fig2.tight_layout()
        p2 = outdir / "lit_roc_50.png"
        fig2.savefig(p2)
        plt.close(fig2)

        pos = json.loads(await server.get_positions())
        lit = [p for p in (pos.get("positions") or []) if int(p.get("market_id") or -1) == 120]
        extra = ""
        if lit:
            p = lit[0]
            extra = f" | {p.get('side')} uPnL {p.get('unrealized_pnl')}"

        last_roc = vals[-1] if vals and vals[-1] == vals[-1] else 0.0
        send_photo(p1, f"LIT 1h 50 свечей{extra}")
        send_photo(p2, f"ROC(10) 1h 50 баров | last={last_roc:.2f}%")
        print("ok", closes[-1], last_roc)
    finally:
        await server.runtime.stop()


if __name__ == "__main__":
    asyncio.run(main())
