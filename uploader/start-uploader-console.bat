@echo off
title Guild Bank Uploader (console)
cd /d "%~dp0"
powershell -NoProfile -STA -ExecutionPolicy Bypass -File "%~dp0gbs-uploader.ps1" -Console
echo.
pause
