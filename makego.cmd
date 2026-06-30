@echo off
cd /d "%~dp0tools\binktermphp-pm"
go mod tidy
if errorlevel 1 exit /b 1
go build -o ..\..\binktermphp-pm.exe .\cmd\pm
if errorlevel 1 exit /b 1
go build -o ..\..\binktermphp-ctl.exe .\cmd\ctl
if errorlevel 1 exit /b 1
echo Done. binktermphp-pm.exe and binktermphp-ctl.exe written to project root.
