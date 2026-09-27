"""MySQL/MariaDB connector with sqlite3-ish API (?, lastrowid, Row)."""
from __future__ import annotations

import os
import re
from pathlib import Path
from typing import Any, Iterable, Optional, Sequence, Union
from urllib.parse import unquote, urlparse

import pymysql
from pymysql.connections import Connection as PyMySQLConnection
from pymysql.cursors import Cursor as PyMySQLCursor

ROOT = Path(__file__).resolve().parent.parent
DEFAULT_DSN = "mysql://bot:botassistant@127.0.0.1:3306/botassistant"

SCHEMA_SQL = """
CREATE TABLE IF NOT EXISTS indicator_snapshots (
    id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    market_id INT NOT NULL,
    symbol VARCHAR(64) NULL,
    resolution VARCHAR(16) NOT NULL,
    bar_ts BIGINT NOT NULL,
    indicator VARCHAR(128) NOT NULL,
    value DOUBLE NULL,
    `signal` INT NULL,
    close DOUBLE NULL,
    created_at BIGINT NOT NULL,
    UNIQUE KEY uq_ind_snap (market_id, resolution, bar_ts, indicator),
    KEY idx_ind_snap_lookup (market_id, resolution, indicator, bar_ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS indicator_stats (
    id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    market_id INT NOT NULL,
    symbol VARCHAR(64) NULL,
    resolution VARCHAR(16) NOT NULL,
    indicator VARCHAR(128) NOT NULL,
    bar_ts BIGINT NULL,
    strategy_return_pct DOUBLE NULL,
    profit_factor DOUBLE NULL,
    accuracy DOUBLE NULL,
    signals INT NULL,
    wins INT NULL,
    losses INT NULL,
    last_signal INT NULL,
    last_value DOUBLE NULL,
    created_at BIGINT NOT NULL,
    UNIQUE KEY uq_ind_stats (market_id, resolution, indicator),
    KEY idx_ind_stats_lookup (market_id, resolution, strategy_return_pct)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS indicator_reports (
    id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    market_id INT NULL,
    symbol VARCHAR(64) NULL,
    model VARCHAR(128) NULL,
    title TEXT NULL,
    summary MEDIUMTEXT NULL,
    findings_json MEDIUMTEXT NULL,
    compact_bytes INT NULL,
    raw_response MEDIUMTEXT NULL,
    created_at BIGINT NOT NULL,
    KEY idx_ind_reports_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS deepseek_queries (
    id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    purpose VARCHAR(64) NOT NULL DEFAULT 'custom',
    model VARCHAR(128) NULL,
    market_id INT NULL,
    report_id BIGINT NULL,
    parent_query_id BIGINT NULL,
    prompt_text MEDIUMTEXT NULL,
    messages_json MEDIUMTEXT NULL,
    response_text MEDIUMTEXT NULL,
    reasoning_text MEDIUMTEXT NULL,
    usage_json TEXT NULL,
    prompt_tokens INT NULL,
    completion_tokens INT NULL,
    total_tokens INT NULL,
    duration_ms INT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'ok',
    error_text TEXT NULL,
    meta_json TEXT NULL,
    created_at BIGINT NOT NULL,
    KEY idx_ds_queries_created (created_at),
    KEY idx_ds_queries_purpose (purpose, created_at),
    KEY idx_ds_queries_report (report_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS agent_checkpoints (
    id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    label VARCHAR(128) NULL,
    reason VARCHAR(64) NOT NULL DEFAULT 'auto',
    conversation_id VARCHAR(64) NULL,
    market_id INT NULL DEFAULT 120,
    side VARCHAR(16) NULL,
    session_pnl DOUBLE NULL,
    line VARCHAR(255) NULL,
    payload_json MEDIUMTEXT NOT NULL,
    handoff_text MEDIUMTEXT NULL,
    created_at BIGINT NOT NULL,
    KEY idx_agent_ckpt_created (created_at),
    KEY idx_agent_ckpt_reason (reason, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS agent_prompts (
    id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(128) NULL,
    role VARCHAR(32) NOT NULL DEFAULT 'system',
    body MEDIUMTEXT NOT NULL,
    active TINYINT NOT NULL DEFAULT 1,
    created_at BIGINT NOT NULL,
    KEY idx_agent_prompts_active (active, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tg_messages (
    id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    chat_id VARCHAR(64) NULL,
    direction VARCHAR(8) NOT NULL,
    role VARCHAR(16) NOT NULL,
    text MEDIUMTEXT NOT NULL,
    tg_message_id BIGINT NULL,
    source VARCHAR(64) NULL,
    created_at BIGINT NOT NULL,
    KEY idx_tg_messages_created (created_at),
    KEY idx_tg_messages_chat (chat_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
"""

_SIGNAL_RE = re.compile(r"(?<![`\w])signal(?![`\w])", re.IGNORECASE)


class Row:
    """sqlite3.Row-compatible: index + key access, works with dict()."""

    __slots__ = ("_keys", "_values", "_map")

    def __init__(self, keys: Sequence[str], values: Sequence[Any]):
        self._keys = list(keys)
        self._values = list(values)
        self._map = dict(zip(self._keys, self._values))

    def __getitem__(self, key: Union[int, str]) -> Any:
        if isinstance(key, int):
            return self._values[key]
        return self._map[key]

    def keys(self) -> list[str]:
        return list(self._keys)

    def __iter__(self):
        return iter(self._keys)

    def __len__(self) -> int:
        return len(self._keys)


class Cursor:
    def __init__(self, cur: PyMySQLCursor):
        self._cur = cur
        self.lastrowid = cur.lastrowid
        self.rowcount = cur.rowcount
        self.description = cur.description

    def fetchone(self) -> Optional[Row]:
        row = self._cur.fetchone()
        if row is None:
            return None
        keys = [d[0] for d in (self._cur.description or [])]
        if isinstance(row, dict):
            keys = list(row.keys())
            return Row(keys, list(row.values()))
        return Row(keys, row)

    def fetchall(self) -> list[Row]:
        rows = self._cur.fetchall()
        keys = [d[0] for d in (self._cur.description or [])]
        out: list[Row] = []
        for row in rows:
            if isinstance(row, dict):
                out.append(Row(list(row.keys()), list(row.values())))
            else:
                out.append(Row(keys, row))
        return out

    def __iter__(self):
        return iter(self.fetchall())

    def execute(self, sql: str, args: Optional[Iterable[Any]] = None) -> "Cursor":
        self._cur.execute(_adapt_sql(sql), tuple(args) if args is not None else None)
        self.lastrowid = self._cur.lastrowid
        self.rowcount = self._cur.rowcount
        self.description = self._cur.description
        return self


class Connection:
    def __init__(self, raw: PyMySQLConnection):
        self._raw = raw
        self.row_factory = None  # accepted for sqlite compat; always Row

    def execute(self, sql: str, args: Optional[Iterable[Any]] = None) -> Cursor:
        cur = self._raw.cursor()
        cur.execute(_adapt_sql(sql), tuple(args) if args is not None else None)
        return Cursor(cur)

    def executescript(self, script: str) -> None:
        for stmt in _split_statements(script):
            with self._raw.cursor() as cur:
                cur.execute(stmt)
        self._raw.commit()

    def commit(self) -> None:
        self._raw.commit()

    def close(self) -> None:
        self._raw.close()

    def cursor(self) -> Cursor:
        return Cursor(self._raw.cursor())


def _adapt_sql(sql: str) -> str:
    sql = _SIGNAL_RE.sub("`signal`", sql)
    # SQLite substr → MySQL SUBSTR (same); keep as-is
    return sql.replace("?", "%s")


def _split_statements(script: str) -> list[str]:
    parts: list[str] = []
    buf: list[str] = []
    for line in script.splitlines():
        s = line.strip()
        if not s or s.startswith("--"):
            continue
        buf.append(line)
        if s.endswith(";"):
            stmt = "\n".join(buf).strip().rstrip(";").strip()
            if stmt:
                parts.append(stmt)
            buf = []
    tail = "\n".join(buf).strip().rstrip(";").strip()
    if tail:
        parts.append(tail)
    return parts


def is_mysql_dsn(value: str) -> bool:
    v = (value or "").strip().lower()
    return v.startswith("mysql://") or v.startswith("mysql:") or v == "mysql"


def resolve_dsn(db_path: Optional[str] = None) -> str:
    if db_path and is_mysql_dsn(db_path):
        if db_path.strip().lower() in ("mysql", "mysql:"):
            return _dsn_from_env()
        return db_path.strip()
    env = os.environ.get("DB_DSN") or os.environ.get("MYSQL_DSN")
    if env:
        return env.strip()
    cfg = _load_indicators_env()
    if cfg.get("DB_DSN"):
        return cfg["DB_DSN"]
    if cfg.get("DB_HOST") or cfg.get("DB_NAME"):
        return _dsn_from_parts(cfg)
    if db_path and not str(db_path).lower().endswith(".sqlite"):
        # treat bare host-less token as "use default mysql"
        if str(db_path).strip().lower() in ("mysql", "mariadb"):
            return DEFAULT_DSN
    return DEFAULT_DSN


def _dsn_from_env() -> str:
    cfg = _load_indicators_env()
    if cfg.get("DB_DSN"):
        return cfg["DB_DSN"]
    if cfg.get("DB_HOST") or cfg.get("DB_NAME"):
        return _dsn_from_parts(cfg)
    return DEFAULT_DSN


def _dsn_from_parts(cfg: dict[str, str]) -> str:
    user = cfg.get("DB_USER", "bot")
    password = cfg.get("DB_PASS", cfg.get("DB_PASSWORD", "botassistant"))
    host = cfg.get("DB_HOST", "127.0.0.1")
    port = cfg.get("DB_PORT", "3306")
    name = cfg.get("DB_NAME", "botassistant")
    return f"mysql://{user}:{password}@{host}:{port}/{name}"


def _load_indicators_env() -> dict[str, str]:
    path = ROOT / "config" / "indicators.env"
    out: dict[str, str] = {}
    if not path.is_file():
        return out
    for line in path.read_text(encoding="utf-8-sig").splitlines():
        trim = line.strip()
        if not trim or trim.startswith("#") or "=" not in trim:
            continue
        if trim.lower().startswith("@indicators"):
            break
        k, v = trim.split("=", 1)
        out[k.strip()] = v.strip()
    return out


def parse_dsn(dsn: str) -> dict[str, Any]:
    # mysql://user:pass@host:port/db
    u = urlparse(dsn)
    if u.scheme not in ("mysql", "mariadb"):
        raise ValueError(f"unsupported dsn scheme: {u.scheme}")
    return {
        "host": u.hostname or "127.0.0.1",
        "port": int(u.port or 3306),
        "user": unquote(u.username or "bot"),
        "password": unquote(u.password or ""),
        "database": (u.path or "/botassistant").lstrip("/") or "botassistant",
        "charset": "utf8mb4",
        "autocommit": False,
    }


def connect(db_path: Optional[str] = None, ensure_schema: bool = True) -> Connection:
    dsn = resolve_dsn(db_path)
    params = parse_dsn(dsn)
    raw = pymysql.connect(**params)
    con = Connection(raw)
    if ensure_schema:
        con.executescript(SCHEMA_SQL)
    return con


def db_exists(_db_path: Optional[str] = None) -> bool:
    """Always True for MySQL once server is reachable; used instead of Path.is_file()."""
    try:
        con = connect(_db_path, ensure_schema=False)
        con.close()
        return True
    except Exception:
        return False
