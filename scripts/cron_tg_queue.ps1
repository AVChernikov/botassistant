# One-shot Telegram queue check + agent tick brief (NO while-loop).
# Intended for Windows Task Scheduler every 2 minutes.
#
# Usage:
#   powershell -File scripts\cron_tg_queue.ps1
#   powershell -File scripts\install_tg_queue_task.ps1   # register schtasks

$ErrorActionPreference = "Continue"
$Root = Split-Path -Parent $PSScriptRoot
$Mcp = Join-Path $Root "mcp-lighter"
$Py = Join-Path $Mcp ".venv\Scripts\python.exe"
$QueueScript = Join-Path $Mcp "tg_queue_cron_once.py"
$BriefScript = Join-Path $Mcp "agent_tick_brief.py"
$TraderScript = Join-Path $Mcp "deepseek_trader_agent.py"

if (-not (Test-Path $Py)) {
    Write-Error "Missing $Py"
    exit 2
}
if (-not (Test-Path $QueueScript)) {
    Write-Error "Missing $QueueScript"
    exit 2
}

Set-Location $Mcp
& $Py $QueueScript
$queueCode = $LASTEXITCODE

# Brief for observability / kill-room / ROC (Cursor is observer only)
if (Test-Path $BriefScript) {
    & $Py $BriefScript
    if ($LASTEXITCODE -ne 0) {
        Write-Warning "agent_tick_brief.py exit $LASTEXITCODE"
    }
}

# Autonomous DeepSeek Pro trader (tools → Lighter + Telegram)
if (Test-Path $TraderScript) {
    & $Py $TraderScript
    if ($LASTEXITCODE -ne 0) {
        Write-Warning "deepseek_trader_agent.py exit $LASTEXITCODE"
    }
}

# Preserve queue semantics: 1 = has pending, 0 = empty, 2 = error
exit $queueCode
