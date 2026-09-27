#!/usr/bin/env python3
"""Silent indicators pipeline cron: php scripts/indicators_pipeline_cli.php."""
from __future__ import annotations

import os
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent
REPO = ROOT.parent
CREATE_NO_WINDOW = 0x08000000


def _php_bin() -> str:
    env_file = REPO / "config" / "indicators.env"
    php = "php"
    if env_file.is_file():
        for line in env_file.read_text(encoding="utf-8").splitlines():
            line = line.strip()
            if not line or line.startswith("#") or line.startswith("@") or "=" not in line:
                continue
            k, v = line.split("=", 1)
            if k.strip() == "PHP_BIN" and v.strip():
                return v.strip()
    return php


def main() -> int:
    pipeline = REPO / "scripts" / "indicators_pipeline_cli.php"
    if not pipeline.is_file():
        print(f"missing {pipeline}", file=sys.stderr)
        return 2
    flags = CREATE_NO_WINDOW if sys.platform == "win32" else 0
    r = subprocess.run(
        [_php_bin(), str(pipeline)],
        cwd=str(REPO),
        creationflags=flags,
        env=os.environ.copy(),
    )
    return int(r.returncode)


if __name__ == "__main__":
    raise SystemExit(main())
