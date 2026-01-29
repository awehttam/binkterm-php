@echo off
setlocal
set ROOT=%~dp0..

powershell -NoProfile -ExecutionPolicy Bypass -Command ^
  "$ErrorActionPreference = 'Stop';" ^
  "$root = Resolve-Path '%ROOT%';" ^
  "$php = if ($env:PHP_BIN) { $env:PHP_BIN } else { 'php' };" ^
  "$procs = @();" ^
  "function Start-Daemon($script,$name) { $p = Start-Process -FilePath $php -ArgumentList (Join-Path $root $script) -WorkingDirectory $root -PassThru -WindowStyle Normal; $script:procs += $p; Write-Host ('Started ' + $name + ' PID ' + $p.Id); }" ^
  "Start-Daemon 'scripts/admin_daemon.php' 'admin_daemon';" ^
  "Start-Daemon 'scripts/binkp_scheduler.php' 'binkp_scheduler';" ^
  "Start-Daemon 'scripts/binkp_server.php' 'binkp_server';" ^
  "Write-Host 'Press Ctrl+C to stop daemons.';" ^
  "$handler = { param($sender,$e) $e.Cancel = $true; Write-Host 'Stopping daemons...'; foreach ($p in $procs) { try { Stop-Process -Id $p.Id -Force -ErrorAction SilentlyContinue } catch {} } exit 0 };" ^
  "[Console]::add_CancelKeyPress($handler);" ^
  "while ($true) { Start-Sleep -Seconds 1 }"
