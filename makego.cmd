@echo off
setlocal
cd /d "%~dp0tools\binktermphp-pm"

go mod tidy
if errorlevel 1 exit /b 1

echo Building Windows (amd64)...
set GOOS=windows
set GOARCH=amd64
go build -o ..\..\dist\windows-amd64\binktermphp-pm.exe .\cmd\pm
if errorlevel 1 exit /b 1
go build -o ..\..\dist\windows-amd64\binktermphp-ctl.exe .\cmd\ctl
if errorlevel 1 exit /b 1

echo Building Linux (amd64)...
set GOOS=linux
set GOARCH=amd64
go build -o ..\..\dist\linux-amd64\binktermphp-pm .\cmd\pm
if errorlevel 1 exit /b 1
go build -o ..\..\dist\linux-amd64\binktermphp-ctl .\cmd\ctl
if errorlevel 1 exit /b 1

echo Building macOS (amd64)...
set GOOS=darwin
set GOARCH=amd64
go build -o ..\..\dist\darwin-amd64\binktermphp-pm .\cmd\pm
if errorlevel 1 exit /b 1
go build -o ..\..\dist\darwin-amd64\binktermphp-ctl .\cmd\ctl
if errorlevel 1 exit /b 1

echo Building macOS (arm64)...
set GOOS=darwin
set GOARCH=arm64
go build -o ..\..\dist\darwin-arm64\binktermphp-pm .\cmd\pm
if errorlevel 1 exit /b 1
go build -o ..\..\dist\darwin-arm64\binktermphp-ctl .\cmd\ctl
if errorlevel 1 exit /b 1

echo Building Linux (arm64)...
set GOOS=linux
set GOARCH=arm64
go build -o ..\..\dist\linux-arm64\binktermphp-pm .\cmd\pm
if errorlevel 1 exit /b 1
go build -o ..\..\dist\linux-arm64\binktermphp-ctl .\cmd\ctl
if errorlevel 1 exit /b 1

echo.
echo Done. Binaries written to dist\:
echo   dist\windows-amd64\  binktermphp-pm.exe  binktermphp-ctl.exe
echo   dist\linux-amd64\    binktermphp-pm      binktermphp-ctl
echo   dist\linux-arm64\    binktermphp-pm      binktermphp-ctl
echo   dist\darwin-amd64\   binktermphp-pm      binktermphp-ctl
echo   dist\darwin-arm64\   binktermphp-pm      binktermphp-ctl
endlocal
