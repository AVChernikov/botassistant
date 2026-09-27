#!/usr/bin/env python3
"""Log DeepSeek query history (analyze / clarify / custom) — MySQL."""
from __future__ import annotations

import json
import sys
import time
from pathlib import Path

from db import Connection, connect


def save(con: Connection, row: dict) -> int:
    usage = row.get("usage") or {}
    if isinstance(usage, str):
        try:
            usage = json.loads(usage)
        except json.JSONDecodeError:
            usage = {}
    messages = row.get("messages")
    messages_json = None
    if messages is not None:
        messages_json = messages if isinstance(messages, str) else json.dumps(messages, ensure_ascii=False)
    meta = row.get("meta")
    meta_json = None
    if meta is not None:
        meta_json = meta if isinstance(meta, str) else json.dumps(meta, ensure_ascii=False)
    usage_json = json.dumps(usage, ensure_ascii=False) if usage else None

    cur = con.execute(
        """
        INSERT INTO deepseek_queries (
            purpose, model, market_id, report_id, parent_query_id,
            prompt_text, messages_json, response_text, reasoning_text,
            usage_json, prompt_tokens, completion_tokens, total_tokens,
            duration_ms, status, error_text, meta_json, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        """,
        (
            str(row.get("purpose") or "custom"),
            row.get("model"),
            row.get("market_id"),
            row.get("report_id"),
            row.get("parent_query_id"),
            row.get("prompt_text"),
            messages_json,
            row.get("response_text"),
            row.get("reasoning_text"),
            usage_json,
            usage.get("prompt_tokens"),
            usage.get("completion_tokens"),
            usage.get("total_tokens"),
            row.get("duration_ms"),
            str(row.get("status") or "ok"),
            row.get("error_text"),
            meta_json,
            int(time.time()),
        ),
    )
    con.commit()
    return int(cur.lastrowid)


def main() -> int:
    if len(sys.argv) < 2:
        print(json.dumps({"ok": False, "error": "payload required"}))
        return 1
    data = json.loads(Path(sys.argv[1]).read_text(encoding="utf-8-sig"))
    db_path = data["db_path"]
    op = data.get("op") or "save"
    con = connect(db_path)
    try:
        if op == "save":
            qid = save(con, data.get("row") or data)
            print(json.dumps({"ok": True, "id": qid}))
            return 0
        if op == "link_report":
            qid = int(data["id"])
            rid = int(data["report_id"])
            con.execute(
                "UPDATE deepseek_queries SET report_id=? WHERE id=?",
                (rid, qid),
            )
            con.commit()
            print(json.dumps({"ok": True, "id": qid, "report_id": rid}))
            return 0
        if op == "list":
            limit = int(data.get("limit") or 50)
            purpose = data.get("purpose")
            sql = """
                SELECT id, purpose, model, market_id, report_id, parent_query_id,
                       SUBSTR(prompt_text, 1, 200) AS prompt_preview,
                       SUBSTR(response_text, 1, 200) AS response_preview,
                       prompt_tokens, completion_tokens, total_tokens,
                       duration_ms, status, created_at
                FROM deepseek_queries
                WHERE 1=1
            """
            args: list = []
            if purpose:
                sql += " AND purpose=?"
                args.append(purpose)
            sql += " ORDER BY created_at DESC, id DESC LIMIT ?"
            args.append(max(1, min(200, limit)))
            cur = con.execute(sql, args)
            rows = [dict(r) for r in cur.fetchall()]
            print(json.dumps({"ok": True, "rows": rows}, ensure_ascii=True))
            return 0
        if op == "get":
            rid = int(data["id"])
            cur = con.execute("SELECT * FROM deepseek_queries WHERE id=?", (rid,))
            row = cur.fetchone()
            if not row:
                print(json.dumps({"ok": False, "error": "not found"}))
                return 0
            print(json.dumps({"ok": True, "row": dict(row)}, ensure_ascii=True))
            return 0
        print(json.dumps({"ok": False, "error": "unknown op"}))
        return 1
    finally:
        con.close()


if __name__ == "__main__":
    raise SystemExit(main())
