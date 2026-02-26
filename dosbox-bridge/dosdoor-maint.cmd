@echo off
REM DOSBox Door Maintenance Launcher
REM Launches DOSBox for door configuration

echo ========================================
echo   DOSBox Door Maintenance Mode
echo ========================================
echo.

REM Save project directory
set PROJECT_DIR=%~dp0
set PROJECT_DIR=%PROJECT_DIR:~0,-1%

echo Project directory: %PROJECT_DIR%
echo.
echo DOSBox will launch with maintenance.conf configuration.
echo Press any key to launch DOSBox...
pause > nul

REM Try to find DOSBox executable (prefer vanilla over DOSBox-X)
set "DOSBOX_EXE="

REM Check if DOSBOX_EXECUTABLE environment variable is set
if defined DOSBOX_EXECUTABLE set "DOSBOX_EXE=%DOSBOX_EXECUTABLE%"
if defined DOSBOX_EXE goto :found

if exist "C:\Program Files\DOSBox\DOSBox.exe" set "DOSBOX_EXE=C:\Program Files\DOSBox\DOSBox.exe"
if defined DOSBOX_EXE goto :found

if exist "C:\Program Files (x86)\DOSBox\DOSBox.exe" set "DOSBOX_EXE=C:\Program Files (x86)\DOSBox\DOSBox.exe"
if defined DOSBOX_EXE goto :found

if exist "C:\DOSBox\dosbox.exe" set "DOSBOX_EXE=C:\DOSBox\dosbox.exe"
if defined DOSBOX_EXE goto :found

if exist "C:\DOSBox-X\dosbox-x.exe" set "DOSBOX_EXE=C:\DOSBox-X\dosbox-x.exe"
if defined DOSBOX_EXE goto :found

where dosbox.exe >nul 2>&1
if %ERRORLEVEL% EQU 0 set "DOSBOX_EXE=dosbox.exe"
if defined DOSBOX_EXE goto :found

where dosbox-x.exe >nul 2>&1
if %ERRORLEVEL% EQU 0 set "DOSBOX_EXE=dosbox-x.exe"

:found

if "%DOSBOX_EXE%"=="" (
    echo ERROR: DOSBox not found!
    echo Please install DOSBox or set DOSBOX_EXECUTABLE environment variable.
    pause
    exit /b 1
)

echo Using: %DOSBOX_EXE%
echo.

 "%DOSBOX_EXE%" -conf "%PROJECT_DIR%\maintenance.conf"
