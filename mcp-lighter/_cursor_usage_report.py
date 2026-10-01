#!/usr/bin/env python3
"""Cursor usage report: last N hours via dashboard API + usage-summary."""
from __future__ import annotations

import base64
import json
import os
import sqlite3
import urllib.error
import urllib.request
from collections import defaultdict
from datetime import datetime, timedelta, timezone

MSK = timezone(timedelta(hours=3))
HOURS = 10
PAGE_SIZE = 100
MONTHS = {
    1: "янв", 2: "фев", 3: "мар", 4: "апр", 5: "май", 6: "июн",
    7: "июл", 8: "авг", 9: "сен", 10: "окт", 11: "ноя", 12: "дек",
}


def load_token() -> str:
    src = os.path.expandvars(r"%APPDATA%\Cursor\User\globalStorage\state.vscdb")
    # read-only URI avoids copying a large DB when TEMP is low on space
    uri = "file:" + src.replace("\\", "/") + "?mode=ro"
    con = sqlite3.connect(uri, uri=True)
    try:
        row = con.execute(
            "SELECT value FROM ItemTable WHERE key='cursorAuth/accessToken'"
        ).fetchone()
        if not row or not row[0]:
            raise SystemExit("NO_TOKEN")
        return row[0]
    finally:
        con.close()


def jwt_sub(token: str) -> str:
    payload = token.split(".")[1]
    payload += "=" * (-len(payload) % 4)
    return json.loads(base64.urlsafe_b64decode(payload)).get("sub") or ""


def http_json(url: str, token: str, method: str = "GET", body: dict | None = None):
    data = None if body is None else json.dumps(body).encode()
    sub = jwt_sub(token)
    cookie = f"WorkosCursorSessionToken={sub}%3A%3A{token}"
    headers = {
        "Authorization": f"Bearer {token}",
        "Accept": "application/json",
        "Content-Type": "application/json",
        "User-Agent": "Mozilla/5.0 Cursor",
        "Origin": "https://cursor.com",
        "Referer": "https://cursor.com/dashboard?tab=usage",
        "Cookie": cookie,
    }
    req = urllib.request.Request(url, data=data, headers=headers, method=method)
    with urllib.request.urlopen(req, timeout=60) as resp:
        return json.loads(resp.read().decode())


def main() -> int:
    token = load_token()
    end = datetime.now(timezone.utc)
    start = end - timedelta(hours=HOURS)
    start_ms = int(start.timestamp() * 1000)
    end_ms = int(end.timestamp() * 1000)

    summary = http_json("https://api2.cursor.sh/auth/usage-summary", token)
    plan = (summary.get("individualUsage") or {}).get("plan") or {}
    od = (summary.get("individualUsage") or {}).get("onDemand") or {}

    events: list[dict] = []
    page = 1
    total_count = None
    while page <= 40:
        data = http_json(
            "https://cursor.com/api/dashboard/get-filtered-usage-events",
            token,
            method="POST",
            body={
                "teamId": 0,
                "startDate": str(start_ms),
                "endDate": str(end_ms),
                "page": page,
                "pageSize": PAGE_SIZE,
            },
        )
        batch = data.get("usageEventsDisplay") or data.get("usageEvents") or []
        if total_count is None:
            total_count = data.get("totalUsageEventsCount")
        events.extend(batch)
        if not batch or len(batch) < PAGE_SIZE:
            break
        if total_count is not None and len(events) >= int(total_count):
            break
        page += 1

    # keep only window
    filtered = []
    for ev in events:
        try:
            ts = int(ev.get("timestamp") or 0)
        except (TypeError, ValueError):
            ts = 0
        if not ts or start_ms <= ts <= end_ms:
            filtered.append(ev)
    events = filtered

    inp = out = cache = cost_cents = req_units = 0.0
    by_conv: dict[str, dict] = defaultdict(
        lambda: {"n": 0, "cost_cents": 0.0, "in": 0.0, "out": 0.0, "cache": 0.0}
    )
    models: dict[str, int] = defaultdict(int)

    for ev in events:
        tu = ev.get("tokenUsage") or {}
        i = float(tu.get("inputTokens") or 0)
        o = float(tu.get("outputTokens") or 0)
        c = float(tu.get("cacheReadTokens") or 0)
        cents = float(ev.get("chargedCents") or tu.get("totalCents") or 0)
        ru = float(ev.get("requestsCosts") or 0)

        inp += i
        out += o
        cache += c
        cost_cents += cents
        req_units += ru

        cid = str(ev.get("conversationId") or "unknown")
        by_conv[cid]["n"] += 1
        by_conv[cid]["cost_cents"] += cents
        by_conv[cid]["in"] += i
        by_conv[cid]["out"] += o
        by_conv[cid]["cache"] += c
        models[str(ev.get("model") or "unknown")] += 1

    sm = start.astimezone(MSK)
    em = end.astimezone(MSK)
    window = (
        f"{sm.day} {MONTHS[sm.month]} {sm.strftime('%H:%M')} -> "
        f"{em.day} {MONTHS[em.month]} {em.strftime('%H:%M')} MSK"
    )

    plan_used = plan.get("used")
    plan_limit = plan.get("limit")
    pct = None
    if plan_used is not None and plan_limit:
        pct = round(100.0 * float(plan_used) / float(plan_limit), 1)

    top = sorted(by_conv.items(), key=lambda x: -x[1]["cost_cents"])
    model_top = sorted(models.items(), key=lambda x: -x[1])

    # human report to stdout
    def fmt_num(n: float) -> str:
        return f"{int(round(n)):,}".replace(",", "\u202f")

    def fmt_m(n: float) -> str:
        if n >= 1_000_000:
            return f"~{n/1_000_000:.1f}M".replace(".", ",")
        if n >= 1_000:
            return f"~{n/1_000:.1f}K".replace(".", ",")
        return str(int(n))

    print(f"За последние {HOURS} часов ({window}), по API Cursor:\n")
    print(f"Событий\n{len(events)}")
    print(f"Input\n{fmt_num(inp)}")
    print(f"Output\n{fmt_num(out)}")
    print(f"In+Out\n{fmt_m(inp + out)}")
    print(f"Cache read\n{fmt_m(cache)}")
    print(f"Стоимость\n~${cost_cents/100:.2f}")
    print(f"Request-units\n{int(round(req_units))}")
    print(f"Модель\n{', '.join(f'{m} ({c})' for m, c in model_top[:3]) or '—'}")
    if len(top) > 1:
        print("\nПо диалогам:")
        for cid, v in top[:6]:
            short = cid[:8] + "…" if len(cid) > 8 else cid
            print(f"${v['cost_cents']/100:.2f} — {short} ({v['n']} событий)")
    print(
        f"\nUltra сейчас: {plan_used} / {plan_limit} included (~{pct}%), "
        f"on-demand {od.get('used') or 0}."
    )
    # also machine-readable footer
    print(
        "\n<!--json-->\n"
        + json.dumps(
            {
                "events": len(events),
                "input": int(inp),
                "output": int(out),
                "cache": int(cache),
                "cost_usd": round(cost_cents / 100, 2),
                "request_units": round(req_units, 1),
                "ultra_used": plan_used,
                "ultra_limit": plan_limit,
            },
            ensure_ascii=False,
        )
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
