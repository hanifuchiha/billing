@echo off
REM =================== QTS CACHE REFRESH TASK SCHEDULER SETUP ===================
REM This batch file sets up automatic background cache refresh via Windows Task Scheduler
REM Run this as Administrator (right-click -> Run as Administrator)

SETLOCAL ENABLEDELAYEDEXPANSION

echo.
echo =================== QTS CACHE REFRESH SETUP ===================
echo.

REM Get PHP path
for /f "tokens=*" %%i in ('where php') do set PHP_PATH=%%i

if "%PHP_PATH%"=="" (
    echo ERROR: PHP not found in PATH
    echo Please ensure PHP is installed and added to system PATH
    exit /b 1
)

echo PHP Path: %PHP_PATH%

REM Get current directory
set PROJECT_PATH=d:\quenbytekniksejahtera.com\QTS\crm\billing
set SCRIPT=%PROJECT_PATH%\cache-manager.php
set LOG_FILE=%PROJECT_PATH%\logs\cache-refresh.log

echo Project Path: %PROJECT_PATH%
echo Script: %SCRIPT%
echo Log File: %LOG_FILE%
echo.

REM Check if script exists
if not exist "%SCRIPT%" (
    echo ERROR: Script not found at %SCRIPT%
    exit /b 1
)

REM Create logs directory
if not exist "%PROJECT_PATH%\logs" (
    mkdir "%PROJECT_PATH%\logs"
)

echo Creating Task Scheduler tasks...
echo.

REM Task 1: Quick refresh every 5 minutes
echo Creating task: QTS Cache Refresh (5 min)...
schtasks /create /tn "QTS Cache Refresh 5min" /tr ""%PHP_PATH%" "%SCRIPT%" refresh" /sc minute /mo 5 /f >nul 2>&1
if errorlevel 1 (
    echo WARNING: Failed to create 5-minute task (may already exist)
) else (
    echo   OK: 5-minute task created
)

REM Task 2: Full refresh every 30 minutes
echo Creating task: QTS Cache Refresh (30 min)...
schtasks /create /tn "QTS Cache Refresh 30min" /tr ""%PHP_PATH%" "%SCRIPT%" refresh" /sc minute /mo 30 /f >nul 2>&1
if errorlevel 1 (
    echo WARNING: Failed to create 30-minute task (may already exist)
) else (
    echo   OK: 30-minute task created
)

REM Task 3: Daily cleanup at 2 AM
echo Creating task: QTS Cache Cleanup (Daily)...
schtasks /create /tn "QTS Cache Cleanup Daily" /tr ""%PHP_PATH%" "%SCRIPT%" clear" /sc daily /st 02:00 /f >nul 2>&1
if errorlevel 1 (
    echo WARNING: Failed to create daily cleanup task (may already exist)
) else (
    echo   OK: Daily cleanup task created
)

REM Task 4: Weekly full refresh at 3 AM Sunday
echo Creating task: QTS Cache Full Refresh (Weekly)...
schtasks /create /tn "QTS Cache Full Refresh Weekly" /tr ""%PHP_PATH%" "%SCRIPT%" refresh" /sc weekly /d SUN /st 03:00 /f >nul 2>&1
if errorlevel 1 (
    echo WARNING: Failed to create weekly task (may already exist)
) else (
    echo   OK: Weekly task created
)

echo.
echo =================== TASKS CREATED SUCCESSFULLY ===================
echo.
echo Schedule Overview:
echo   - Every 5 minutes: Quick cache refresh
echo   - Every 30 minutes: Full cache refresh
echo   - Daily at 2:00 AM: Clear expired caches
echo   - Weekly (Sunday) at 3:00 AM: Full system refresh
echo.
echo Management:
echo   View tasks:      schtasks /query /tn "QTS*"
echo   View task details: schtasks /query /tn "QTS Cache Refresh 5min" /v
echo   Delete tasks:    schtasks /delete /tn "QTS Cache Refresh 5min" /f
echo.
echo Logs:
echo   Location: %LOG_FILE%
echo   View:     type "%LOG_FILE%"
echo.
echo Manual triggers:
echo   Quick refresh: %PHP_PATH% %SCRIPT% refresh
echo   Clear cache:   %PHP_PATH% %SCRIPT% clear
echo   Status:        %PHP_PATH% %SCRIPT% status
echo.
echo =================== SETUP COMPLETE ===================
echo.

REM Show current tasks
echo.
echo Current QTS tasks:
schtasks /query /tn "QTS*" 2>nul

pause
