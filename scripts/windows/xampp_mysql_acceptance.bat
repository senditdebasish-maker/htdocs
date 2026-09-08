@echo off
setlocal EnableExtensions

REM GP Tender Portal - disposable MySQL/MariaDB acceptance runner for Windows XAMPP.
REM Run from Command Prompt after copying the app to C:\xampp\htdocs\gp-tender.
REM This script DROPS and recreates DB_NAME. Never point DB_NAME at production.

set "XAMPP_DIR=%XAMPP_DIR%"
if "%XAMPP_DIR%"=="" set "XAMPP_DIR=C:\xampp"
set "PHP_EXE=%XAMPP_DIR%\php\php.exe"
set "MYSQL_EXE=%XAMPP_DIR%\mysql\bin\mysql.exe"

if not exist "%PHP_EXE%" (
  echo ERROR: PHP not found at "%PHP_EXE%".
  echo Set XAMPP_DIR to your XAMPP directory and run again.
  exit /b 1
)
if not exist "%MYSQL_EXE%" (
  echo ERROR: mysql.exe not found at "%MYSQL_EXE%".
  echo Start/install XAMPP MySQL/MariaDB and run again.
  exit /b 1
)

pushd "%~dp0\..\.." >nul
if not exist "install.sql" (
  echo ERROR: install.sql not found. Run this script from the checked-out application package.
  popd >nul
  exit /b 1
)

if "%DB_NAME%"=="" set "DB_NAME=gp_portal_acceptance"
if "%DB_USER%"=="" set "DB_USER=root"
REM DB_PASS may be left blank for a stock XAMPP local MySQL root account.

set "MYSQL_AUTH=--user=%DB_USER% --host=127.0.0.1 --port=3306"
if not "%DB_PASS%"=="" set "MYSQL_AUTH=%MYSQL_AUTH% --password=%DB_PASS%"

set "DB_DRIVER=mysql"
set "DB_HOST=127.0.0.1"
set "DB_PORT=3306"
set "ALLOW_E2E_MYSQL_RESET=1"
set "ACCEPTANCE_SQL=%TEMP%\gp-tender-acceptance-install.sql"

powershell -NoProfile -ExecutionPolicy Bypass -Command ^
  "$src = Join-Path (Get-Location) 'install.sql'; $dst = $env:ACCEPTANCE_SQL; $db = $env:DB_NAME; (Get-Content -Raw $src) -replace '`gp_portal`', ('`' + $db + '`') | Set-Content -Encoding UTF8 $dst"
if errorlevel 1 (
  echo ERROR: Failed to create temporary acceptance SQL.
  popd >nul
  exit /b 1
)

echo [1/5] Dropping and recreating disposable database %DB_NAME% ...
"%MYSQL_EXE%" %MYSQL_AUTH% --execute="DROP DATABASE IF EXISTS `%DB_NAME%`; CREATE DATABASE `%DB_NAME%` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
if errorlevel 1 goto :fail

echo [2/5] Importing install.sql into %DB_NAME% ...
"%MYSQL_EXE%" %MYSQL_AUTH% < "%ACCEPTANCE_SQL%"
if errorlevel 1 goto :fail

echo [3/5] Running schema health check ...
"%PHP_EXE%" seed.php --check
if errorlevel 1 goto :fail

echo [4/5] Running full MySQL lifecycle/PDF/audit acceptance smoke ...
"%PHP_EXE%" tests\e2e_lifecycle.php
if errorlevel 1 goto :fail

echo [5/5] CLI MySQL acceptance completed for disposable database %DB_NAME%.
echo Next: start Apache in XAMPP and manually test http://localhost/gp-tender/ using XAMPP_INSTALLATION.md.
popd >nul
exit /b 0

:fail
echo ERROR: Acceptance step failed. Review the output above. Database %DB_NAME% is disposable and may be dropped after investigation.
popd >nul
exit /b 1
