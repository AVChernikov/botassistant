#!/usr/bin/env python3
"""Save / list / get AI indicator pattern reports."""
from __future__ import annotations

import json
import sqlite3
import sys
import time
from pathlib import Path

SCHEMA = """
CREATE TABLE IF NOT EXISTS indicator_reports (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    market_id INTEGER,
    symbol TEXT,
    model TEXT,
    title TEXT,
    summary TEXT,
    findings_json TEXT,
    compact_bytes INTEGER,
    raw_response TEXT,
    created_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_ind_reports_created ON indicator_reports(created_at DESC);
"""


def connect(db_path: str) -> sqlite3.Connection:
    Path(db_path).parent.mkdir(parents=True, exist_ok=True)
    con = sqlite3.connect(db_path)
    con.executescript(SCHEMA)
    return con


def main() -> int:
    path = sys.argv[1] if len(sys.argv) > 1 else None
    if not path:
        print(json.dumps({"ok": False, "error": "payload path required"}))
        return 1
    data = json.loads(Path(path).read_text(encoding="utf-8"))
    db_path = data["db_path"]
    op = data.get("op")
    con = connect(db_path)
    try:
        if op == "save":
            r = data.get("report") or {}
            findings = r.get("findings")
            if findings is not None and not isinstance(findings, str):
                findings = json.dumps(findings, ensure_ascii=False)
            cur = con.execute(
                """
                INSERT INTO indicator_reports
                    (market_id, symbol, model, title, summary, findings_json, compact_bytes, raw_response, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                """,
                (
                    r.get("market_id"),
                    r.get("symbol"),
                    r.get("model"),
                    r.get("title"),
                    r.get("summary"),
                    findings,
                    r.get("compact_bytes"),
                    r.get("raw_response"),
                    int(time.time()),
                ),
            )
            con.commit()
            print(json.dumps({"ok": True, "id": cur.lastrowid}))
            return 0

        if op == "list":
            limit = int(data.get("limit") or 20)
            mid = data.get("market_id")
            sql = """
                SELECT id, market_id, symbol, model, title, summary, compact_bytes, created_at
                FROM indicator_reports
                WHERE 1=1
            """
            args: list = []
            if mid is not None:
                sql += " AND market_id=?"
                args.append(int(mid))
            sql += " ORDER BY created_at DESC LIMIT ?"
            args.append(max(1, min(100, limit)))
            cur = con.execute(sql, args)
            cols = [d[0] for d in cur.description]
            rows = [dict(zip(cols, row)) for row in cur.fetchall()]
            print(json.dumps({"ok": True, "reports": rows}, ensure_ascii=True))
            return 0

        if op == "get":
            rid = int(data["id"])
            cur = con.execute(
                """
                SELECT id, market_id, symbol, model, title, summary, findings_json,
                       compact_bytes, raw_response, created_at
                FROM indicator_reports WHERE id=?
                """,
                (rid,),
            )
            row = cur.fetchone()
            if not row:
                print(json.dumps({"ok": False, "error": "not found"}))
                return 0
            cols = [d[0] for d in cur.description]
            report = dict(zip(cols, row))
            if report.get("findings_json"):
                try:
                    report["findings"] = json.loads(report["findings_json"])
                except json.JSONDecodeError:
                    report["findings"] = report["findings_json"]
            print(json.dumps({"ok": True, "report": report}, ensure_ascii=True))
            return 0

        print(json.dumps({"ok": False, "error": "unknown op"}))
        return 1
    finally:
        con.close()


if __name__ == "__main__":
    raise SystemExit(main())
