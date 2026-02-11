@echo off
REM Clean up expired door sessions
REM This should be run periodically via Windows Task Scheduler

cd /d "%~dp0"
php scripts\cleanup_expired_dosdoor_sessions.php
