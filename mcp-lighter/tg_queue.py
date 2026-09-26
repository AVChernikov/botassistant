"""Telegram → agent command queue.

Files:
  _tg_queue.json     pending/done commands
  _tg_control.json   pause flags (fast path from bot)

CLI:
  python tg_queue.py --pending
  python tg_queue.py --ack 3 --result "ok"
  python tg_queue.py --enqueue "стоп"
  python tg_queue.py --control
"""

from __future__ import annotations

import argparse
import json
import time
import uuid
from pathlib import Path
from typing import Any, Optional

ROOT = Path(__file__).resolve().parent
QUEUE_PATH = ROOT / "_tg_queue.json"
CONTROL_PATH = ROOT / "_tg_control.json"

DEFAULT_CONTROL = {"paused": False, "sma_paused": False, "roc_paused": False}


def _read_json(path: Path, default: Any) -> Any:
    if not path.exists():
        return default
    return json.loads(path.read_text(encoding="utf-8-sig"))


def _write_json(path: Path, data: Any) -> None:
    path.write_text(json.dumps(data, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")


def load_control() -> dict[str, Any]:
    data = _read_json(CONTROL_PATH, dict(DEFAULT_CONTROL))
    for k, v in DEFAULT_CONTROL.items():
        data.setdefault(k, v)
    return data


def save_control(data: dict[str, Any]) -> None:
    _write_json(CONTROL_PATH, data)


def load_queue() -> dict[str, Any]:
    data = _read_json(QUEUE_PATH, {"next_id": 1, "items": []})
    data.setdefault("next_id", 1)
    data.setdefault("items", [])
    return data


def save_queue(data: dict[str, Any]) -> None:
    _write_json(QUEUE_PATH, data)


def normalize_command(text: str) -> str:
    t = (text or "").strip()
    if t.startswith("/"):
        t = t[1:]
    # drop @botname
    if t.startswith("status@"):
        t = "status"
    parts = t.split()
    if parts and "@" in parts[0]:
        parts[0] = parts[0].split("@", 1)[0]
        t = " ".join(parts)
    return t.strip().lower()


def apply_fast_control(text: str) -> Optional[str]:
    """Update pause flags immediately. Return ack line or None if not a control cmd."""
    cmd = normalize_command(text)
    ctrl = load_control()
    mapping = {
        "stop": ("paused", True, "all paused"),
        "стоп": ("paused", True, "all paused"),
        "pause": ("paused", True, "all paused"),
        "start": ("paused", False, "all resumed"),
        "старт": ("paused", False, "all resumed"),
        "продолжить": ("paused", False, "all resumed"),
        "resume": ("paused", False, "all resumed"),
        "stop sma": ("sma_paused", True, "SMA paused"),
        "стоп sma": ("sma_paused", True, "SMA paused"),
        "pause sma": ("sma_paused", True, "SMA paused"),
        "start sma": ("sma_paused", False, "SMA resumed"),
        "старт sma": ("sma_paused", False, "SMA resumed"),
        "stop roc": ("roc_paused", True, "ROC paused"),
        "стоп roc": ("roc_paused", True, "ROC paused"),
        "pause roc": ("roc_paused", True, "ROC paused"),
        "start roc": ("roc_paused", False, "ROC resumed"),
        "старт roc": ("roc_paused", False, "ROC resumed"),
    }
    if cmd not in mapping:
        return None
    key, val, label = mapping[cmd]
    if key == "paused":
        ctrl["paused"] = val
        if not val:
            ctrl["sma_paused"] = False
            ctrl["roc_paused"] = False
    else:
        ctrl[key] = val
    save_control(ctrl)
    return f"control: {label}"


def enqueue(text: str, *, source: str = "telegram") -> dict[str, Any]:
    q = load_queue()
    item = {
        "id": q["next_id"],
        "uuid": str(uuid.uuid4()),
        "ts": int(time.time()),
        "text": text.strip(),
        "norm": normalize_command(text),
        "source": source,
        "status": "pending",
        "result": None,
    }
    q["next_id"] = int(q["next_id"]) + 1
    q["items"].append(item)
    # keep last 200
    if len(q["items"]) > 200:
        q["items"] = q["items"][-200:]
    save_queue(q)
    fast = apply_fast_control(text)
    if fast:
        item["fast"] = fast
    return item


def pending_items() -> list[dict[str, Any]]:
    q = load_queue()
    return [i for i in q["items"] if i.get("status") == "pending"]


def ack(item_id: int, result: str = "ok", status: str = "done") -> Optional[dict[str, Any]]:
    q = load_queue()
    for item in q["items"]:
        if int(item.get("id")) == int(item_id):
            item["status"] = status
            item["result"] = result
            item["acked_ts"] = int(time.time())
            save_queue(q)
            return item
    return None


def strategy_paused(kind: str) -> bool:
    ctrl = load_control()
    if ctrl.get("paused"):
        return True
    if kind == "sma" and ctrl.get("sma_paused"):
        return True
    if kind == "roc" and ctrl.get("roc_paused"):
        return True
    return False


def main() -> int:
    p = argparse.ArgumentParser()
    p.add_argument("--pending", action="store_true")
    p.add_argument("--enqueue", type=str, default=None)
    p.add_argument("--ack", type=int, default=None)
    p.add_argument("--result", type=str, default="ok")
    p.add_argument("--control", action="store_true")
    p.add_argument("--paused", choices=("sma", "roc"), help="exit 0 if paused, 1 if not")
    args = p.parse_args()

    if args.control:
        print(json.dumps(load_control(), ensure_ascii=False))
        return 0
    if args.paused:
        return 0 if strategy_paused(args.paused) else 1
    if args.enqueue is not None:
        item = enqueue(args.enqueue)
        print(json.dumps(item, ensure_ascii=False))
        return 0
    if args.ack is not None:
        item = ack(args.ack, result=args.result)
        print(json.dumps(item, ensure_ascii=False) if item else "not found")
        return 0 if item else 2
    if args.pending:
        print(json.dumps(pending_items(), ensure_ascii=False, indent=2))
        return 0
    p.print_help()
    return 2


if __name__ == "__main__":
    raise SystemExit(main())
