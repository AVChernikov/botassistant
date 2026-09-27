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
- open_long, open_short (предпочтительно quote_usd), close_position, place_tp_sl
- telegram_reply (отправить текст пользователю)
- record_trade_pnl (после закрытия — учёт wrong-entry для лота)
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
  добери через mysql_query, например:
  SELECT id, title, summary, model, created_at FROM indicator_reports
  WHERE market_id=120 ORDER BY id DESC LIMIT 3;
  или SELECT … FROM indicator_snapshots / indicator_stats WHERE market_id=120 …
- Если report.is_new=true — удели отчёту внимание при решении hold/enter/flip.
- Ты (Pro) не вызываешь Flash сам: Flash уже отработал offline; ты потребляешь результат.

СТРАТЕГИЯ / ПРАВИЛА:
- Метод: пересечение нуля ROC(10) на 1h; фильтр направления — ROC 12h. Confluence с MACD допустим; входы по RSI/BB не использовать.
- Развороты (flip): по усмотрению — взвешивай сетап + отчёт Flash; не обязателен на каждом пересечении.
- Лот: база $200. После 3 подряд ошибочных/убыточных входов → $400 (см. lot_policy в JSON). Потолок $400.
- После открытия: TP 50% от входа; SL = запас до kill сессии (sl_usd/lot). Всегда вызывай place_tp_sl после open/flip.
- Kill-switch: session_pnl ≤ -50 → только закрытие / без новых входов; ≥ +100 → не открывать новые позиции.
- Та же сторона уже открыта → не наращивай размер. Противоположная → flip (закрыть, затем открыть).
- Если неясно → hold (без tool calls, кроме telegram_reply, когда это требуется ниже).

TELEGRAM:
- ПРИОРИТЕТ №1: если `pending_tg` не пуст — **сразу** ответь через `telegram_reply` в этом же ходе
  (до или вместе с торговыми инструментами). Не откладывай. Ack после тика делается автоматически.
- `tg_history` — последние ~50 сообщений Telegram (in/out) с метками времени: используй для связности; не повторяй старые ответы.
- Отвечай на языке пользователя (обычно русский). Кратко и по делу (позиция / решение / цифры).
- Пользователь может просить статус, анализ, закрыть, стоп — следуй намерению. «стоп» → без новых входов (закрывай только если просят).
- РАСПИСАНИЕ ОТЧЁТА ПО ПОЗИЦИИ (локальные часы хоста в JSON `clock`):
  - Слоты каждый час: минута **10** и минута **40** (окно помечается хостом как `position_report.due`).
  - Если `position_report.due` = true: отправь **ровно один** telegram_reply — короткий отчёт по позиции
    (сторона, размер, вход, uPnL, session_pnl, запас до kill, сигналы ROC 1h/12h, твоё действие).
  - Если `position_report.due` = false: не спамь рутинным статусом.
  - Не отправляй плановый отчёт дважды для одного и того же `position_report.slot_id`.
  - Если есть и pending_tg, и position_report.due: сначала ответь пользователю, затем плановый отчёт (или совмести в одно короткое сообщение).

ВЫВОД:
- Для действий предпочитай tool calls. Короткий итоговый текст допустим для лога.
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
