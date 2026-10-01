# Single-instance Telegram bridge (avoid Windows venv launcher double-poll).
$ErrorActionPreference = "Stop"
$Root = Split-Path -Parent $MyInvocation.MyCommand.Path
$Venv = Join-Path $Root ".venv"
$Site = Join-Path $Venv "Lib\site-packages"
$Scripts = Join-Path $Venv "Scripts"
$Cfg = Join-Path $Venv "pyvenv.cfg"
$HomePy = "C:\Users\teams\AppData\Local\Programs\Python\Python312\python.exe"
if (Test-Path $Cfg) {
    $line = (Get-Content $Cfg | Where-Object { $_ -match '^\s*home\s*=' } | Select-Object -First 1)
    if ($line) {
        $homeDir = ($line -split '=', 2)[1].Trim()
        $candidate = Join-Path $homeDir "python.exe"
        if (Test-Path $candidate) { $HomePy = $candidate }
    }
}

Get-CimInstance Win32_Process -Filter "name = 'python.exe'" |
    Where-Object { $_.CommandLine -match 'telegram_bot\.py' } |
    ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }

Start-Sleep -Seconds 1
$env:VIRTUAL_ENV = $Venv
$env:PATH = "$Scripts;$env:PATH"
$env:PYTHONPATH = $Site
Start-Process -FilePath $HomePy -ArgumentList @('-u', 'telegram_bot.py') -WorkingDirectory $Root -WindowStyle Hidden
Start-Sleep -Seconds 2
Get-CimInstance Win32_Process -Filter "name = 'python.exe'" |
    Where-Object { $_.CommandLine -match 'telegram_bot\.py' } |
    ForEach-Object { Write-Host "running pid=$($_.ProcessId) $($_.CommandLine)" }
