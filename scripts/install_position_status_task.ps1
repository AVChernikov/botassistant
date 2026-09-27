# Silent 30m position status → Telegram (pythonw).
#   powershell -ExecutionPolicy Bypass -File scripts\install_position_status_task.ps1

$ErrorActionPreference = "Stop"
$Root = Split-Path -Parent $PSScriptRoot
$TaskName = "botassistant-pos-status-30m"
$Mcp = Join-Path $Root "mcp-lighter"
$Pyw = Join-Path $Mcp ".venv\Scripts\pythonw.exe"
$Py = Join-Path $Mcp ".venv\Scripts\python.exe"
$Script = Join-Path $Mcp "cron_pos_status_main.py"
$Exe = if (Test-Path $Pyw) { $Pyw } else { $Py }

if (-not (Test-Path $Exe)) { throw "Missing $Exe" }
if (-not (Test-Path $Script)) { throw "Missing $Script" }

$action = New-ScheduledTaskAction -Execute $Exe -Argument "`"$Script`"" -WorkingDirectory $Mcp
$trigger = New-ScheduledTaskTrigger -Daily -At "00:00"
$trigger.Repetition = (New-ScheduledTaskTrigger -Once -At "00:00" -RepetitionInterval (New-TimeSpan -Minutes 30) -RepetitionDuration (New-TimeSpan -Days 1)).Repetition
$settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Minutes 5) -Hidden
$principal = New-ScheduledTaskPrincipal -UserId $env:USERNAME -LogonType Interactive -RunLevel Limited

Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false -ErrorAction SilentlyContinue
Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger -Settings $settings -Principal $principal -Force | Out-Null
Start-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
Write-Host "Scheduled task '$TaskName' → silent pythonw"
Get-ScheduledTaskInfo -TaskName $TaskName | Format-List LastRunTime, NextRunTime, LastTaskResult
