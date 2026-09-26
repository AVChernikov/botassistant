# Register Windows Scheduled Task: full indicator pipeline every N minutes.
# snapshot → DeepSeek → report (cron_indicators.ps1 → indicators_pipeline_cli.php)
#
#   powershell -ExecutionPolicy Bypass -File scripts\install_indicators_pipeline_task.ps1

$ErrorActionPreference = "Stop"
$Root = Split-Path -Parent $PSScriptRoot
$TaskName = "botassistant-ind-pipeline"
$Ps1 = Join-Path $Root "scripts\cron_indicators.ps1"
$EnvFile = Join-Path $Root "config\indicators.env"

$intervalMin = 5
if (Test-Path $EnvFile) {
    Get-Content $EnvFile -Encoding UTF8 | ForEach-Object {
        if ($_ -match '^CRON_INTERVAL_SEC\s*=\s*(\d+)') {
            $sec = [int]$Matches[1]
            $intervalMin = [Math]::Max(5, [Math]::Ceiling($sec / 60.0))
        }
    }
}

$action = New-ScheduledTaskAction `
    -Execute "powershell.exe" `
    -Argument "-NoProfile -NonInteractive -ExecutionPolicy Bypass -File `"$Ps1`"" `
    -WorkingDirectory $Root

$trigger = New-ScheduledTaskTrigger -Daily -At "00:00"
$trigger.Repetition = (New-ScheduledTaskTrigger -Once -At "00:00" -RepetitionInterval (New-TimeSpan -Minutes $intervalMin) -RepetitionDuration (New-TimeSpan -Days 1)).Repetition

$settings = New-ScheduledTaskSettingsSet `
    -AllowStartIfOnBatteries `
    -DontStopIfGoingOnBatteries `
    -StartWhenAvailable `
    -MultipleInstances IgnoreNew `
    -ExecutionTimeLimit (New-TimeSpan -Minutes 10)

$principal = New-ScheduledTaskPrincipal -UserId $env:USERNAME -LogonType Interactive -RunLevel Limited

Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false -ErrorAction SilentlyContinue
Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger -Settings $settings -Principal $principal -Force | Out-Null

Write-Host "Scheduled task '$TaskName' every ${intervalMin}m (no loop)"
Get-ScheduledTask -TaskName $TaskName | Format-List TaskName, State
