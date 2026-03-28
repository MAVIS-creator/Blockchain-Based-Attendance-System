@echo off
rem open_with_autosave.bat - open Explorer on this folder and start autosave watcher in background
set REPO_DIR=%~dp0

nrem Open folder in Explorer
start "" "explorer.exe" "%REPO_DIR%"

nrem Start watcher in minimized PowerShell window
start "Autosave Watcher" powershell -WindowStyle Minimized -ExecutionPolicy Bypass -File "%REPO_DIR%autosave-watcher.ps1"
echo Opened folder and started autosave watcher.
exit /b 0
