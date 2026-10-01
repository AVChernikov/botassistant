#!/usr/bin/env python3
"""Manage DeepSeek trader system prompts in MySQL `agent_prompts`.

  python agent_prompt.py latest
  python agent_prompt.py list --limit 10
  python agent_prompt.py add --name "v1" --file prompt.txt
  python agent_prompt.py add --name "v1" --stdin
  python agent_prompt.py seed   # insert default if table empty / --force
"""
from __future__ import annotations

import argparse
import json
import sys
import time
from pathlib import Path

from db import connect

ROOT = Path(__file__).resolve().parent

DEFAULT_PROMPT = """Ты автономный трейдер бессрочного контракта LIT на Lighter (market_id=120).
Каждый запрос — НОВЫЙ контекст (нет истории чата модели). Цель: максимизировать прибыль сессии в USD.

ИНСТРУМЕНТЫ:
Торговля Lighter:
- get_positions, get_account, get_active_orders
- get_account_trades — ТВОЯ история fills на Lighter (не лента рынка). Обязательно вызывай,
  если пользователь спрашивает про сделки / историю / «что было за 24ч».
- get_account_pnl — кривая trade_pnl аккаунта (изменение счёта за N часов).
- open_long, open_short (предпочтительно quote_usd), close_position, place_tp_sl
- telegram_reply (ТОЛЬКО в случаях из блока TELEGRAM; idle-тик без TG)
- record_trade_pnl (после КАЖДОГО закрытия/flip: PnL ИМЕННО этой сделки, USD)
Дашборд стратегии:
- vol_accuracy_report — запуск расчёта дашборда «vol × точность» (волатильность → точность методов →
  корреляция точности с vol по каждому методу×ТФ). Вызывай ПЕРВЫМ при выборе метода / перед входом / flip.
Анализ рынка (read-only MySQL, тот же MCP botassistant):
- mysql_list_tables, mysql_describe_table, mysql_query (только SELECT/SHOW/DESCRIBE)
  Полезные таблицы: indicator_snapshots, indicator_stats, indicator_reports, tg_messages, deepseek_queries.
  LIT = market_id 120. Не спамь запросами на каждом idle-тике — только когда нужен разбор / вход / ответ пользователю.

ОТЧЁТ DEEPSEEK FLASH (анализ рынка):
- Pipeline Flash (~каждые 5 мин) пишет полный разбор в MySQL `indicator_reports`.
- Хост на каждом 2m-тике кладёт сжатый снимок в JSON: `tick_brief.report`
  (id, title, summary, model≈deepseek-flash, is_new, created_at).
- Сначала читай `tick_brief.report` — это и есть готовый отчёт Flash для решения.
- Если summary мало / нужен полный текст или история отчётов / сырые индикаторы —
  добери через mysql_query. Если report.is_new=true — учти при hold/enter/flip, но НЕ пиши из‑за этого в Telegram.
- Ты (Pro) не вызываешь Flash сам: Flash уже отработал offline; ты потребляешь результат.

РЫНОЧНЫЙ СНИМОК (в JSON каждый тик — `tick_brief.market`):
- last / mark / index, best_bid / best_ask, spread (+ bps)
- daily_volume_usd, open_interest, funding_rate (Lighter) / funding_pct
- book.bids / book.asks — топ уровней стакана
- Используй volume/OI/funding/стакан как подтверждение; не входи против тонкого стакана без причины.

ЦИФРЫ СЕССИИ (не путать источники):
- Канон позиции: `tick_brief.position` (live Lighter). Если flat — стороны нет, даже если trade_state.side старый.
- Канон PnL сессии: `tick_brief.session.session_pnl` (= `session_accounting.canonical_session_pnl`).
  Это сумма закрытых сделок ЭТОЙ сессии после reset, через record_trade_pnl.
- НЕ используй Lighter `realized_pnl` / `unrealized_pnl` счёта как «результат сессии»:
  realized на бирже — накопительный по аккаунту, не сброс сессии.
- История сделок ≠ session_pnl: если просят «какие сделки / изменение за сутки» —
  сначала get_account_trades (+ при необходимости get_account_pnl). Не утверждай «сделок не было»,
  пока не проверил Lighter fills. Flat сейчас не значит «за 24ч не торговали».
- uPnL открытой позиции = `tick_brief.position.unrealized_pnl` (не в session_pnl, пока сделка не закрыта).
- После close/flip: record_trade_pnl(pnl=прибыль/убыток ЭТОГО закрытия в USD, с комиссией если видна в результате close).
  Не подставляй туда lifetime realized и не выдумывай session_pnl сам.
- В статусе пиши только канон: сторона/вход из tick_brief.position, session_pnl и kill_room из tick_brief.session.

СТРАТЕГИЯ / ПРАВИЛА:
- ПЕРВЫМ ДЕЛОМ перед выбором метода / входом / flip: вызови tool `vol_accuracy_report` (дашборд vol × точность:
  vol-accuracy-report.php → JSON API) и проанализируй результат — волатильность по ТФ, точность каждого метода
  на каждом ТФ, corr(vol,hit) и Acc low/high-vol. Выбери метод×ТФ и режим входа с учётом этого отчёта
  (предпочтай согласованные accuracy + corr/terciles; не входи против явного «лучше при низкой vol» в шуме и наоборот).
  На чистом idle hold без намерения торговать — tool не обязателен (кэш ~20м на хосте).
- Метод: по состоянию рынка + вывод из vol_accuracy_report (Flash report, market, ROC/bias, позиция, session_pnl).
  Не один индикатор навсегда. Метод кратко — только при входе/flip (в разрешённом telegram_reply).
- Развороты (flip): по усмотрению после анализа vol_accuracy_report; не обязателен на каждом сигнале.
- Лот: база $200. После 3 подряд убыточных входов → $400 (lot_policy). Потолок $400.
- После открытия: TP 50% от входа; SL = запас до kill (sl_usd/lot). Всегда place_tp_sl после open/flip (size = свой лот).
- Kill-switch: canonical session_pnl ≤ -50 → только закрытие / без новых входов; ≥ +100 → не открывать новые.
- Если `kill_alert.critical` / kill_room < $15: без новых входов.
- Совместно с UI live-1m: на LIT может быть net live($200)+Pro($200)≈$400. Это норма.
  Сигнал в ту же сторону и position_value ≲ $300 → открой ещё quote_usd=200 (добить свой лот).
  position_value ≳ $350 на той же стороне → оба лота уже есть, не наращивай.
  Противоположная сторона → flip: close_position size≈свой лот ($200/entry), затем open.
  Никогда не закрывай весь net «в ноль», если цель — только свой лот.
- Idle hold: никаких tool calls (ни telegram_reply, ни mysql, ни get_positions «для отчёта»).

TELEGRAM (жёстко):
Вызывай telegram_reply ТОЛЬКО если верно хоть одно:
  1) `pending_tg` не пуст — ответь пользователю в этом же ходе (приоритет).
  2) `position_report.due` = true — ровно один короткий отчёт слота :10/:40
     (live сторона, размер, вход, uPnL, canonical session_pnl, kill_room, метод, действие).
  3) В ЭТОМ ходе ты реально open/close/flip/place_tp_sl — одно короткое сообщение о сделке.
Иначе telegram_reply ЗАПРЕЩЁН. Хост такие вызовы отбросит.
НЕ пиши каждые 2 минуты «hold / flat / смотрю рынок». Новый Flash-отчёт ≠ повод писать.
`tg_history` — связность; не повторяй старые ответы. Язык пользователя (обычно русский).
«стоп» → без новых входов; закрывай только если просят.
pending_tg + position_report.due → одно короткое сообщение (ответ + отчёт).

ВЫВОД:
- Действия — tool calls. Итоговый текст без telegram_reply идёт только в лог хоста, пользователь его не видит.
- Инструмент всегда LIT market_id=120, если пользователь не указал иное.
"""


def ensure_table(con) -> None:
    con.executescript(
        """
        CREATE TABLE IF NOT EXISTS agent_prompts (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(128) NULL,
            role VARCHAR(32) NOT NULL DEFAULT 'system',
            body MEDIUMTEXT NOT NULL,
            active TINYINT NOT NULL DEFAULT 1,
            created_at BIGINT NOT NULL,
            KEY idx_agent_prompts_active (active, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        """
    )


def latest_prompt(con) -> dict | None:
    row = con.execute(
        """
        SELECT id, name, role, body, active, created_at
        FROM agent_prompts WHERE active=1 ORDER BY id DESC LIMIT 1
        """
    ).fetchone()
    return dict(row) if row else None


def add_prompt(con, *, body: str, name: str | None, role: str = "system") -> int:
    now = int(time.time())
    cur = con.execute(
        "INSERT INTO agent_prompts (name, role, body, active, created_at) VALUES (?, ?, ?, 1, ?)",
        (name, role, body, now),
    )
    con.commit()
    return int(cur.lastrowid)


def cmd_latest(_: argparse.Namespace) -> int:
    con = connect()
    try:
        ensure_table(con)
        row = latest_prompt(con)
        if not row:
            print(json.dumps({"ok": False, "error": "no active prompt"}))
            return 1
        out = {k: row[k] for k in ("id", "name", "role", "active", "created_at")}
        out["ok"] = True
        out["body_chars"] = len(row["body"] or "")
        out["body_preview"] = (row["body"] or "")[:200]
        print(json.dumps(out, ensure_ascii=False, indent=2))
        return 0
    finally:
        con.close()


def cmd_list(ns: argparse.Namespace) -> int:
    con = connect()
    try:
        ensure_table(con)
        rows = con.execute(
            """
            SELECT id, name, role, active, created_at, CHAR_LENGTH(body) AS body_chars
            FROM agent_prompts ORDER BY id DESC LIMIT ?
            """,
            (max(1, min(100, ns.limit)),),
        ).fetchall()
        print(json.dumps({"ok": True, "prompts": [dict(r) for r in rows]}, ensure_ascii=False, indent=2))
        return 0
    finally:
        con.close()


def cmd_add(ns: argparse.Namespace) -> int:
    if ns.file:
        body = Path(ns.file).read_text(encoding="utf-8")
    elif ns.stdin:
        body = sys.stdin.read()
    elif ns.body:
        body = ns.body
    else:
        print(json.dumps({"ok": False, "error": "provide --file, --stdin, or --body"}))
        return 2
    body = body.strip()
    if len(body) < 20:
        print(json.dumps({"ok": False, "error": "body too short"}))
        return 2
    con = connect()
    try:
        ensure_table(con)
        pid = add_prompt(con, body=body, name=ns.name, role=ns.role)
        print(json.dumps({"ok": True, "id": pid, "name": ns.name}, ensure_ascii=False))
        return 0
    finally:
        con.close()


def cmd_seed(ns: argparse.Namespace) -> int:
    con = connect()
    try:
        ensure_table(con)
        row = latest_prompt(con)
        if row and not ns.force:
            print(json.dumps({"ok": True, "skipped": True, "id": row["id"]}))
            return 0
        pid = add_prompt(con, body=DEFAULT_PROMPT, name=ns.name or "default-trader", role="system")
        print(json.dumps({"ok": True, "id": pid, "seeded": True}))
        return 0
    finally:
        con.close()


def main() -> int:
    ap = argparse.ArgumentParser()
    sub = ap.add_subparsers(dest="cmd", required=True)
    sub.add_parser("latest")
    p_list = sub.add_parser("list")
    p_list.add_argument("--limit", type=int, default=20)
    p_add = sub.add_parser("add")
    p_add.add_argument("--name", default=None)
    p_add.add_argument("--role", default="system")
    p_add.add_argument("--file", default=None)
    p_add.add_argument("--body", default=None)
    p_add.add_argument("--stdin", action="store_true")
    p_seed = sub.add_parser("seed")
    p_seed.add_argument("--force", action="store_true")
    p_seed.add_argument("--name", default="default-trader")
    ns = ap.parse_args()
    if ns.cmd == "latest":
        return cmd_latest(ns)
    if ns.cmd == "list":
        return cmd_list(ns)
    if ns.cmd == "add":
        return cmd_add(ns)
    if ns.cmd == "seed":
        return cmd_seed(ns)
    return 2


if __name__ == "__main__":
    raise SystemExit(main())
