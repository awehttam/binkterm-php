@echo off
REM DOSBox Door Maintenance Launcher
REM Launches DOSBox-X for door configuration

echo ========================================
echo   DOSBox-X Door Maintenance Mode
echo ========================================
echo.

REM Save project directory
set PROJECT_DIR=%~dp0
set PROJECT_DIR=%PROJECT_DIR:~0,-1%

echo Project directory: %PROJECT_DIR%
echo.
echo DOSBox-X will launch with default settings.
echo Once inside DOSBox, type these commands:
echo.
echo   mount c %PROJECT_DIR%\dosbox-bridge\dos
echo   c:
echo   cd \doors\lord
echo   lordcfg
echo.
echo Press any key to launch DOSBox-X...
pause > nul

REM Change to DOSBox-X directory and run with NO config
cd /d "C:\DOSBox-X"
start "DOSBox-X Maintenance" dosbox-x.exe

echo.
echo DOSBox-X should now be visible.
cd /d "%PROJECT_DIR%"
pause
