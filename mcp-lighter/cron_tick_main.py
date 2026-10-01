#!/usr/bin/env python3
"""Silent 2m cron entry (prefer pythonw.exe — no console window).

Runs: tg_queue_cron_once → agent_tick_brief → deepseek_trader_agent
"""
from __future__ import annotations

import subprocess
import sys
from pathlib import Path

from venv_py import executable

ROOT = Path(__file__).resolve().parent
CREATE_NO_WINDOW = 0x08000000


def _run(script: str) -> int:
    exe = executable(ROOT)
    # Always use console python for child but hide window via CREATE_NO_WINDOW
    r = subprocess.run(
        [exe, str(ROOT / script)],
        cwd=str(ROOT),
        creationflags=CREATE_NO_WINDOW if sys.platform == "win32" else 0,
    )
    return int(r.returncode)


def main() -> int:
    queue_code = _run("tg_queue_cron_once.py")
    _run("agent_tick_brief.py")
    trader = ROOT / "deepseek_trader_agent.py"
    if trader.is_file():
        _run("deepseek_trader_agent.py")
    return queue_code


if __name__ == "__main__":
    raise SystemExit(main())
