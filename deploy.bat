@REM ============================================================
@REM Quick Deploy Script - Deploy Code + Sync .env in One Command
@REM ============================================================
@REM Usage: deploy.bat
@REM         deploy.bat --no-sync     (skip .env sync)
@REM         deploy.bat --no-restart  (skip app restart)
@REM
@echo off
setlocal enabledelayedexpansion

cls
echo.
echo ====================================================
echo  🚀 SMART ATTENDANCE SYSTEM - DEPLOY SCRIPT
echo ====================================================
echo.

@REM Check if .env exists
if not exist ".env" (
    echo ❌ ERROR: .env file not found!
    echo.
    echo Please create .env file first:
    echo   1. Copy .env.example to .env
    echo   2. Update values with your configuration
    echo   3. Run this script again
    echo.
    pause
    exit /b 1
)

echo ✅ Found .env file
echo.

@REM Parse arguments
set SYNC_ENV=true
set RESTART=false

if "%1"=="--no-sync" set SYNC_ENV=false
if "%1"=="--no-restart" set RESTART=false
if "%2"=="--no-sync" set SYNC_ENV=false
if "%2"=="--no-restart" set RESTART=false

echo 📋 Deployment Plan:
echo    • Push code to Azure
echo    • Build application (Oryx)
if "!SYNC_ENV!"=="true" echo    • Sync .env variables
if "!RESTART!"=="true" echo    • Restart app service
echo.

@REM Run deployment
powershell -NoProfile -ExecutionPolicy Bypass -Command ^
  ".\tools\deploy-azure.ps1 -SyncEnv:$!SYNC_ENV!.IsPresent -Restart:$!RESTART!.IsPresent" 2>nul

@REM Fallback if PowerShell command doesn't work as expected
if errorlevel 1 (
    echo.
    echo Running with standard syntax...
    if "!SYNC_ENV!"=="true" (
        if "!RESTART!"=="true" (
            powershell -ExecutionPolicy Bypass -File .\tools\deploy-azure.ps1 -SyncEnv -Restart
        ) else (
            powershell -ExecutionPolicy Bypass -File .\tools\deploy-azure.ps1 -SyncEnv
        )
    ) else (
        if "!RESTART!"=="true" (
            powershell -ExecutionPolicy Bypass -File .\tools\deploy-azure.ps1 -Restart
        ) else (
            powershell -ExecutionPolicy Bypass -File .\tools\deploy-azure.ps1
        )
    )
)

echo.
echo ====================================================
if errorlevel 0 (
    echo ✅ Deployment Complete!
) else (
    echo ❌ Deployment Failed!
)
echo ====================================================
echo.

pause
