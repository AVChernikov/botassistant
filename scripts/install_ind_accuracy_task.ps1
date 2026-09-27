# Silent 3h indicator accuracy → Telegram (pythonw).
#   powershell -ExecutionPolicy Bypass -File scripts\install_ind_accuracy_task.ps1

$ErrorActionPreference = "Stop"
$Root = Split-Path -Parent $PSScriptRoot
$TaskName = "botassistant-ind-accuracy-3h"
$Mcp = Join-Path $Root "mcp-lighter"
$Pyw = Join-Path $Mcp ".venv\Scripts\pythonw.exe"
$Py = Join-Path $Mcp ".venv\Scripts\python.exe"
$Script = Join-Path $Mcp "cron_ind_accuracy_main.py"
$Exe = if (Test-Path $Pyw) { $Pyw } else { $Py }

if (-not (Test-Path $Exe)) { throw "Missing $Exe" }
if (-not (Test-Path $Script)) { throw "Missing $Script" }

Get-CimInstance Win32_Process -ErrorAction SilentlyContinue |
    Where-Object { $_.CommandLine -and $_.CommandLine -match "_lit_ind_acc_loop\.ps1" } |
    ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }

$action = New-ScheduledTaskAction -Execute $Exe -Argument "`"$Script`"" -WorkingDirectory $Mcp
$trigger = New-ScheduledTaskTrigger -Daily -At "00:00"
$trigger.Repetition = (New-ScheduledTaskTrigger -Once -At "00:00" -RepetitionInterval (New-TimeSpan -Hours 3) -RepetitionDuration (New-TimeSpan -Days 1)).Repetition
$settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Minutes 15) -Hidden
$principal = New-ScheduledTaskPrincipal -UserId $env:USERNAME -LogonType Interactive -RunLevel Limited

Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false -ErrorAction SilentlyContinue
Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger -Settings $settings -Principal $principal -Force | Out-Null
Start-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
Write-Host "Scheduled task '$TaskName' → silent pythonw"
Get-ScheduledTaskInfo -TaskName $TaskName | Format-List LastRunTime, NextRunTime, LastTaskResult
