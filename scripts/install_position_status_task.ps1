# Register Windows Scheduled Task: position status → Telegram every 30 minutes.
#   powershell -ExecutionPolicy Bypass -File scripts\install_position_status_task.ps1
#
# Remove:
#   Unregister-ScheduledTask -TaskName "botassistant-pos-status-30m" -Confirm:$false

$ErrorActionPreference = "Stop"
$Root = Split-Path -Parent $PSScriptRoot
$TaskName = "botassistant-pos-status-30m"
$Ps1 = Join-Path $Root "scripts\cron_position_status.ps1"

if (-not (Test-Path $Ps1)) {
    throw "Missing $Ps1"
}

# Stop legacy infinite TG status loop if still running
Get-CimInstance Win32_Process -ErrorAction SilentlyContinue |
    Where-Object { $_.CommandLine -and $_.CommandLine -match "_lit_tg_status_loop\.ps1" } |
    ForEach-Object {
        Write-Host "Stopping legacy loop PID $($_.ProcessId)"
        Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue
    }

$action = New-ScheduledTaskAction `
    -Execute "powershell.exe" `
    -Argument "-NoProfile -NonInteractive -ExecutionPolicy Bypass -File `"$Ps1`"" `
    -WorkingDirectory $Root

$trigger = New-ScheduledTaskTrigger -Daily -At "00:00"
$trigger.Repetition = (New-ScheduledTaskTrigger -Once -At "00:00" -RepetitionInterval (New-TimeSpan -Minutes 30) -RepetitionDuration (New-TimeSpan -Days 1)).Repetition

$settings = New-ScheduledTaskSettingsSet `
    -AllowStartIfOnBatteries `
    -DontStopIfGoingOnBatteries `
    -StartWhenAvailable `
    -MultipleInstances IgnoreNew `
    -ExecutionTimeLimit (New-TimeSpan -Minutes 5)

$principal = New-ScheduledTaskPrincipal -UserId $env:USERNAME -LogonType Interactive -RunLevel Limited

Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false -ErrorAction SilentlyContinue
Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger -Settings $settings -Principal $principal -Force | Out-Null
Start-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue

Write-Host "Scheduled task '$TaskName' registered: every 30 minutes"
Write-Host "Script: $Ps1"
Write-Host "Format: LIT `$VOL long|short POS_PNL / sess SESS_PNL | INDICATOR"
Get-ScheduledTask -TaskName $TaskName | Format-List TaskName, State
Get-ScheduledTaskInfo -TaskName $TaskName | Format-List LastRunTime, NextRunTime, LastTaskResult
