#!/usr/bin/env python3
"""Call DeepSeek chat completions; payload file → result file + stdout JSON."""
from __future__ import annotations

import json
import sys
import time
import urllib.error
import urllib.request
from pathlib import Path

# Transient network / gateway failures worth retrying.
_RETRY_MARKERS = (
    "remote end closed connection",
    "connection reset",
    "connection aborted",
    "timed out",
    "temporarily unavailable",
    "broken pipe",
    "eof occurred",
    "ssl",
    "503",
    "502",
    "504",
    "429",
)


def load_env(path: Path) -> dict[str, str]:
    out: dict[str, str] = {}
    if not path.is_file():
        return out
    for line in path.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        k, v = line.split("=", 1)
        out[k.strip()] = v.strip()
    return out


def _transient(err: str) -> bool:
    low = err.lower()
    return any(m in low for m in _RETRY_MARKERS)


def _post(url: str, body: dict, key: str, timeout: float) -> dict:
    req = urllib.request.Request(
        url,
        data=json.dumps(body).encode("utf-8"),
        headers={
            "Authorization": f"Bearer {key}",
            "Content-Type": "application/json",
            "Accept": "application/json",
            "Connection": "close",
        },
        method="POST",
    )
    with urllib.request.urlopen(req, timeout=timeout) as resp:
        return json.loads(resp.read().decode("utf-8"))


def main() -> int:
    if len(sys.argv) < 2:
        print(json.dumps({"ok": False, "error": "payload required"}))
        return 1
    payload_path = Path(sys.argv[1])
    out_path = Path(sys.argv[2]) if len(sys.argv) > 2 else payload_path.with_suffix(".out.json")
    data = json.loads(payload_path.read_text(encoding="utf-8"))
    root = Path(__file__).resolve().parent
    env = load_env(root / ".env")
    key = (data.get("api_key") or env.get("DEEPSEEK_API_KEY") or "").strip()
    base = (data.get("base_url") or env.get("DEEPSEEK_BASE_URL") or "https://api.deepseek.com").rstrip("/")
    model = (data.get("model") or env.get("DEEPSEEK_MODEL") or "deepseek-v4-pro").strip()
    if not key:
        out_path.write_text(json.dumps({"ok": False, "error": "DEEPSEEK_API_KEY missing"}), encoding="utf-8")
        print(json.dumps({"ok": False, "error": "DEEPSEEK_API_KEY missing"}))
        return 1

    body: dict = {
        "model": model,
        "messages": data["messages"],
        "max_tokens": int(data.get("max_tokens") or 8000),
    }
    # Prefer final answer in content for report JSON (less token waste on reasoning)
    if data.get("thinking") is False or data.get("disable_thinking"):
        body["thinking"] = {"type": "disabled"}

    url = base + "/chat/completions"
    # Flash lead-pick is short; keep timeout moderate and retry on drop.
    timeout = float(data.get("timeout") or env.get("DEEPSEEK_TIMEOUT") or 60)
    retries = int(data.get("retries") or env.get("DEEPSEEK_RETRIES") or 3)
    retries = max(1, min(retries, 5))

    raw = None
    last_err = ""
    for attempt in range(1, retries + 1):
        try:
            raw = _post(url, body, key, timeout)
            break
        except urllib.error.HTTPError as e:
            err_body = e.read().decode("utf-8", errors="replace")[:800]
            last_err = f"HTTP {e.code}: {err_body}"
            if e.code in (429, 502, 503, 504) and attempt < retries:
                time.sleep(min(8.0, 0.8 * (2 ** (attempt - 1))))
                continue
            result = {"ok": False, "error": last_err, "attempts": attempt}
            out_path.write_text(json.dumps(result, ensure_ascii=False), encoding="utf-8")
            print(json.dumps(result, ensure_ascii=False))
            return 1
        except Exception as e:
            last_err = str(e)
            if _transient(last_err) and attempt < retries:
                time.sleep(min(8.0, 0.8 * (2 ** (attempt - 1))))
                continue
            result = {"ok": False, "error": last_err, "attempts": attempt}
            out_path.write_text(json.dumps(result, ensure_ascii=False), encoding="utf-8")
            print(json.dumps(result, ensure_ascii=False))
            return 1

    if raw is None:
        result = {"ok": False, "error": last_err or "empty response", "attempts": retries}
        out_path.write_text(json.dumps(result, ensure_ascii=False), encoding="utf-8")
        print(json.dumps(result, ensure_ascii=False))
        return 1

    msg = (raw.get("choices") or [{}])[0].get("message") or {}
    content = (msg.get("content") or "").strip()
    reasoning = msg.get("reasoning_content")
    if not content and isinstance(reasoning, str) and reasoning.strip():
        # fallback: model spent tokens on reasoning only
        content = reasoning.strip()
    result = {
        "ok": True,
        "content": content,
        "reasoning": reasoning,
        "model": raw.get("model") or model,
        "usage": raw.get("usage"),
    }
    out_path.write_text(json.dumps(result, ensure_ascii=False), encoding="utf-8")
    # short stdout status only (avoid exec line-splitting huge JSON)
    print(json.dumps({"ok": True, "out": str(out_path), "model": result["model"]}, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
