"""Resolve venv Python on Linux (bin/python) or Windows (Scripts/python.exe)."""
from __future__ import annotations

import sys
from pathlib import Path


def executable(root: Path | None = None) -> str:
    root = root or Path(__file__).resolve().parent
    unix = root / ".venv" / "bin" / "python"
    win = root / ".venv" / "Scripts" / "python.exe"
    if unix.is_file():
        return str(unix)
    if win.is_file():
        return str(win)
    return sys.executable
