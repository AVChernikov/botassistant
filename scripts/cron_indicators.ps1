# One-shot full indicator pipeline (NO while-loop).
# snapshot → DeepSeek → report
#
# Schedule: botassistant-ind-pipeline (Task Scheduler)
# Usage: powershell -File scripts\cron_indicators.ps1

$ErrorActionPreference = "Continue"
$Root = Split-Path -Parent $PSScriptRoot
$EnvFile = Join-Path $Root "config\indicators.env"
$Php = "php"
$Pipeline = Join-Path $Root "scripts\indicators_pipeline_cli.php"

if (Test-Path $EnvFile) {
    Get-Content -Path $EnvFile -Encoding UTF8 | ForEach-Object {
        $line = $_.Trim()
        if ($line -eq "" -or $line.StartsWith("#") -or $line -match "^@indicators") { return }
        $i = $line.IndexOf("=")
        if ($i -gt 0) {
            $k = $line.Substring(0, $i).Trim()
            $v = $line.Substring($i + 1).Trim()
            if ($k -eq "PHP_BIN" -and $v -ne "") { $Php = $v }
        }
    }
}

if (-not (Test-Path $Pipeline)) {
    Write-Error "Missing $Pipeline"
    exit 2
}

Write-Host ("[{0:u}] indicators pipeline once" -f (Get-Date).ToUniversalTime())
& $Php $Pipeline
exit $LASTEXITCODE
