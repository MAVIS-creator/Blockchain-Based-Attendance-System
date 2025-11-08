@echo off
:loop
git add .
git commit -m "Auto-save %date% %time%"
git push
timeout /t 20 >nul
goto loop
