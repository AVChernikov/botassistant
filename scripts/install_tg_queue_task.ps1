# Register silent 2m cron: pythonw → cron_tick_main.py (no console flash).
#   powershell -ExecutionPolicy Bypass -File scripts\install_tg_queue_task.ps1

$ErrorActionPreference = "Stop"
$Root = Split-Path -Parent $PSScriptRoot
$TaskName = "botassistant-tg-queue-2m"
$Mcp = Join-Path $Root "mcp-lighter"
$Pyw = Join-Path $Mcp ".venv\Scripts\pythonw.exe"
$Py = Join-Path $Mcp ".venv\Scripts\python.exe"
$Script = Join-Path $Mcp "cron_tick_main.py"
$Exe = if (Test-Path $Pyw) { $Pyw } else { $Py }

if (-not (Test-Path $Exe)) { throw "Missing $Exe" }
if (-not (Test-Path $Script)) { throw "Missing $Script" }

Get-CimInstance Win32_Process -ErrorAction SilentlyContinue |
    Where-Object { $_.CommandLine -and $_.CommandLine -match "_lit_tg_status_loop\.ps1" } |
    ForEach-Object {
        Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue
    }

$action = New-ScheduledTaskAction `
    -Execute $Exe `
    -Argument "`"$Script`"" `
    -WorkingDirectory $Mcp

$trigger = New-ScheduledTaskTrigger -Daily -At "00:00"
$trigger.Repetition = (New-ScheduledTaskTrigger -Once -At "00:00" -RepetitionInterval (New-TimeSpan -Minutes 2) -RepetitionDuration (New-TimeSpan -Days 1)).Repetition

$settings = New-ScheduledTaskSettingsSet `
    -AllowStartIfOnBatteries `
    -DontStopIfGoingOnBatteries `
    -StartWhenAvailable `
    -MultipleInstances IgnoreNew `
    -ExecutionTimeLimit (New-TimeSpan -Minutes 3) `
    -Hidden

$principal = New-ScheduledTaskPrincipal -UserId $env:USERNAME -LogonType Interactive -RunLevel Limited

Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false -ErrorAction SilentlyContinue
Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger -Settings $settings -Principal $principal -Force | Out-Null
Start-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue

# Preserve control flags (do not force pause)
$ctrlPath = Join-Path $Mcp "_tg_control.json"
if (Test-Path $ctrlPath) {
    try {
        $prev = Get-Content $ctrlPath -Raw -Encoding UTF8 | ConvertFrom-Json
        $ctrlObj = [ordered]@{}
        $prev.PSObject.Properties | ForEach-Object { $ctrlObj[$_.Name] = $_.Value }
        $ctrlObj["telegram_cron_2m"] = $true
        $ctrlObj["telegram_loops_stopped"] = $true
        $utf8NoBom = New-Object System.Text.UTF8Encoding $false
        [System.IO.File]::WriteAllText($ctrlPath, (($ctrlObj | ConvertTo-Json -Depth 6) + "`n"), $utf8NoBom)
    } catch {}
}

Write-Host "Scheduled task '$TaskName' → $Exe $Script (silent pythonw)"
Get-ScheduledTask -TaskName $TaskName | Format-List TaskName, State
