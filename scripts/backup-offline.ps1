<#
.SYNOPSIS
    TIMS-BARMM Offline Server — Backup Script
.DESCRIPTION
    Creates a timestamped backup of the database and essential application files.
    Safe to run while the application is running.
    Intended to be scheduled via Task Scheduler (daily at 02:00).

    Backup contents:
      - Full mysqldump of the application database (.sql)
      - Compressed archive of storage/app/private (uploaded files)
      - Copy of .env (for disaster recovery; protect accordingly)

    Retention: keeps the last 7 daily backups; older ones are pruned automatically.

.PARAMETER ProjectRoot
    Absolute path to the TIMS-BARMM project root. Default: C:\xampp\htdocs\TIMS-BARMM

.PARAMETER XamppRoot
    Absolute path to the XAMPP installation. Default: C:\xampp

.PARAMETER BackupRoot
    Directory where backups are stored. Default: C:\TIMS-BARMM-Backups

.PARAMETER RetainDays
    Number of daily backups to keep. Default: 7

.EXAMPLE
    .\backup-offline.ps1
    .\backup-offline.ps1 -BackupRoot "D:\Backups" -RetainDays 14
#>

[CmdletBinding()]
param (
    [string] $ProjectRoot = "C:\xampp\htdocs\TIMS-BARMM",
    [string] $XamppRoot   = "C:\xampp",
    [string] $BackupRoot  = "C:\TIMS-BARMM-Backups",
    [int]    $RetainDays  = 7
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

$timestamp    = Get-Date -Format "yyyyMMdd-HHmmss"
$backupDir    = Join-Path $BackupRoot "daily-$timestamp"
$logFile      = Join-Path $BackupRoot "backup.log"
$mysqldumpExe = Join-Path $XamppRoot "mysql\bin\mysqldump.exe"

function Write-Log {
    param([string]$Msg, [string]$Level = "INFO")
    $line = "[$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')] [$Level] $Msg"
    Add-Content $logFile $line
    if ($Level -eq "ERROR") { Write-Error $Msg } else { Write-Host $line }
}

function Read-Env {
    param([string]$Key)
    $envFile = Join-Path $ProjectRoot ".env"
    $line    = Get-Content $envFile | Where-Object { $_ -match "^$Key=" } | Select-Object -First 1
    if ($line) { return $line.Split("=", 2)[1].Trim('"').Trim("'") }
    return ""
}

# ---------------------------------------------------------------------------
# Prepare backup directory
# ---------------------------------------------------------------------------
New-Item -ItemType Directory -Path $backupDir -Force | Out-Null
New-Item -ItemType Directory -Path (Split-Path $logFile) -Force | Out-Null
Write-Log "Backup started — target: $backupDir"

# Read DB credentials
$dbName = Read-Env "DB_DATABASE"
$dbUser = Read-Env "DB_USERNAME"
$dbPass = Read-Env "DB_PASSWORD"
$dbHost = Read-Env "DB_HOST"
if (-not $dbHost) { $dbHost = "127.0.0.1" }

if (-not $dbName) { Write-Log "DB_DATABASE not found in .env — aborting." "ERROR"; exit 1 }

# ---------------------------------------------------------------------------
# 1. Database dump
# ---------------------------------------------------------------------------
Write-Log "Dumping database: $dbName"

$sqlFile = Join-Path $backupDir "database.sql"
$dumpArgs = @(
    "-h", $dbHost,
    "-u", $dbUser,
    "-p$dbPass",
    "--single-transaction",
    "--routines",
    "--triggers",
    "--set-gtid-purged=OFF",
    $dbName
)

& $mysqldumpExe @dumpArgs > $sqlFile 2>&1
if ($LASTEXITCODE -ne 0) {
    Write-Log "mysqldump failed (exit $LASTEXITCODE). Check MySQL is running." "ERROR"
    exit 1
}

$sqlSize = [Math]::Round((Get-Item $sqlFile).Length / 1MB, 2)
Write-Log "Database dump complete: $sqlFile ($sqlSize MB)"

# ---------------------------------------------------------------------------
# 2. Compress database dump
# ---------------------------------------------------------------------------
$zipFile = Join-Path $backupDir "database.sql.zip"
Compress-Archive -Path $sqlFile -DestinationPath $zipFile -Force
Remove-Item $sqlFile -Force
$zipSize = [Math]::Round((Get-Item $zipFile).Length / 1MB, 2)
Write-Log "Compressed dump: $zipFile ($zipSize MB)"

# ---------------------------------------------------------------------------
# 3. Back up storage/app (uploaded photos, signatures, etc.)
# ---------------------------------------------------------------------------
$storageAppDir = Join-Path $ProjectRoot "storage\app"
if (Test-Path $storageAppDir) {
    $storageZip = Join-Path $backupDir "storage-app.zip"
    Compress-Archive -Path $storageAppDir -DestinationPath $storageZip -Force
    $sSize = [Math]::Round((Get-Item $storageZip).Length / 1MB, 2)
    Write-Log "Storage backup: $storageZip ($sSize MB)"
} else {
    Write-Log "storage\app not found — skipping."
}

# ---------------------------------------------------------------------------
# 4. Back up .env (protected copy for disaster recovery)
# ---------------------------------------------------------------------------
$envSrc  = Join-Path $ProjectRoot ".env"
$envDest = Join-Path $backupDir ".env.bak"
if (Test-Path $envSrc) {
    Copy-Item $envSrc $envDest -Force
    # Remove read permission for other users on this file
    $acl = Get-Acl $envDest
    $acl.SetAccessRuleProtection($true, $false)
    $adminSid = New-Object System.Security.Principal.SecurityIdentifier("S-1-5-32-544")
    $adminRule = New-Object System.Security.AccessControl.FileSystemAccessRule($adminSid, "FullControl", "Allow")
    $acl.SetAccessRule($adminRule)
    Set-Acl $envDest $acl
    Write-Log ".env backed up (Admin-only read): $envDest"
} else {
    Write-Log ".env not found — skipping."
}

# ---------------------------------------------------------------------------
# 5. Write backup manifest
# ---------------------------------------------------------------------------
$manifest = @{
    timestamp     = $timestamp
    database      = $dbName
    backup_dir    = $backupDir
    files         = (Get-ChildItem $backupDir | Select-Object -ExpandProperty Name)
    total_size_mb = [Math]::Round((Get-ChildItem $backupDir -Recurse | Measure-Object -Property Length -Sum).Sum / 1MB, 2)
}
$manifest | ConvertTo-Json | Set-Content (Join-Path $backupDir "manifest.json")
Write-Log "Manifest written."

# ---------------------------------------------------------------------------
# 6. Prune old backups
# ---------------------------------------------------------------------------
Write-Log "Pruning backups older than $RetainDays days..."

$oldBackups = Get-ChildItem $BackupRoot -Directory |
    Where-Object { $_.Name -like "daily-*" -and $_.CreationTime -lt (Get-Date).AddDays(-$RetainDays) } |
    Sort-Object CreationTime

foreach ($old in $oldBackups) {
    Remove-Item $old.FullName -Recurse -Force
    Write-Log "Removed old backup: $($old.FullName)"
}

Write-Log "Backup complete. Total size: $($manifest.total_size_mb) MB"
Write-Host ""
Write-Host "Backup complete: $backupDir" -ForegroundColor Green
Write-Host "Log: $logFile" -ForegroundColor Cyan
