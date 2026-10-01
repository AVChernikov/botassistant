#!/usr/bin/env python3
"""Silent 30m position-report cron (pythonw / CREATE_NO_WINDOW)."""
from __future__ import annotations

import subprocess
import sys
from pathlib import Path

from venv_py import executable

ROOT = Path(__file__).resolve().parent
CREATE_NO_WINDOW = 0x08000000


def main() -> int:
    exe = executable(ROOT)
    r = subprocess.run(
        [exe, str(ROOT / "telegram_notify.py"), "--position-report"],
        cwd=str(ROOT),
        creationflags=CREATE_NO_WINDOW if sys.platform == "win32" else 0,
    )
    return int(r.returncode)


if __name__ == "__main__":
    raise SystemExit(main())
