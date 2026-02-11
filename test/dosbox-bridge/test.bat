@echo off
REM DOSBox Bridge Test Launcher (Windows)
REM This script helps run the test components

cd %~dp0\..\..

echo ========================================
echo   DOSBox Bridge Test Launcher
echo ========================================
echo.

:menu
echo Please select what to run:
echo.
echo   1. Start Bridge Server (Terminal 1)
echo   2. Start DOSBox (Terminal 2)
echo   3. Open Test Client in Browser
echo   4. Install Node.js Dependencies
echo   5. Check Prerequisites
echo   6. Exit
echo.
set /p choice=Enter choice (1-6):

if "%choice%"=="1" goto bridge
if "%choice%"=="2" goto dosbox
if "%choice%"=="3" goto client
if "%choice%"=="4" goto install
if "%choice%"=="5" goto check
if "%choice%"=="6" goto end
echo Invalid choice. Please try again.
echo.
goto menu

:bridge
echo.
echo Starting Bridge Server...
echo Press Ctrl+C to stop
echo.
node scripts\door-bridge-server.js 5000 5001 test-session
goto end

:dosbox
echo.
echo Starting DOSBox...
echo.

REM Try dosbox-x first, then dosbox
dosbox-x -conf test\dosbox-bridge\dosbox-bridge-test.conf 2>nul
if errorlevel 1 (
    dosbox -conf test\dosbox-bridge\dosbox-bridge-test.conf 2>nul
    if errorlevel 1 (
        echo Error: Neither DOSBox-X nor DOSBox found
        echo Install DOSBox-X from: https://dosbox-x.com/
        pause
    )
)
goto end

:client
echo.
echo Opening test client in default browser...
start test\dosbox-bridge\test-client.html
echo.
echo If browser doesn't open automatically:
echo   file:///%CD%\test\dosbox-bridge\test-client.html
echo.
pause
goto menu

:install
echo.
echo Installing Node.js dependencies...
call npm install
echo.
echo Done!
pause
goto menu

:check
echo.
echo Checking prerequisites...
echo.

echo [Node.js]
node --version 2>nul
if errorlevel 1 (
    echo   NOT FOUND - Install Node.js 18.x or newer
) else (
    echo   OK
)

echo.
echo [NPM Packages]
node -e "require('ws')" 2>nul
if errorlevel 1 (
    echo   ws: NOT FOUND - Run: npm install
) else (
    echo   ws: OK
)

node -e "require('iconv-lite')" 2>nul
if errorlevel 1 (
    echo   iconv-lite: NOT FOUND - Run: npm install
) else (
    echo   iconv-lite: OK
)

echo.
echo [DOSBox]
dosbox -version >nul 2>&1
if errorlevel 1 (
    dosbox-x --version >nul 2>&1
    if errorlevel 1 (
        echo   NOT FOUND - Install DOSBox or DOSBox-X
    ) else (
        echo   DOSBox-X: OK
    )
) else (
    echo   DOSBox: OK
)

echo.
pause
goto menu

:end
