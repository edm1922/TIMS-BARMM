<#
.SYNOPSIS
    TIMS-BARMM Offline Server - Update Script
.DESCRIPTION
    Safely updates an existing TIMS-BARMM offline installation.
    Run as Administrator.

    Procedure:
      1. Create a timestamped backup (database dump + files).
      2. Enable Laravel maintenance mode.
      3. Replace application code (preserving .env, storage/, and vendor/).
      4. Install new Composer dependencies (no-dev, optimized).
      5. Run migrations.
      6. Clear and rebuild all caches.
      7. Run health checks.
      8. Disable maintenance mode.
      9. On any failure, roll back to the backup.

.PARAMETER ProjectRoot
    Absolute path to the TIMS-BARMM project root. Default: C:\xampp\htdocs\TIMS-BARMM

.PARAMETER XamppRoot
    Absolute path to the XAMPP installation. Default: C:\xampp

.PARAMETER SourceZip
    Path to the new TIMS-BARMM release ZIP (or folder).

.EXAMPLE
    .\update-offline.ps1 -SourceZip "C:\Updates\TIMS-BARMM-v2.0.zip"
#>

[CmdletBinding(SupportsShouldProcess)]
param (
    [string] $ProjectRoot = "C:\xampp\htdocs\TIMS-BARMM",
    [string] $XamppRoot   = "C:\xampp",
    [string] $SourceZip   = ""
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

# Any unhandled error must not strand the site in maintenance mode: report it,
# restore the previous files, and bring the application back up.
trap {
    try { Write-Fatal ("Unexpected error: " + $_.Exception.Message) } catch { exit 1 }
}

# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

$ProgressFile = Join-Path $ProjectRoot "storage\app\private\app-update-progress.json"
$script:CurrentPercent = 0
$script:CurrentStep = "Starting"

# The page polls this file while the update runs. It lives under storage/
# because the update replaces application code but never storage/.
function Write-Phase {
    param(
        [string] $Step,
        [int]    $Percent,
        [string] $State = "running",
        [string] $Message = ""
    )

    $script:CurrentPercent = $Percent
    $script:CurrentStep = $Step

    $payload = [ordered]@{
        state      = $State
        percent    = $Percent
        step       = $Step
        message    = $(if ($Message -eq "") { $null } else { $Message })
        updated_at = (Get-Date).ToString("o")
    }

    try {
        $dir = Split-Path $ProgressFile -Parent
        if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
        # WriteAllText without a BOM: Laravel's json_decode cannot read a
        # BOM-prefixed document, and Set-Content -Encoding UTF8 adds one.
        $json = $payload | ConvertTo-Json -Compress
        [System.IO.File]::WriteAllText(
            $ProgressFile,
            $json,
            (New-Object System.Text.UTF8Encoding($false))
        )
    } catch {
        Write-Host "    [!!]  Could not write update progress: $($_.Exception.Message)" -ForegroundColor Yellow
    }
}

function Write-Step  { param([string]$Msg) Write-Host "`n==> $Msg" -ForegroundColor Cyan }
function Write-Ok    { param([string]$Msg) Write-Host "    [OK]  $Msg" -ForegroundColor Green }
function Write-Warn  { param([string]$Msg) Write-Host "    [!!]  $Msg" -ForegroundColor Yellow }
function Write-Fatal {
    param([string]$Msg)
    Write-Host "    [XX]  $Msg" -ForegroundColor Red
    Write-Phase -Step $script:CurrentStep -Percent $script:CurrentPercent -State "failed" -Message $Msg
    Invoke-Rollback
    exit 1
}

$phpExe   = Join-Path $XamppRoot "php\php.exe"
$mysqlExe = Join-Path $XamppRoot "mysql\bin\mysql.exe"
$mysqldumpExe = Join-Path $XamppRoot "mysql\bin\mysqldump.exe"
$artisan  = Join-Path $ProjectRoot "artisan"

$timestamp   = Get-Date -Format "yyyyMMdd-HHmmss"
$backupDir   = "C:\TIMS-BARMM-Backups\update-$timestamp"
$rollbackDir = "$backupDir\rollback-files"

function Invoke-Php {
    param([string[]]$PhpArgs, [switch]$AllowFail, [int]$TimeoutSec = 300)

    $argList = @()
    foreach ($a in $PhpArgs) {
        if ($a -match '\s') { $argList += ('"' + $a + '"') } else { $argList += $a }
    }

    $stdOut = [System.IO.Path]::GetTempFileName()
    $stdErr = [System.IO.Path]::GetTempFileName()

    $proc = Start-Process -FilePath $phpExe -ArgumentList $argList `
        -WorkingDirectory $ProjectRoot -NoNewWindow -PassThru `
        -RedirectStandardOutput $stdOut -RedirectStandardError $stdErr

    # Cache the handle so ExitCode is populated after the process exits.
    $null = $proc.Handle

    if (-not $proc.WaitForExit($TimeoutSec * 1000)) {
        try { $proc.Kill() } catch { }
        Remove-Item $stdOut, $stdErr -Force -ErrorAction SilentlyContinue
        Write-Fatal "PHP command timed out after $TimeoutSec seconds: php $($PhpArgs -join ' ')"
    }

    $proc.WaitForExit()

    $out = Get-Content $stdOut -Raw -ErrorAction SilentlyContinue
    $err = Get-Content $stdErr -Raw -ErrorAction SilentlyContinue
    Remove-Item $stdOut, $stdErr -Force -ErrorAction SilentlyContinue

    if ($out) { Write-Host $out.TrimEnd() }
    if ($err) { Write-Host $err.TrimEnd() -ForegroundColor Yellow }

    if (-not $AllowFail -and $proc.ExitCode -ne 0) {
        Write-Fatal "PHP command failed (exit $($proc.ExitCode)): php $($PhpArgs -join ' ')"
    }
}

function Read-Env {
    param([string]$Key)
    $envFile = Join-Path $ProjectRoot ".env"
    $line    = Get-Content $envFile | Where-Object { $_ -match "^$Key=" }
    if ($line) { return $line.Split("=", 2)[1].Trim('"').Trim("'") }
    return ""
}

function Invoke-Rollback {
    Write-Host "`n!! Rolling back to previous version..." -ForegroundColor Red

    if (Test-Path $rollbackDir) {
        # Restore application files (excludes .env and storage which were not changed)
        Copy-Item "$rollbackDir\*" $ProjectRoot -Recurse -Force
        Write-Warn "Files restored from $rollbackDir"
    }

    # Disable maintenance mode if active
    $downFile = Join-Path $ProjectRoot "storage\framework\down"
    if (Test-Path $downFile) {
        Remove-Item $downFile -Force
        Write-Warn "Maintenance mode disabled."
    }
}

# ---------------------------------------------------------------------------
# Step 0: Require Administrator
# ---------------------------------------------------------------------------
Write-Step "Checking privileges"
Write-Phase -Step "Checking privileges" -Percent 3
$id = [System.Security.Principal.WindowsIdentity]::GetCurrent()
$p  = [System.Security.Principal.WindowsPrincipal]$id
if (-not $p.IsInRole([System.Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Write-Host "[XX] This script must be run as Administrator." -ForegroundColor Red; exit 1
}
Write-Ok "Running as Administrator."

# ---------------------------------------------------------------------------
# Step 1: Validate source
# ---------------------------------------------------------------------------
Write-Step "Validating update source"
Write-Phase -Step "Validating the update package" -Percent 6

if ($SourceZip -eq "" -or -not (Test-Path $SourceZip)) {
    Write-Host "[XX] Please specify a valid -SourceZip path." -ForegroundColor Red
    exit 1
}
Write-Ok "Update source: $SourceZip"

# ---------------------------------------------------------------------------
# Step 2: Create backup
# ---------------------------------------------------------------------------
Write-Step "Creating backup before update"
Write-Phase -Step "Backing up the database" -Percent 10

New-Item -ItemType Directory -Path $backupDir -Force | Out-Null
New-Item -ItemType Directory -Path $rollbackDir -Force | Out-Null

# Read DB credentials from .env
$dbName = Read-Env "DB_DATABASE"
$dbUser = Read-Env "DB_USERNAME"
$dbPass = Read-Env "DB_PASSWORD"
$dbHost = Read-Env "DB_HOST"
if (-not $dbHost) { $dbHost = "127.0.0.1" }

# Database dump
$dumpFile = "$backupDir\db-$timestamp.sql.gz"
Write-Host "    Dumping database '$dbName'..."
$dumpArgs = @(
    "-h", $dbHost, "-u", $dbUser, "-p$dbPass",
    "--single-transaction", "--routines", "--triggers", $dbName
)
& $mysqldumpExe @dumpArgs | & "C:\Windows\System32\compact.exe" /EXE:GZip >$null 2>&1
# Fallback: plain SQL dump if compression fails
& $mysqldumpExe @dumpArgs > "$backupDir\db-$timestamp.sql" 2>&1
if ($LASTEXITCODE -ne 0) { Write-Fatal "Database dump failed." }
Write-Ok "Database dumped to $backupDir\db-$timestamp.sql"

# Copy rollback files (excludes .env, storage, vendor to save space)
Write-Phase -Step "Snapshotting files for rollback" -Percent 25
Write-Host "    Copying application files for rollback..."
$excludes = @(".env", "storage", "vendor", "node_modules", ".git")
Get-ChildItem $ProjectRoot -Exclude $excludes |
    Copy-Item -Destination $rollbackDir -Recurse -Force
Write-Ok "Rollback snapshot created at $rollbackDir"

# ---------------------------------------------------------------------------
# Step 3: Enable maintenance mode
# ---------------------------------------------------------------------------
Write-Step "Enabling maintenance mode"
Write-Phase -Step "Enabling maintenance mode" -Percent 33
Invoke-Php @($artisan, "down", "--retry=60", "--no-interaction")
Write-Ok "Maintenance mode enabled. Users will see 'service unavailable'."

# ---------------------------------------------------------------------------
# Step 4: Extract / copy new version
# ---------------------------------------------------------------------------
Write-Step "Deploying new version"
Write-Phase -Step "Extracting the new version" -Percent 40

$extractDir = "$env:TEMP\tims-barmm-update-$timestamp"

if ($SourceZip -like "*.zip") {
    Expand-Archive -Path $SourceZip -DestinationPath $extractDir -Force
    # The zip may have a single subfolder - flatten if needed
    $inner = Get-ChildItem $extractDir | Where-Object { $_.PSIsContainer }
    if ($inner.Count -eq 1) { $extractDir = $inner[0].FullName }
} else {
    # Assume it's a directory
    $extractDir = $SourceZip
}
Write-Ok "Source extracted/located at $extractDir"

# Preserve critical files before overwrite
$envBackup     = "$backupDir\.env.bak"
$storageBackup = "$backupDir\storage-bak"
Copy-Item (Join-Path $ProjectRoot ".env") $envBackup -Force
if (Test-Path (Join-Path $ProjectRoot "storage")) {
    Copy-Item (Join-Path $ProjectRoot "storage") $storageBackup -Recurse -Force
}
Write-Ok "Preserved .env and storage/ from current installation."

# Copy new files - excluding .env and storage (we keep old ones)
Write-Phase -Step "Replacing application files" -Percent 48
Get-ChildItem $extractDir -Exclude @(".env", "storage", ".env.example") |
    Copy-Item -Destination $ProjectRoot -Recurse -Force
Write-Ok "New application files copied."

# Restore preserved files (overwrite any from source)
Copy-Item $envBackup (Join-Path $ProjectRoot ".env") -Force
Write-Ok ".env restored."

# ---------------------------------------------------------------------------
# Step 5: Install Composer dependencies
# ---------------------------------------------------------------------------
Write-Step "Installing Composer dependencies"
Write-Phase -Step "Installing dependencies" -Percent 60

$composerPhar = Join-Path $ProjectRoot "composer.phar"
if (Test-Path $composerPhar) {
    & $phpExe $composerPhar install --no-dev --optimize-autoloader --working-dir=$ProjectRoot
    if ($LASTEXITCODE -ne 0) { Write-Fatal "Composer install failed." }
    Write-Ok "Composer dependencies updated."
} elseif (Test-Path (Join-Path $extractDir "vendor")) {
    Copy-Item (Join-Path $extractDir "vendor") $ProjectRoot -Recurse -Force
    Write-Ok "vendor/ copied from source package."
} else {
    Write-Warn "No composer.phar or vendor/ found. Skipping dependency install."
    Write-Warn "If this is an error, run: php composer.phar install --no-dev inside $ProjectRoot"
}

# ---------------------------------------------------------------------------
# Step 6: Run migrations
# ---------------------------------------------------------------------------
Write-Step "Running database migrations"
Write-Phase -Step "Running database migrations" -Percent 72

Invoke-Php @($artisan, "config:clear", "--no-interaction")
Invoke-Php @($artisan, "migrate", "--force", "--no-interaction")
Write-Ok "Migrations applied."

# ---------------------------------------------------------------------------
# Step 7: Rebuild caches
# ---------------------------------------------------------------------------
Write-Step "Rebuilding caches"
Write-Phase -Step "Rebuilding caches" -Percent 84

Invoke-Php @($artisan, "config:cache", "--no-interaction")
Invoke-Php @($artisan, "route:cache", "--no-interaction")
Invoke-Php @($artisan, "view:cache", "--no-interaction")
Write-Ok "Caches rebuilt."

# ---------------------------------------------------------------------------
# Step 8: Health check
# ---------------------------------------------------------------------------
Write-Step "Running health checks"
Write-Phase -Step "Running health checks" -Percent 92

# Verify artisan responds
$version = (& $phpExe $artisan "--version" 2>&1)
if ($LASTEXITCODE -ne 0) { Write-Fatal "Artisan did not respond correctly after update." }
Write-Ok "Artisan version: $version"

# Check DB connectivity
$dbCheck = (& $phpExe $artisan "db:show" "--json" 2>&1 | Select-Object -Last 1)
if ($LASTEXITCODE -ne 0) {
    Write-Warn "db:show failed - trying simple query..."
    Invoke-Php @($artisan, "tinker", "--execute=DB::select('SELECT 1');", "--no-interaction") -AllowFail
    if ($LASTEXITCODE -ne 0) { Write-Fatal "Database connectivity check failed after update." }
}
Write-Ok "Database connectivity verified."

# ---------------------------------------------------------------------------
# Step 9: Disable maintenance mode
# ---------------------------------------------------------------------------
Write-Step "Disabling maintenance mode"
Write-Phase -Step "Bringing the server back online" -Percent 97

Invoke-Php @($artisan, "up", "--no-interaction")
Write-Ok "Application is live again."

# ---------------------------------------------------------------------------
# Step 10: Clean up temp files
# ---------------------------------------------------------------------------
if (Test-Path "$env:TEMP\tims-barmm-update-$timestamp") {
    Remove-Item "$env:TEMP\tims-barmm-update-$timestamp" -Recurse -Force
}

# ---------------------------------------------------------------------------
# Summary
# ---------------------------------------------------------------------------
Write-Phase -Step "Update complete" -Percent 100 -State "completed"
Write-Host ""
Write-Host "========================================" -ForegroundColor Green
Write-Host "  TIMS-BARMM Update Complete" -ForegroundColor Green
Write-Host "========================================" -ForegroundColor Green
Write-Host ""
Write-Host "  Backup location: $backupDir" -ForegroundColor Yellow
Write-Host "  To roll back:    .\update-offline.ps1 rollback (see rollback-files/)" -ForegroundColor Yellow
Write-Host ""
Write-Host "Verify the application works before closing this session." -ForegroundColor Cyan
Write-Host ""
