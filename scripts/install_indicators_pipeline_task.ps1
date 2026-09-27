# Silent indicators pipeline every N minutes (pythonw → php, CREATE_NO_WINDOW).
#   powershell -ExecutionPolicy Bypass -File scripts\install_indicators_pipeline_task.ps1

$ErrorActionPreference = "Stop"
$Root = Split-Path -Parent $PSScriptRoot
$TaskName = "botassistant-ind-pipeline"
$Mcp = Join-Path $Root "mcp-lighter"
$EnvFile = Join-Path $Root "config\indicators.env"
$Pyw = Join-Path $Mcp ".venv\Scripts\pythonw.exe"
$Py = Join-Path $Mcp ".venv\Scripts\python.exe"
$Script = Join-Path $Mcp "cron_indicators_main.py"
$Exe = if (Test-Path $Pyw) { $Pyw } else { $Py }

$intervalMin = 5
if (Test-Path $EnvFile) {
    Get-Content $EnvFile -Encoding UTF8 | ForEach-Object {
        if ($_ -match '^CRON_INTERVAL_SEC\s*=\s*(\d+)') {
            $sec = [int]$Matches[1]
            $intervalMin = [Math]::Max(5, [Math]::Ceiling($sec / 60.0))
        }
    }
}

if (-not (Test-Path $Exe)) { throw "Missing $Exe" }
if (-not (Test-Path $Script)) { throw "Missing $Script" }

$action = New-ScheduledTaskAction -Execute $Exe -Argument "`"$Script`"" -WorkingDirectory $Mcp
$trigger = New-ScheduledTaskTrigger -Daily -At "00:00"
$trigger.Repetition = (New-ScheduledTaskTrigger -Once -At "00:00" -RepetitionInterval (New-TimeSpan -Minutes $intervalMin) -RepetitionDuration (New-TimeSpan -Days 1)).Repetition
$settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Minutes 10) -Hidden
$principal = New-ScheduledTaskPrincipal -UserId $env:USERNAME -LogonType Interactive -RunLevel Limited

Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false -ErrorAction SilentlyContinue
Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger -Settings $settings -Principal $principal -Force | Out-Null
Write-Host "Scheduled task '$TaskName' every ${intervalMin}m → silent pythonw"
Get-ScheduledTask -TaskName $TaskName | Format-List TaskName, State
