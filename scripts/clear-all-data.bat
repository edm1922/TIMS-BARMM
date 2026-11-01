@echo off
setlocal EnableExtensions
title TIMS-BARMM - Clear all application data

rem ===========================================================================
rem  DESTRUCTIVE UTILITY - FOR THE OFFLINE/DEMO DATABASE
rem
rem  Truncates the contents of EVERY table in the TIMS-BARMM database except:
rem    - users       (the account table requested by the operator)
rem    - migrations  (Laravel's schema history; not application data. Keeping
rem                   it prevents future migrations from trying to recreate
rem                   tables that already exist.)
rem
rem  It does NOT drop tables or the database, and it does NOT touch other
rem  MySQL schemas. A database backup is written before anything is changed.
rem
rem  Run this only on a device whose beneficiary/sync/audit data may be erased.
rem  It requires a MySQL account with DROP privileges (normally root).
rem ===========================================================================

cd /d "%~dp0.."

set "MYSQL=C:\xampp\mysql\bin\mysql.exe"
set "MYSQLDUMP=C:\xampp\mysql\bin\mysqldump.exe"
set "DB_NAME=tims_barmm"

if not exist "%MYSQL%" (
    echo ERROR: mysql.exe was not found at "%MYSQL%".
    echo Edit MYSQL in this script to match the XAMPP installation.
    pause
    exit /b 1
)

if not exist "%MYSQLDUMP%" (
    echo ERROR: mysqldump.exe was not found at "%MYSQLDUMP%".
    echo Edit MYSQLDUMP in this script to match the XAMPP installation.
    pause
    exit /b 1
)

for /f "tokens=1,* delims==" %%A in ('findstr /b /c:"DB_DATABASE=" ".env" 2^>nul') do set "DB_NAME=%%B"

echo.
echo ===========================================================================
echo  WARNING: THIS ERASES ALL APPLICATION DATA
echo  in the "%DB_NAME%" database: beneficiaries, batches, imports, reports,
echo  audit logs, sync records, conflicts, sessions, cache, jobs, and
echo  offline-server enrollment records.
echo.
echo  Preserved tables: users (accounts), migrations (schema history)
echo  Every other table in "%DB_NAME%" will be truncated.
echo ===========================================================================
echo.
set /p "CONFIRM=Type CLEAR to continue: "
if /i not "%CONFIRM%"=="CLEAR" (
    echo Cancelled. Nothing was changed.
    pause
    exit /b 1
)

set "DB_ADMIN=root"
set /p "DB_ADMIN=MySQL admin user [root]: "
if "%DB_ADMIN%"=="" set "DB_ADMIN=root"

set /p "DB_PASS=MySQL admin password: "

for /f %%I in ('powershell -NoProfile -Command "Get-Date -Format yyyyMMdd-HHmmss"') do set "STAMP=%%I"
set "CNF=%TEMP%\tims-reset-%RANDOM%-%RANDOM%.cnf"
set "SQLFILE=%TEMP%\tims-reset-%RANDOM%-%RANDOM%.sql"
set "BACKUP_DIR=storage\app\private\backups"
set "BACKUP=%BACKUP_DIR%\pre-reset-%STAMP%.sql"

if not exist "%BACKUP_DIR%" mkdir "%BACKUP_DIR%" >nul 2>&1

>"%CNF%" echo [client]
>>"%CNF%" echo user=%DB_ADMIN%
>>"%CNF%" echo password=%DB_PASS%
>>"%CNF%" echo host=127.0.0.1
>>"%CNF%" echo port=3306

"%MYSQL%" --defaults-extra-file="%CNF%" -N -B -e "SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name='%DB_NAME%';" >"%TEMP%\tims-db-check.txt" 2>nul
set /p DB_EXISTS=<"%TEMP%\tims-db-check.txt"
del "%TEMP%\tims-db-check.txt" >nul 2>&1
if not "%DB_EXISTS%"=="1" (
    echo ERROR: Database "%DB_NAME%" does not exist or the admin credentials are wrong.
    goto :cleanup
)

echo.
echo Backing up "%DB_NAME%" to "%BACKUP%" ...
"%MYSQLDUMP%" --defaults-extra-file="%CNF%" --single-transaction --routines --triggers --result-file="%BACKUP%" "%DB_NAME%"
if errorlevel 1 (
    echo ERROR: Backup failed. Nothing was erased.
    goto :cleanup
)

echo Generating truncate statements ...
>"%SQLFILE%" echo SET FOREIGN_KEY_CHECKS=0;
for /f "usebackq delims=" %%T in (`"%MYSQL%" --defaults-extra-file="%CNF%" -N -B -e "SELECT table_name FROM information_schema.tables WHERE table_schema='%DB_NAME%' AND table_type='BASE TABLE' AND table_name NOT IN ('users','migrations') ORDER BY table_name;"`) do (
    >>"%SQLFILE%" echo TRUNCATE TABLE `%%T`;
)
>>"%SQLFILE%" echo SET FOREIGN_KEY_CHECKS=1;

echo Clearing all application data (users and migrations are preserved) ...
"%MYSQL%" --defaults-extra-file="%CNF%" "%DB_NAME%" < "%SQLFILE%"
if errorlevel 1 (
    echo ERROR: The clear operation failed. Restore the backup if needed:
    echo   "%BACKUP%"
    goto :cleanup
)

echo.
echo Done. All application data cleared. Preserved: users, migrations.
echo Backup: "%BACKUP%"
echo.
pause
goto :eof

:cleanup
if exist "%CNF%" del "%CNF%" >nul 2>&1
if exist "%SQLFILE%" del "%SQLFILE%" >nul 2>&1
echo.
echo No credentials were left in temporary files.
pause
exit /b 1
