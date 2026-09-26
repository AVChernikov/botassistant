# Register Windows Scheduled Task: TG queue poll every 2 minutes (no loop process).
# Run once as current user:
#   powershell -ExecutionPolicy Bypass -File scripts\install_tg_queue_task.ps1
#
# Remove:
#   Unregister-ScheduledTask -TaskName "botassistant-tg-queue-2m" -Confirm:$false

$ErrorActionPreference = "Stop"
$Root = Split-Path -Parent $PSScriptRoot
$TaskName = "botassistant-tg-queue-2m"
$Ps1 = Join-Path $Root "scripts\cron_tg_queue.ps1"

if (-not (Test-Path $Ps1)) {
    throw "Missing $Ps1"
}

# Stop legacy TG status loop processes if any (infinite while)
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

# Daily trigger + repeat every 2 minutes for 1 day (renews each midnight)
$trigger = New-ScheduledTaskTrigger -Daily -At "00:00"
$trigger.Repetition = (New-ScheduledTaskTrigger -Once -At "00:00" -RepetitionInterval (New-TimeSpan -Minutes 2) -RepetitionDuration (New-TimeSpan -Days 1)).Repetition

$settings = New-ScheduledTaskSettingsSet `
    -AllowStartIfOnBatteries `
    -DontStopIfGoingOnBatteries `
    -StartWhenAvailable `
    -MultipleInstances IgnoreNew `
    -ExecutionTimeLimit (New-TimeSpan -Minutes 2)

$principal = New-ScheduledTaskPrincipal -UserId $env:USERNAME -LogonType Interactive -RunLevel Limited

Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false -ErrorAction SilentlyContinue
Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger -Settings $settings -Principal $principal -Force | Out-Null

# Also start immediately once
Start-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue

# Mark control: loops abandoned, cron used
$ctrlPath = Join-Path $Root "mcp-lighter\_tg_control.json"
$ctrlObj = [ordered]@{
    paused                   = $true
    sma_paused               = $true
    roc_paused               = $true
    ticks_stopped            = $true
    telegram_loops_stopped   = $true
    telegram_cron_2m         = $true
    pause_note               = "TG queue via schtasks every 2m (no loop)"
}
if (Test-Path $ctrlPath) {
    try {
        $prev = Get-Content $ctrlPath -Raw -Encoding UTF8 | ConvertFrom-Json
        if ($null -ne $prev.paused) { $ctrlObj.paused = [bool]$prev.paused }
        if ($null -ne $prev.sma_paused) { $ctrlObj.sma_paused = [bool]$prev.sma_paused }
        if ($null -ne $prev.roc_paused) { $ctrlObj.roc_paused = [bool]$prev.roc_paused }
        if ($null -ne $prev.ticks_stopped) { $ctrlObj.ticks_stopped = [bool]$prev.ticks_stopped }
    } catch {}
}
$utf8NoBom = New-Object System.Text.UTF8Encoding $false
[System.IO.File]::WriteAllText($ctrlPath, (($ctrlObj | ConvertTo-Json -Depth 6) + "`n"), $utf8NoBom)

Write-Host "Scheduled task '$TaskName' registered: every 2 minutes"
Write-Host "Script: $Ps1"
Write-Host "Inbox:  $(Join-Path $Root 'mcp-lighter\_tg_agent_inbox.json')"
Write-Host "Test now: powershell -File `"$Ps1`""
Get-ScheduledTask -TaskName $TaskName | Format-List TaskName, State
