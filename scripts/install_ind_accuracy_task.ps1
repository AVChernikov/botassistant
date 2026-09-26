# Register Windows Scheduled Task: indicator accuracy ranking every 3 hours.
# Replaces infinite loop _lit_ind_acc_loop.ps1
#
#   powershell -ExecutionPolicy Bypass -File scripts\install_ind_accuracy_task.ps1
#
# Remove:
#   Unregister-ScheduledTask -TaskName "botassistant-ind-accuracy-3h" -Confirm:$false

$ErrorActionPreference = "Stop"
$Root = Split-Path -Parent $PSScriptRoot
$TaskName = "botassistant-ind-accuracy-3h"
$Ps1 = Join-Path $Root "scripts\cron_ind_accuracy.ps1"

if (-not (Test-Path $Ps1)) {
    throw "Missing $Ps1"
}

# Kill legacy infinite accuracy loop
Get-CimInstance Win32_Process -ErrorAction SilentlyContinue |
    Where-Object { $_.CommandLine -and $_.CommandLine -match "_lit_ind_acc_loop\.ps1" } |
    ForEach-Object {
        Write-Host "Stopping legacy loop PID $($_.ProcessId)"
        Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue
    }

$action = New-ScheduledTaskAction `
    -Execute "powershell.exe" `
    -Argument "-NoProfile -NonInteractive -ExecutionPolicy Bypass -File `"$Ps1`"" `
    -WorkingDirectory $Root

# Daily + repeat every 3 hours for 24h
$trigger = New-ScheduledTaskTrigger -Daily -At "00:00"
$trigger.Repetition = (New-ScheduledTaskTrigger -Once -At "00:00" -RepetitionInterval (New-TimeSpan -Hours 3) -RepetitionDuration (New-TimeSpan -Days 1)).Repetition

$settings = New-ScheduledTaskSettingsSet `
    -AllowStartIfOnBatteries `
    -DontStopIfGoingOnBatteries `
    -StartWhenAvailable `
    -MultipleInstances IgnoreNew `
    -ExecutionTimeLimit (New-TimeSpan -Minutes 15)

$principal = New-ScheduledTaskPrincipal -UserId $env:USERNAME -LogonType Interactive -RunLevel Limited

Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false -ErrorAction SilentlyContinue
Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger -Settings $settings -Principal $principal -Force | Out-Null
Start-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue

# Control flags: no loop
$ctrlPath = Join-Path $Root "mcp-lighter\_tg_control.json"
if (Test-Path $ctrlPath) {
    try {
        $prev = Get-Content $ctrlPath -Raw -Encoding UTF8 | ConvertFrom-Json
        $ctrlObj = [ordered]@{}
        $prev.PSObject.Properties | ForEach-Object { $ctrlObj[$_.Name] = $_.Value }
        $ctrlObj["ind_accuracy_loop_stopped"] = $true
        $ctrlObj["ind_accuracy_cron_3h"] = $true
        $note = [string]$ctrlObj["pause_note"]
        if ($note -notmatch "ind.accuracy") {
            $ctrlObj["pause_note"] = ($note + "; ind accuracy 3h via schtasks (no loop)").TrimStart("; ")
        }
        $utf8NoBom = New-Object System.Text.UTF8Encoding $false
        [System.IO.File]::WriteAllText($ctrlPath, (($ctrlObj | ConvertTo-Json -Depth 6) + "`n"), $utf8NoBom)
    } catch {
        Write-Host "control update skipped: $_"
    }
}

Write-Host "Scheduled task '$TaskName' registered: every 3 hours"
Write-Host "Script: $Ps1 (runs _ind_accuracy_24h.py → Telegram)"
Get-ScheduledTask -TaskName $TaskName | Format-List TaskName, State
Get-ScheduledTaskInfo -TaskName $TaskName | Format-List LastRunTime, NextRunTime, LastTaskResult
