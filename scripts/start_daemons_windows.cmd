@echo off
setlocal

powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0start_daemons_windows.ps1"
