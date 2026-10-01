# One-shot: indicator ranking 24h+12h → Telegram (NO while-loop).
# Every 3 hours via Task Scheduler (replaces _lit_ind_acc_loop.ps1).

$ErrorActionPreference = "Continue"
$Root = Split-Path -Parent $PSScriptRoot
$Mcp = Join-Path $Root "mcp-lighter"
$Py = Join-Path $Mcp ".venv\Scripts\python.exe"
$Script = Join-Path $Mcp "_ind_accuracy_24h.py"

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
