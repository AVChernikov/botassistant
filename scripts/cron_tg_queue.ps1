# One-shot Telegram queue check (NO while-loop).
# Intended for Windows Task Scheduler every 2 minutes.
#
# Usage:
#   powershell -File scripts\cron_tg_queue.ps1
#   powershell -File scripts\install_tg_queue_task.ps1   # register schtasks

$ErrorActionPreference = "Continue"
$Root = Split-Path -Parent $PSScriptRoot
$Mcp = Join-Path $Root "mcp-lighter"
$Py = Join-Path $Mcp ".venv\Scripts\python.exe"
$Script = Join-Path $Mcp "tg_queue_cron_once.py"

if (-not (Test-Path $Py)) {
    Write-Error "Missing $Py"
    exit 2
}
if (-not (Test-Path $Script)) {
    Write-Error "Missing $Script"
    exit 2
}

Set-Location $Mcp
& $Py $Script
exit $LASTEXITCODE
