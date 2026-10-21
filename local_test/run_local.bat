@echo off
setlocal
title Monali OTP - local test
set "PORT=8000"
cd /d "%~dp0.."

rem Find php.exe: PATH first, then common install folders
set "PHP="
where php >nul 2>nul && set "PHP=php"
if not defined PHP if exist "C:\php\php.exe" set "PHP=C:\php\php.exe"
if not defined PHP if exist "C:\xampp\php\php.exe" set "PHP=C:\xampp\php\php.exe"
if not defined PHP (
  echo [X] php.exe not found. Install PHP or add it to PATH.
  pause
  exit /b 1
)

rem Reset demo data on every run
if exist "%~dp0config.local.json" del "%~dp0config.local.json"

echo.
echo  ======================================================
echo   Local test - no MySQL needed (data in local_test\config.local.json)
echo   PHP           : %PHP%
echo.
echo   Customer page : http://localhost:%PORT%/index.php
echo   Admin page    : http://localhost:%PORT%/admin.php   (admin / 1234)
echo.
echo   Test emails   : netflix@demo.local  PIN 1234
echo                   disney@demo.local   PIN 5678
echo                   nopin@demo.local    (no PIN - should be blocked)
echo.
echo   Close this window to stop the server.
echo  ======================================================
echo.
set OTP_LOCAL=1
start "" "http://localhost:%PORT%/index.php"
"%PHP%" -S localhost:%PORT%
pause
