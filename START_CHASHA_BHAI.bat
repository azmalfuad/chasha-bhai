@echo off
title Chasha Bhai launcher
cd /d "%~dp0"
start "Chasha Bhai - Satellite (port 8001)" /min cmd /k "cd /d "%~dp0services\satellite" && set MAX_SCENES=4&& .venv\Scripts\python.exe -m uvicorn main:app --host 127.0.0.1 --port 8001"
start "Chasha Bhai - Website (port 8080)" /min cmd /k "cd /d "%~dp0" && php -d max_execution_time=180 -S 127.0.0.1:8080 router.php"
timeout /t 4 /nobreak >nul
start "" http://localhost:8080/
