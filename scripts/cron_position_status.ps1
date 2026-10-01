# One-shot: send position status to Telegram (NO while-loop).
# Every 30 minutes via Task Scheduler.
#
# Line: LIT $200 long +1.50 / sess -22.8 | ROC(10) 30m

$ErrorActionPreference = "Continue"
$Root = Split-Path -Parent $PSScriptRoot
$Mcp = Join-Path $Root "mcp-lighter"
$Py = Join-Path $Mcp ".venv\Scripts\python.exe"

if (-not (Test-Path $Py)) {
    Write-Error "Missing $Py"
    exit 2
}

Set-Location $Mcp
& $Py ".\telegram_notify.py" "--position-report"
exit $LASTEXITCODE
