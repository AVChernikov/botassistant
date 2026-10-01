#!/usr/bin/env python3
"""CRUD for sim_1m_* tables (paper / emulation mode, isolated from live trading)."""
from __future__ import annotations

import argparse
import json
import sys
import time
from pathlib import Path

from db import connect


def _now() -> int:
    return int(time.time())


def _ensure_columns(con) -> None:
    """Best-effort ALTER for existing MySQL tables (CREATE IF NOT EXISTS won't add cols)."""
    try:
        cols = {
            str(r[0]).lower()
            for r in con.execute("SHOW COLUMNS FROM sim_1m_ticks").fetchall()
        }
        if "resolution" not in cols:
            con.execute("ALTER TABLE sim_1m_ticks ADD COLUMN resolution VARCHAR(8) NULL AFTER method")
            con.commit()
    except Exception:
        pass


def start(db_path: str, cfg: dict) -> dict:
    con = connect(db_path)
    try:
        _ensure_columns(con)
        # stop any running session first (only one active emulation)
        con.execute(
            "UPDATE sim_1m_sessions SET status='stopped', stopped_at=?, updated_at=? WHERE status='running'",
            (_now(), _now()),
        )
        now = _now()
        tp_levels = _normalize_pct_list(cfg.get("tp_levels"), [float(cfg.get("tp_pct") or 50)])
        sl_levels = _normalize_pct_list(cfg.get("sl_levels"), [float(cfg.get("sl_pct") or 30)])
        tp_pct = float(min(tp_levels))
        sl_pct = float(min(sl_levels))
        allowed_lots = [50, 100, 150, 200, 250, 300, 350, 400]
        try:
            lot_cand = float(cfg.get("lot_usd") or 200)
        except (TypeError, ValueError):
            lot_cand = 200.0
        lot_usd = float(min(allowed_lots, key=lambda x: abs(x - lot_cand)))
        cfg = dict(cfg)
        cfg["tp_levels"] = tp_levels
        cfg["sl_levels"] = sl_levels
        cfg["tp_pct"] = tp_pct
        cfg["sl_pct"] = sl_pct
        cfg["lot_usd"] = lot_usd
        con.execute(
            """
            INSERT INTO sim_1m_sessions (
                market_id, symbol, status, mode, resolution, method,
                lot_usd, tick_sec, tp_pct, sl_pct, kill_lo, kill_hi,
                session_pnl, realized_pnl, fees,
                ticks_count, trades_count, config_json, started_at, updated_at
            ) VALUES (?, ?, 'running', 'emulation', ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, 0, 0, 0, ?, ?, ?)
            """,
            (
                int(cfg.get("market_id") or 120),
                cfg.get("symbol") or "LIT",
                cfg.get("resolution") or "1m",
                cfg.get("method") or "ROC(10) zero-cross",
                lot_usd,
                int(cfg.get("tick_sec") or 30),
                tp_pct,
                sl_pct,
                float(cfg.get("kill_lo") or -50),
                float(cfg.get("kill_hi") or 100),
                json.dumps(cfg, ensure_ascii=True),
                now,
                now,
            ),
        )
        sid = int(con.execute("SELECT LAST_INSERT_ID()").fetchone()[0])
        con.execute(
            "INSERT INTO sim_1m_logs (session_id, created_at, level, message) VALUES (?, ?, 'info', ?)",
            (sid, now, "emulation session started (live crons untouched)"),
        )
        con.commit()
        return {"ok": True, "session_id": sid, "started_at": now}
    finally:
        con.close()


def stop(db_path: str, session_id: int | None = None) -> dict:
    con = connect(db_path)
    try:
        now = _now()
        if session_id:
            con.execute(
                "UPDATE sim_1m_sessions SET status='stopped', stopped_at=?, updated_at=? WHERE id=? AND status='running'",
                (now, now, int(session_id)),
            )
            sid = int(session_id)
        else:
            row = con.execute(
                "SELECT id FROM sim_1m_sessions WHERE status='running' ORDER BY id DESC LIMIT 1"
            ).fetchone()
            if not row:
                return {"ok": True, "stopped": False, "reason": "no running session"}
            sid = int(row[0])
            con.execute(
                "UPDATE sim_1m_sessions SET status='stopped', stopped_at=?, updated_at=? WHERE id=?",
                (now, now, sid),
            )
        con.execute(
            "INSERT INTO sim_1m_logs (session_id, created_at, level, message) VALUES (?, ?, 'info', ?)",
            (sid, now, "emulation session stopped"),
        )
        con.commit()
        return {"ok": True, "stopped": True, "session_id": sid, "stopped_at": now}
    finally:
        con.close()


def get_session(db_path: str, session_id: int | None = None) -> dict | None:
    con = connect(db_path)
    try:
        if session_id:
            row = con.execute("SELECT * FROM sim_1m_sessions WHERE id=?", (int(session_id),)).fetchone()
        else:
            row = con.execute(
                "SELECT * FROM sim_1m_sessions WHERE status='running' ORDER BY id DESC LIMIT 1"
            ).fetchone()
            if not row:
                row = con.execute(
                    "SELECT * FROM sim_1m_sessions ORDER BY id DESC LIMIT 1"
                ).fetchone()
        return dict(row) if row else None
    finally:
        con.close()


def save_tick_bundle(db_path: str, payload: dict) -> dict:
    """Update session + insert tick (+ optional trades/logs) atomically."""
    con = connect(db_path)
    try:
        _ensure_columns(con)
        now = _now()
        sess = payload["session"]
        sid = int(sess["id"])
        con.execute(
            """
            UPDATE sim_1m_sessions SET
                session_pnl=?, realized_pnl=?, fees=?,
                position_side=?, position_size=?, entry_price=?, entry_ts=?,
                tp_price=?, sl_price=?,
                ticks_count=?, trades_count=?,
                last_tick_at=?, last_bar_ts=?, last_action=?, last_reason=?,
                status=?, stopped_at=?, updated_at=?
            WHERE id=?
            """,
            (
                sess.get("session_pnl"),
                sess.get("realized_pnl"),
                sess.get("fees"),
                sess.get("position_side"),
                sess.get("position_size"),
                sess.get("entry_price"),
                sess.get("entry_ts"),
                sess.get("tp_price"),
                sess.get("sl_price"),
                sess.get("ticks_count"),
                sess.get("trades_count"),
                now,
                sess.get("last_bar_ts"),
                sess.get("last_action"),
                sess.get("last_reason"),
                sess.get("status") or "running",
                sess.get("stopped_at"),
                now,
                sid,
            ),
        )
        tick = payload.get("tick") or {}
        con.execute(
            """
            INSERT INTO sim_1m_ticks (
                session_id, created_at, bar_ts, price, bid, ask, spread_bps,
                method, resolution, roc_sig, sma_sig, method_sig, action, reason,
                position_side, u_pnl, session_pnl, payload_json
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            """,
            (
                sid,
                now,
                tick.get("bar_ts"),
                tick.get("price"),
                tick.get("bid"),
                tick.get("ask"),
                tick.get("spread_bps"),
                tick.get("method"),
                tick.get("resolution"),
                tick.get("roc_sig"),
                tick.get("sma_sig"),
                tick.get("method_sig"),
                tick.get("action"),
                tick.get("reason"),
                tick.get("position_side"),
                tick.get("u_pnl"),
                tick.get("session_pnl"),
                json.dumps(tick.get("payload") or {}, ensure_ascii=True),
            ),
        )
        tick_id = int(con.execute("SELECT LAST_INSERT_ID()").fetchone()[0])
        trade_ids = []
        for tr in payload.get("trades") or []:
            con.execute(
                """
                INSERT INTO sim_1m_trades (
                    session_id, created_at, bar_ts, side, action,
                    price, size, quote_usd, pnl, fees, reason
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                """,
                (
                    sid,
                    now,
                    tr.get("bar_ts"),
                    tr.get("side"),
                    tr.get("action"),
                    tr.get("price"),
                    tr.get("size"),
                    tr.get("quote_usd"),
                    tr.get("pnl"),
                    tr.get("fees"),
                    tr.get("reason"),
                ),
            )
            trade_ids.append(int(con.execute("SELECT LAST_INSERT_ID()").fetchone()[0]))
        for msg in payload.get("logs") or []:
            con.execute(
                "INSERT INTO sim_1m_logs (session_id, created_at, level, message) VALUES (?, ?, ?, ?)",
                (sid, now, msg.get("level") or "info", str(msg.get("message") or "")[:2000]),
            )
        con.commit()
        return {"ok": True, "tick_id": tick_id, "trade_ids": trade_ids, "session_id": sid}
    finally:
        con.close()


def set_lead(db_path: str, session_id: int | None, method: str, resolution: str | None = None) -> dict:
    con = connect(db_path)
    try:
        now = _now()
        method = str(method or "").strip()
        if not method:
            return {"ok": False, "error": "method required"}
        if session_id:
            row = con.execute(
                "SELECT id FROM sim_1m_sessions WHERE id=? AND status='running'",
                (int(session_id),),
            ).fetchone()
        else:
            row = con.execute(
                "SELECT id FROM sim_1m_sessions WHERE status='running' ORDER BY id DESC LIMIT 1"
            ).fetchone()
        if not row:
            return {"ok": False, "error": "no running session"}
        sid = int(row[0])
        res = str(resolution or "").strip() or None
        if res:
            con.execute(
                "UPDATE sim_1m_sessions SET method=?, resolution=?, updated_at=?, last_reason=? WHERE id=?",
                (method, res, now, f"lead -> {method} @{res}", sid),
            )
            msg = f"ведущий lead: {method} @ {res}"
        else:
            con.execute(
                "UPDATE sim_1m_sessions SET method=?, updated_at=?, last_reason=? WHERE id=?",
                (method, now, f"method -> {method}", sid),
            )
            msg = f"ведущий метод изменён: {method}"
        con.execute(
            "INSERT INTO sim_1m_logs (session_id, created_at, level, message) VALUES (?, ?, 'info', ?)",
            (sid, now, msg),
        )
        con.commit()
        return {"ok": True, "session_id": sid, "method": method, "resolution": res}
    finally:
        con.close()


def set_method(db_path: str, session_id: int | None, method: str) -> dict:
    return set_lead(db_path, session_id, method, None)


def _normalize_pct_list(raw, fallback: list[float]) -> list[float]:
    out: list[float] = []
    if isinstance(raw, str):
        try:
            raw = json.loads(raw)
        except Exception:
            raw = []
    if not isinstance(raw, (list, tuple)):
        raw = []
    for x in raw:
        try:
            v = float(x)
        except (TypeError, ValueError):
            continue
        if 0.5 <= v <= 500 and v not in out:
            out.append(v)
    out.sort()
    return out if out else list(fallback)


def set_levels(
    db_path: str,
    session_id: int | None,
    tp_levels: list | None = None,
    sl_levels: list | None = None,
    tp_price: float | None = None,
    sl_price: float | None = None,
    lot_usd: float | None = None,
) -> dict:
    """Persist selected TP/SL % levels + optional lot into config_json + primary tp_pct/sl_pct."""
    con = connect(db_path)
    try:
        _ensure_columns(con)
        now = _now()
        if session_id:
            row = con.execute(
                "SELECT id, config_json, entry_price, position_side, lot_usd FROM sim_1m_sessions WHERE id=?",
                (int(session_id),),
            ).fetchone()
        else:
            row = con.execute(
                """
                SELECT id, config_json, entry_price, position_side, lot_usd FROM sim_1m_sessions
                WHERE status='running' ORDER BY id DESC LIMIT 1
                """
            ).fetchone()
        if not row:
            return {"ok": False, "error": "no session"}
        sid = int(row[0])
        cfg = {}
        try:
            cfg = json.loads(row[1] or "{}") if row[1] else {}
        except Exception:
            cfg = {}
        if not isinstance(cfg, dict):
            cfg = {}
        tps = _normalize_pct_list(tp_levels if tp_levels is not None else cfg.get("tp_levels"), [50.0])
        sls = _normalize_pct_list(sl_levels if sl_levels is not None else cfg.get("sl_levels"), [30.0])
        cfg["tp_levels"] = tps
        cfg["sl_levels"] = sls
        tp_pct = float(min(tps))
        sl_pct = float(min(sls))
        allowed_lots = [50, 100, 150, 200, 250, 300, 350, 400]
        lot = float(row[4] or 200)
        if lot_usd is not None:
            try:
                cand = float(lot_usd)
            except (TypeError, ValueError):
                cand = lot
            lot = float(min(allowed_lots, key=lambda x: abs(x - cand)))
            cfg["lot_usd"] = lot
        entry = float(row[2] or 0) if row[2] is not None else 0.0
        side = (row[3] or None) if len(row) > 3 else None
        # UI changes apply to *next* entry: do not move active position tp/sl
        tp_p = tp_price
        sl_p = sl_price
        con.execute(
            """
            UPDATE sim_1m_sessions SET
                tp_pct=?, sl_pct=?, lot_usd=?, config_json=?,
                tp_price=COALESCE(?, tp_price), sl_price=COALESCE(?, sl_price),
                updated_at=?, last_reason=?
            WHERE id=?
            """,
            (
                tp_pct,
                sl_pct,
                lot,
                json.dumps(cfg, ensure_ascii=True),
                tp_p,
                sl_p,
                now,
                f"next-entry levels TP{tps} SL{sls} lot=${int(lot)}",
                sid,
            ),
        )
        con.execute(
            "INSERT INTO sim_1m_logs (session_id, created_at, level, message) VALUES (?, ?, 'info', ?)",
            (sid, now, f"след. вход: TP%={tps} SL%={sls} lot=${int(lot)}"),
        )
        con.commit()
        return {
            "ok": True,
            "session_id": sid,
            "tp_levels": tps,
            "sl_levels": sls,
            "tp_pct": tp_pct,
            "sl_pct": sl_pct,
            "tp_price": tp_p,
            "sl_price": sl_p,
            "lot_usd": lot,
        }
    finally:
        con.close()


def status_bundle(db_path: str, session_id: int | None = None, ticks: int = 40, trades: int = 30) -> dict:
    con = connect(db_path)
    try:
        _ensure_columns(con)
        sess = get_session(db_path, session_id)
        if not sess:
            return {"ok": True, "session": None, "ticks": [], "trades": [], "logs": []}
        sid = int(sess["id"])
        tick_rows = [
            dict(r)
            for r in con.execute(
                """
                SELECT id, created_at, bar_ts, price, bid, ask, spread_bps,
                       method, resolution, roc_sig, sma_sig, method_sig, action, reason,
                       position_side, u_pnl, session_pnl
                FROM sim_1m_ticks WHERE session_id=? ORDER BY id DESC LIMIT ?
                """,
                (sid, int(ticks)),
            ).fetchall()
        ]
        trade_rows = [
            dict(r)
            for r in con.execute(
                """
                SELECT id, created_at, bar_ts, side, action, price, size,
                       quote_usd, pnl, fees, reason
                FROM sim_1m_trades WHERE session_id=? ORDER BY id DESC LIMIT ?
                """,
                (sid, int(trades)),
            ).fetchall()
        ]
        log_rows = [
            dict(r)
            for r in con.execute(
                """
                SELECT id, created_at, level, message
                FROM sim_1m_logs WHERE session_id=? ORDER BY id DESC LIMIT 40
                """,
                (sid,),
            ).fetchall()
        ]
        return {
            "ok": True,
            "session": sess,
            "ticks": tick_rows,
            "trades": trade_rows,
            "logs": log_rows,
        }
    finally:
        con.close()


def set_position(
    db_path: str,
    session_id: int | None,
    side: str | None,
    size: float | None,
    entry: float | None,
    tp: float | None = None,
    sl: float | None = None,
    reason: str = "adopt",
) -> dict:
    con = connect(db_path)
    try:
        _ensure_columns(con)
        now = _now()
        if session_id:
            row = con.execute(
                "SELECT id FROM sim_1m_sessions WHERE id=? AND status='running'",
                (int(session_id),),
            ).fetchone()
        else:
            row = con.execute(
                "SELECT id FROM sim_1m_sessions WHERE status='running' ORDER BY id DESC LIMIT 1"
            ).fetchone()
        if not row:
            return {"ok": False, "error": "no running session"}
        sid = int(row[0])
        side_n = (str(side).strip().lower() if side else "") or None
        if side_n not in (None, "long", "short"):
            return {"ok": False, "error": "side must be long|short|null"}
        if side_n is None:
            con.execute(
                """
                UPDATE sim_1m_sessions SET
                    position_side=NULL, position_size=NULL, entry_price=NULL, entry_ts=NULL,
                    tp_price=NULL, sl_price=NULL, last_action=?, last_reason=?, updated_at=?
                WHERE id=?
                """,
                ("flat", reason, now, sid),
            )
            msg = f"позиция снята ({reason})"
        else:
            con.execute(
                """
                UPDATE sim_1m_sessions SET
                    position_side=?, position_size=?, entry_price=?, entry_ts=?,
                    tp_price=?, sl_price=?, last_action=?, last_reason=?, updated_at=?
                WHERE id=?
                """,
                (
                    side_n,
                    float(size or 0),
                    float(entry or 0),
                    now * 1000,
                    tp,
                    sl,
                    "adopt",
                    reason,
                    now,
                    sid,
                ),
            )
            msg = f"в сессию включена {side_n} @ {entry} ({reason})"
        con.execute(
            "INSERT INTO sim_1m_logs (session_id, created_at, level, message) VALUES (?, ?, 'info', ?)",
            (sid, now, msg),
        )
        con.commit()
        return {
            "ok": True,
            "session_id": sid,
            "position_side": side_n,
            "position_size": float(size or 0) if side_n else None,
            "entry_price": float(entry or 0) if side_n else None,
        }
    finally:
        con.close()


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("op", choices=["start", "stop", "session", "save", "status", "set_method", "set_position", "set_levels"])
    ap.add_argument("db_path")
    ap.add_argument("--payload", default=None, help="JSON file for start/save/set_position/set_levels")
    ap.add_argument("--session-id", type=int, default=None)
    ap.add_argument("--method", default=None)
    ap.add_argument("--resolution", default=None)
    ap.add_argument("--ticks", type=int, default=40)
    ap.add_argument("--trades", type=int, default=30)
    ns = ap.parse_args()

    try:
        if ns.op == "start":
            cfg = {}
            if ns.payload:
                cfg = json.loads(Path(ns.payload).read_text(encoding="utf-8"))
            print(json.dumps(start(ns.db_path, cfg), ensure_ascii=True))
            return 0
        if ns.op == "stop":
            print(json.dumps(stop(ns.db_path, ns.session_id), ensure_ascii=True))
            return 0
        if ns.op == "session":
            print(json.dumps({"ok": True, "session": get_session(ns.db_path, ns.session_id)}, ensure_ascii=True))
            return 0
        if ns.op == "save":
            if not ns.payload:
                print(json.dumps({"ok": False, "error": "payload required"}))
                return 1
            data = json.loads(Path(ns.payload).read_text(encoding="utf-8"))
            print(json.dumps(save_tick_bundle(ns.db_path, data), ensure_ascii=True))
            return 0
        if ns.op == "set_method":
            print(
                json.dumps(
                    set_lead(ns.db_path, ns.session_id, ns.method or "", getattr(ns, "resolution", None)),
                    ensure_ascii=True,
                )
            )
            return 0
        if ns.op == "set_levels":
            payload = {}
            if ns.payload:
                payload = json.loads(Path(ns.payload).read_text(encoding="utf-8"))
            print(
                json.dumps(
                    set_levels(
                        ns.db_path,
                        ns.session_id if ns.session_id is not None else payload.get("session_id"),
                        payload.get("tp_levels"),
                        payload.get("sl_levels"),
                        payload.get("tp_price"),
                        payload.get("sl_price"),
                        payload.get("lot_usd"),
                    ),
                    ensure_ascii=True,
                )
            )
            return 0
        if ns.op == "set_position":
            payload = {}
            if ns.payload:
                payload = json.loads(Path(ns.payload).read_text(encoding="utf-8"))
            print(
                json.dumps(
                    set_position(
                        ns.db_path,
                        ns.session_id if ns.session_id is not None else payload.get("session_id"),
                        payload.get("side"),
                        payload.get("size"),
                        payload.get("entry"),
                        payload.get("tp"),
                        payload.get("sl"),
                        str(payload.get("reason") or "adopt"),
                    ),
                    ensure_ascii=True,
                )
            )
            return 0
        if ns.op == "status":
            print(
                json.dumps(
                    status_bundle(ns.db_path, ns.session_id, ns.ticks, ns.trades),
                    ensure_ascii=True,
                )
            )
            return 0
    except Exception as e:  # noqa: BLE001
        print(json.dumps({"ok": False, "error": str(e)}))
        return 1
    return 1


if __name__ == "__main__":
    raise SystemExit(main())
