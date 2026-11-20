@echo off
taskkill /fi "WINDOWTITLE eq Chasha Bhai - Satellite*" /t /f >nul 2>&1
taskkill /fi "WINDOWTITLE eq Chasha Bhai - Website*" /t /f >nul 2>&1
echo Chasha Bhai stopped.