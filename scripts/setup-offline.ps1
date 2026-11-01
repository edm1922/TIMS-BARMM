<#
.SYNOPSIS
    TIMS-BARMM Offline Server - Initial Setup Script
.DESCRIPTION
    Sets up a fresh Windows/XAMPP installation as a TIMS-BARMM offline server.
    Run as Administrator. Read all comments before executing in production.

    What this script does:
      1. Validates prerequisites (XAMPP, PHP, required PHP extensions).
      2. Verifies the package manifest (SHA256SUMS.txt) when present.
      3. Refuses to overwrite an existing populated database unless -Reinstall
         is given, in which case a database dump and .env copy are written first.
         With -Repair it only applies pending migrations and leaves the existing
         data, .env, and server configuration untouched.
      4. Creates the MariaDB database and least-privilege application user.
      5. Writes a production-ready .env file. No interactive prompts: the
         database password is generated, the application URL is derived from
         the LAN address, and the trusted Central Server address comes from the
         packaged .env.example.
      6. Runs Laravel migrations and prepares the account registry.
      7. Configures the Apache VirtualHost to serve only public/.
      8. Adds a Windows Firewall rule restricting Apache to the LAN subnet.
      9. Creates a Windows Task Scheduler job to sync every 15 minutes.
     10. Opens the application in the default browser.

    SAFETY:
      - Run this script ONCE on a clean XAMPP installation.
      - Never run on a machine that already has TIMS-BARMM data unless you
        understand what each step does. Use update-offline.ps1 for updates.
      - Verify SUBNET before applying firewall rules.

.PARAMETER ProjectRoot
    Absolute path to the TIMS-BARMM project root. Default: C:\xampp\htdocs\TIMS-BARMM

.PARAMETER XamppRoot
    Absolute path to the XAMPP installation. Default: C:\xampp

.PARAMETER Subnet
    The LAN/hotspot subnet to allow Apache access from (e.g. 192.168.1.0/24).
    Default: auto-detected from the active LAN adapter; override for a
    hotspot or a different site. Tailscale/virtual adapters are ignored.

.PARAMETER AppUrl
    Optional application URL override. When omitted it is derived from this
    machine's LAN IPv4 address, for example http://192.168.1.50.

.PARAMETER MysqlRootPassword
    Optional MySQL root password used for the administrative database commands.
    It is never written to .env and never stored by the script.

.PARAMETER Reinstall
    Acknowledges an existing populated database or .env file. The script backs
    up the database and the .env file first and then continues. Without this
    switch the script stops instead of touching existing data.

.PARAMETER Repair
    Applies pending Laravel migrations to an existing installation without
    reinstalling it. The existing .env, database, Apache configuration, and
    firewall rules are left unchanged. Use this after deploying updated code
    that includes new migrations.

.PARAMETER SkipChecksumVerification
    Skips verification of SHA256SUMS.txt. Use only when the manifest is known
    to be damaged.

.EXAMPLE
    .\setup-offline.ps1 -Subnet "192.168.1.0/24"

.EXAMPLE
    .\setup-offline.ps1 -Subnet "192.168.1.0/24" -Reinstall

.EXAMPLE
    .\setup-offline.ps1 -Repair
#>

[CmdletBinding(SupportsShouldProcess)]
param (
    [string] $ProjectRoot = "C:\xampp\htdocs\TIMS-BARMM",
    [string] $XamppRoot   = "C:\xampp",
    [string] $Subnet       = "",
    [string] $AppUrl       = "",
    [string] $MysqlRootPassword = "",
    [switch] $Reinstall,
    [switch] $Repair,
    [switch] $SkipChecksumVerification
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

$script:stepTotal = 17
$script:stepCurrent = 0

function Write-Step  { param([string]$Msg)
    $script:stepCurrent++
    $percent = [math]::Round($script:stepCurrent/$script:stepTotal*100)
    Write-Host "`n==> [$percent%] $Msg" -ForegroundColor Cyan
}
function Write-Ok    { param([string]$Msg) Write-Host "    [OK]  $Msg" -ForegroundColor Green }
function Write-Warn  { param([string]$Msg) Write-Host "    [!!]  $Msg" -ForegroundColor Yellow }
function Write-Fatal { param([string]$Msg) Write-Host "    [XX]  $Msg" -ForegroundColor Red; exit 1 }

function Require-Admin {
    $id = [System.Security.Principal.WindowsIdentity]::GetCurrent()
    $p  = [System.Security.Principal.WindowsPrincipal]$id
    if (-not $p.IsInRole([System.Security.Principal.WindowsBuiltInRole]::Administrator)) {
        Write-Fatal "This script must be run as Administrator. Right-click PowerShell -> Run as administrator."
    }
}

function Invoke-Php {
    param([string[]]$PhpArgs, [int]$TimeoutSec = 300)
    $php = Join-Path $XamppRoot "php\php.exe"

    # Quote arguments that contain spaces so Start-Process passes them intact.
    $argList = @()
    foreach ($a in $PhpArgs) {
        if ($a -match '\s') { $argList += ('"' + $a + '"') } else { $argList += $a }
    }

    $stdOut = [System.IO.Path]::GetTempFileName()
    $stdErr = [System.IO.Path]::GetTempFileName()

    $proc = Start-Process -FilePath $php -ArgumentList $argList `
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

    if ($proc.ExitCode -ne 0) { Write-Fatal "PHP command failed (exit $($proc.ExitCode)): php $($PhpArgs -join ' ')" }
}

function Invoke-MySQL {
    param([string]$SQL, [string]$User = "root", [string]$Pass = "")
    $mysql = Join-Path $XamppRoot "mysql\bin\mysql.exe"
    if ($Pass -eq "") {
        $out = echo $SQL | & $mysql -u $User 2>&1
    } else {
        $out = echo $SQL | & $mysql -u $User "-p$Pass" 2>&1
    }
    if ($LASTEXITCODE -ne 0) {
        $msg = ($out | Out-String)
        if ($msg -match '1030|Read page with wrong checksum|Aria') {
            Write-Host "    [!!]  MySQL reports a corrupted Aria system table (usually an unclean shutdown)." -ForegroundColor Yellow
            Write-Host "    [!!]  Repair it, then run this script again:" -ForegroundColor Yellow
            Write-Host "          REPAIR TABLE mysql.global_priv EXTENDED;" -ForegroundColor Yellow
            Write-Host "          REPAIR TABLE mysql.db EXTENDED;" -ForegroundColor Yellow
            Write-Host "          REPAIR TABLE mysql.procs_priv EXTENDED;" -ForegroundColor Yellow
            Write-Host "          REPAIR TABLE mysql.columns_priv EXTENDED;" -ForegroundColor Yellow
            Write-Host "          REPAIR TABLE mysql.tables_priv EXTENDED;" -ForegroundColor Yellow
            Write-Host "          FLUSH PRIVILEGES;" -ForegroundColor Yellow
        }
        Write-Fatal "MySQL command failed: $msg"
    }
}

function Invoke-MySQLValue {
    param([string]$SQL, [string]$User = "root", [string]$Pass = "")
    $mysql = Join-Path $XamppRoot "mysql\bin\mysql.exe"
    if ($Pass -eq "") {
        $result = echo $SQL | & $mysql -u $User
    } else {
        $result = echo $SQL | & $mysql -u $User "-p$Pass"
    }
    if ($LASTEXITCODE -ne 0 -or $result -eq "") { return "" }
    return ($result | Select-Object -First 1)
}

# ---------------------------------------------------------------------------
# Step 0: Require Administrator
# ---------------------------------------------------------------------------
Write-Step "Checking privileges"
Require-Admin
Write-Ok "Running as Administrator."

# ---------------------------------------------------------------------------
# Step 1: Validate prerequisites
# ---------------------------------------------------------------------------
Write-Step "Validating prerequisites"

$phpExe   = Join-Path $XamppRoot "php\php.exe"
$mysqlExe = Join-Path $XamppRoot "mysql\bin\mysql.exe"
$httpdExe = Join-Path $XamppRoot "apache\bin\httpd.exe"
$artisan  = Join-Path $ProjectRoot "artisan"

foreach ($f in @($phpExe, $mysqlExe, $httpdExe, $artisan)) {
    if (-not (Test-Path $f)) { Write-Fatal "Required file not found: $f" }
}
Write-Ok "XAMPP binaries present."

# Check PHP version (must be 8.x)
$phpVer = (& $phpExe -r "echo PHP_MAJOR_VERSION;")
if ($phpVer -lt 8) { Write-Fatal "PHP 8+ is required. Found: $(& $phpExe -r 'echo phpversion();')" }
Write-Ok "PHP version: $(& $phpExe -r 'echo phpversion();')"

# Check required extensions
$requiredExts = @("pdo_mysql", "openssl", "mbstring", "tokenizer", "ctype", "json", "fileinfo", "xml", "zip")
foreach ($ext in $requiredExts) {
    $loaded = (& $phpExe -r "echo extension_loaded('$ext') ? 'yes' : 'no';")
    if ($loaded -ne "yes") { Write-Fatal "PHP extension '$ext' is not loaded. Enable it in $XamppRoot\php\php.ini." }
}
Write-Ok "All required PHP extensions loaded."

# ---------------------------------------------------------------------------
# Optional repair mode: apply pending migrations to an existing installation
# ---------------------------------------------------------------------------
if ($Repair) {
    if ($Reinstall) {
        Write-Fatal "Use either -Repair or -Reinstall, not both."
    }

    Write-Step "Applying pending migrations to the existing installation"

    $repairEnvPath = Join-Path $ProjectRoot ".env"
    if (-not (Test-Path -LiteralPath $repairEnvPath)) {
        Write-Fatal "-Repair requires an existing .env file. Run the normal setup for a new installation."
    }

    $repairConfigCache = Join-Path $ProjectRoot "bootstrap\cache\config.php"
    if (Test-Path -LiteralPath $repairConfigCache) {
        Remove-Item -LiteralPath $repairConfigCache -Force -ErrorAction SilentlyContinue
    }

    Invoke-Php @($artisan, "config:clear", "--no-interaction")
    Invoke-Php @($artisan, "migrate", "--force", "--no-interaction")

    Write-Ok "Pending migrations applied. Existing data, .env, and server settings were left unchanged."
    exit 0
}

# ---------------------------------------------------------------------------
# Step 2: Derive configuration (no interactive prompts)
# ---------------------------------------------------------------------------
Write-Step "Preparing configuration"

$AppName = "TIMS-BARMM"
$DbName  = "tims_barmm"
$DbUser  = "tims_app"

$htdocsRoot = [System.IO.Path]::GetFullPath((Join-Path $XamppRoot 'htdocs')).TrimEnd([char[]]@('\', '/'))
$projectFull = [System.IO.Path]::GetFullPath($ProjectRoot).TrimEnd([char[]]@('\', '/'))

if (-not $projectFull.StartsWith($htdocsRoot, [System.StringComparison]::OrdinalIgnoreCase)) {
    Write-Fatal "ProjectRoot must be inside $htdocsRoot so Apache can serve it. Move the package there or pass -ProjectRoot."
}

$projectRelative = $projectFull.Substring($htdocsRoot.Length).Trim([char[]]@('\', '/')).Replace('\', '/')
if ([string]::IsNullOrWhiteSpace($projectRelative)) {
    Write-Fatal "ProjectRoot cannot be the htdocs root itself; use a subfolder such as TIMS-BARMM."
}

# Application URL and firewall subnet: derive both from the active LAN
# adapter unless explicitly supplied. Tailscale, loopback, Bluetooth, and
# virtual adapters are ignored so a phone hotspot or a different site gets the
# correct address instead of a hard-coded 192.168.1.0/24.
$lanIp = ""
$prefix = 24
$lanConfig = $null

if ([string]::IsNullOrWhiteSpace($AppUrl) -or [string]::IsNullOrWhiteSpace($Subnet)) {
    $lanConfig = Get-NetIPConfiguration -ErrorAction SilentlyContinue |
        Where-Object {
            $_.IPv4DefaultGateway -and
            $_.IPv4Address -and
            $_.InterfaceAlias -notmatch 'Tailscale|Loopback|Bluetooth|vEthernet|VMware|VirtualBox|Hyper-V'
        } |
        Select-Object -First 1

    if (-not $lanConfig) {
        $fallback = Get-NetIPAddress -AddressFamily IPv4 -ErrorAction SilentlyContinue |
            Where-Object {
                $_.IPAddress -notlike '127.*' -and
                $_.IPAddress -notlike '169.254.*' -and
                $_.IPAddress -notlike '100.64.*' -and
                $_.InterfaceAlias -notmatch 'Tailscale|Loopback|Bluetooth|vEthernet|VMware|VirtualBox|Hyper-V'
            } |
            Select-Object -First 1
        if ($fallback) {
            $lanIp = $fallback.IPAddress
            $prefix = [int]$fallback.PrefixLength
        }
    } else {
        $address = @($lanConfig.IPv4Address)[0]
        $lanIp = $address.IPAddress
        $prefix = [int]$address.PrefixLength
    }
}

if ([string]::IsNullOrWhiteSpace($AppUrl)) {
    if ([string]::IsNullOrWhiteSpace($lanIp)) {
        $lanIp = 'localhost'
        Write-Warn "No LAN IPv4 address detected; using 'localhost'. Edit APP_URL in .env if clients connect over the LAN."
    }

    $AppUrl = "http://$lanIp"
}

if ([string]::IsNullOrWhiteSpace($Subnet)) {
    if ([string]::IsNullOrWhiteSpace($lanIp) -or $lanIp -eq 'localhost') {
        $Subnet = '127.0.0.1/32'
        Write-Warn "No LAN subnet detected; the firewall rule will allow loopback only. Pass -Subnet explicitly for hotspot/LAN clients."
    } else {
        $octets = $lanIp.Split('.')
        $ipLong = ([uint32]$octets[0] -shl 24) -bor ([uint32]$octets[1] -shl 16) -bor ([uint32]$octets[2] -shl 8) -bor ([uint32]$octets[3])
        $maskLong = [uint32]0
        for ($bit = 0; $bit -lt $prefix; $bit++) {
            $maskLong = $maskLong -bor ([uint32]1 -shl (31 - $bit))
        }
        $networkLong = $ipLong -band $maskLong
        $Subnet = "{0}.{1}.{2}.{3}/{4}" -f `
            (($networkLong -shr 24) -band 255), `
            (($networkLong -shr 16) -band 255), `
            (($networkLong -shr 8) -band 255), `
            ($networkLong -band 255), $prefix
    }
}

Write-Ok "Application URL: $AppUrl"
Write-Ok "Firewall subnet: $Subnet"

# Use the directory form (trailing slash) so Apache does not hand Laravel a
# path-suffixed URI that can produce a 404 before the root route is matched.
$launchUrl = $AppUrl.TrimEnd('/') + '/'

# Database password: generated locally. It is never shipped and never reused
# across devices; it is stored only in the protected .env file.
$dbPassBytes = New-Object byte[] 24
[System.Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($dbPassBytes)
$DbPassStr = -join ($dbPassBytes | ForEach-Object { '{0:x2}' -f $_ })
Write-Ok "Generated a local database password (stored only in .env)."

# Trusted Central Server address: read from the .env.example the package was
# built with. This is a deployment-level setting, never typed per device.
$BootstrapUrl = ""
$envExamplePathEarly = Join-Path $ProjectRoot ".env.example"
if (Test-Path -LiteralPath $envExamplePathEarly) {
    foreach ($line in [System.IO.File]::ReadAllLines($envExamplePathEarly)) {
        if ($line -match '^\s*SYNC_BOOTSTRAP_URL\s*=\s*(.*)$') { $BootstrapUrl = $Matches[1].Trim() }
    }
}

if ([string]::IsNullOrWhiteSpace($BootstrapUrl)) {
    Write-Warn "SYNC_BOOTSTRAP_URL is not set in .env.example. First-time onboarding needs the trusted Central Server address; set it before enrolling."
} else {
    Write-Ok "Trusted Central Server: $BootstrapUrl"
}

# ---------------------------------------------------------------------------
# Step 2A: Verify the package manifest
# ---------------------------------------------------------------------------
Write-Step "Verifying package integrity"

$manifestPath = Join-Path $ProjectRoot "SHA256SUMS.txt"

if ($SkipChecksumVerification) {
    Write-Warn "Checksum verification skipped by request."
} elseif (Test-Path $manifestPath) {
    $manifestLines = @(
        [System.IO.File]::ReadAllLines($manifestPath) |
            Where-Object { -not [string]::IsNullOrWhiteSpace($_) }
    )
    $total = $manifestLines.Count

    if ($total -eq 0) {
        Write-Fatal "SHA256SUMS.txt is empty, so the package cannot be verified. Extract the package again before installing."
    }

    Write-Ok "Verifying $total file(s) against SHA256SUMS.txt..."
    Write-Host "        Hashing every packaged file; on a slow disk this can take a few minutes." -ForegroundColor DarkGray

    $index = 0
    $checked = 0
    $mismatched = @()
    $sha = [System.Security.Cryptography.SHA256]::Create()
    $verificationStarted = Get-Date

    try {
        foreach ($line in $manifestLines) {
            $index++

            $parts = $line -split '\s{2}', 2
            if ($parts.Count -lt 2) { continue }

            $expected = $parts[0].Trim()
            $relative = $parts[1].Trim()
            $target = Join-Path $ProjectRoot ($relative -replace '/', '\')

            if (-not (Test-Path -LiteralPath $target)) {
                $mismatched += "$relative (missing)"
            } else {
                # Get-FileHash has a large per-file cmdlet overhead (seconds per
                # thousand files on Windows PowerShell 5.1, more with real-time
                # antivirus). Hashing the stream directly is much faster and
                # still produces the same lowercase SHA-256 the manifest
                # carries.
                try {
                    $stream = [System.IO.File]::Open(
                        $target,
                        [System.IO.FileMode]::Open,
                        [System.IO.FileAccess]::Read,
                        [System.IO.FileShare]::ReadWrite
                    )

                    try {
                        $actual = [System.BitConverter]::ToString($sha.ComputeHash($stream)) -replace '-', ''
                    } finally {
                        $stream.Dispose()
                    }

                    if ($actual.ToLower() -ne $expected.ToLower()) {
                        $mismatched += "$relative (checksum mismatch)"
                    }
                } catch {
                    $mismatched += "$relative (unreadable: $($_.Exception.Message))"
                }

                $checked++
            }

            if (($index % 250) -eq 0 -or $index -eq $total) {
                $seconds = [int]((Get-Date) - $verificationStarted).TotalSeconds
                Write-Host ("        verified {0:N0}/{1:N0} files ({2}s)" -f $index, $total, $seconds) -ForegroundColor DarkGray
            }
        }
    } finally {
        $sha.Dispose()
    }

    if ($mismatched.Count -gt 0) {
        foreach ($item in ($mismatched | Select-Object -First 20)) { Write-Host "        $item" -ForegroundColor Red }
        if ($mismatched.Count -gt 20) { Write-Host "        ... and $($mismatched.Count - 20) more mismatched file(s)" -ForegroundColor Red }
        Write-Fatal "Package verification failed for $($mismatched.Count) file(s). Extract the package again before installing. If the package came from a trusted transfer and you need to proceed, re-run with -SkipChecksumVerification."
    }

    Write-Ok "Verified $checked file(s) against SHA256SUMS.txt."
} else {
    Write-Warn "SHA256SUMS.txt not found - the package integrity cannot be verified."
}

# ---------------------------------------------------------------------------
# Step 2B: Refuse to overwrite an existing installation
# ---------------------------------------------------------------------------
Write-Step "Checking for an existing installation"

$existingEnvPath = Join-Path $ProjectRoot ".env"
$envExists = Test-Path -LiteralPath $existingEnvPath

$tableCount = Invoke-MySQLValue -SQL "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '$DbName';" -User "root" -Pass $MysqlRootPassword
$tableCount = [int]($tableCount -replace "[^0-9]", "")
$beneficiaryCount = 0

if ($tableCount -gt 0) {
    $rawCount = Invoke-MySQLValue -SQL "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '$DbName' AND table_name = 'beneficiaries';" -User "root" -Pass $MysqlRootPassword
    if ([int]($rawCount -replace "[^0-9]", "") -gt 0) {
        $bt = [char]96
        $rawRows = Invoke-MySQLValue -SQL ('SELECT COUNT(*) FROM ' + $bt + $DbName + $bt + '.beneficiaries;') -User "root" -Pass $MysqlRootPassword
        $beneficiaryCount = [int]($rawRows -replace "[^0-9]", "")
    }
}

if ($tableCount -gt 0 -or $envExists) {
    if (-not $Reinstall) {
        Write-Host ""
        Write-Host "    An existing installation was detected:" -ForegroundColor Yellow
        if ($envExists) { Write-Host "      - .env file is present at $existingEnvPath" -ForegroundColor Yellow }
        if ($tableCount -gt 0) { Write-Host "      - database '$DbName' has $tableCount table(s), $beneficiaryCount beneficiary record(s)" -ForegroundColor Yellow }
        Write-Host ""
        Write-Host "    This script will not overwrite existing data. Choose one of these:" -ForegroundColor Cyan
        Write-Host "      - Run again with -Repair to apply pending migrations only." -ForegroundColor Cyan
        Write-Host "      - Run again with -Reinstall to back up the data and continue." -ForegroundColor Cyan
        Write-Host "      - Run update-offline.ps1 instead when you only need to update the code." -ForegroundColor Cyan
        Write-Host "      - Drop the database manually if you truly want a clean slate:" -ForegroundColor Cyan
        Write-Host "          DROP DATABASE \`$DbName\`;" -ForegroundColor Cyan
        Write-Host ""
        exit 2
    }

    $backupDir = Join-Path $ProjectRoot "storage\app\private\backups"
    if (-not (Test-Path $backupDir)) { New-Item -ItemType Directory -Path $backupDir -Force | Out-Null }

    $stamp = Get-Date -Format "yyyyMMdd-HHmmss"

    if ($envExists) {
        $envBackup = Join-Path $backupDir ".env.backup-$stamp"
        Copy-Item -LiteralPath $existingEnvPath -Destination $envBackup -Force
        Write-Ok "Existing .env backed up to $envBackup"
    }

    if ($tableCount -gt 0) {
        $dumpExe = Join-Path $XamppRoot "mysql\bin\mysqldump.exe"
        $dumpFile = Join-Path $backupDir "$DbName-$stamp.sql"

        if (Test-Path $dumpExe) {
            $dumpArgs = @("-u", "root", "--result-file=$dumpFile", "--single-transaction", "--routines", $DbName)
            if ($MysqlRootPassword -ne "") { $dumpArgs += "-p$MysqlRootPassword" }
            & $dumpExe @dumpArgs
            if ($LASTEXITCODE -ne 0) {
                Write-Fatal "Database backup failed. Aborting before any change is made."
            }
            Write-Ok "Database backed up to $dumpFile"
        } else {
            Write-Warn "mysqldump.exe not found - back up '$DbName' manually before continuing."
        }
    }

    Write-Warn "-Reinstall was used: the existing data was preserved and backed up."
}
else {
    Write-Ok "No existing installation detected - clean setup confirmed."
}

# ---------------------------------------------------------------------------
# Step 3: Create database and application user
# ---------------------------------------------------------------------------
Write-Step "Creating database and application user"

# Create database
# NOTE: a literal MySQL identifier quote is a backtick. In a PowerShell
# double-quoted string that is written as two backticks (```), never as
# "\`" - the latter produces a literal backslash and a literal $, which
# MySQL rejects with "Unknown command '\$'".
$bt = [char]96
$createDbSql = 'CREATE DATABASE IF NOT EXISTS ' + $bt + $DbName + $bt + ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;'
Invoke-MySQL -SQL $createDbSql -User "root"

# Create user
$createUserSql = "CREATE USER IF NOT EXISTS '$DbUser'@'127.0.0.1' IDENTIFIED BY '$DbPassStr';"
Invoke-MySQL -SQL $createUserSql -User "root" -Pass $MysqlRootPassword

# CREATE USER IF NOT EXISTS leaves an existing account and its password alone,
# but the .env written below carries the password that was just typed. The
# password is therefore set explicitly so the application can always connect.
$alterUserSql = "ALTER USER '$DbUser'@'127.0.0.1' IDENTIFIED BY '$DbPassStr';"
Invoke-MySQL -SQL $alterUserSql -User "root" -Pass $MysqlRootPassword

# Grant privileges
# Kept on a single line: Windows PowerShell 5.1 does not continue an
# expression onto a following line that starts with '+'.
$grantSql = 'GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, INDEX, ALTER, REFERENCES ON ' + $bt + $DbName + $bt + '.* TO ''' + $DbUser + '''@''127.0.0.1'';'
Invoke-MySQL -SQL $grantSql -User "root" -Pass $MysqlRootPassword

# Flush privileges
$flushSql = "FLUSH PRIVILEGES;"
Invoke-MySQL -SQL $flushSql -User "root"

Write-Ok "Database '$DbName' and user '$DbUser' created."

# ---------------------------------------------------------------------------
# Step 4: Write .env file
# ---------------------------------------------------------------------------
Write-Step "Writing .env file"

$envPath    = Join-Path $ProjectRoot ".env"
$envExample = Join-Path $ProjectRoot ".env.example"

if (-not (Test-Path $envExample)) { Write-Fatal ".env.example not found in $ProjectRoot" }

# Generate APP_KEY
$appKey = (& $phpExe (Join-Path $ProjectRoot "artisan") key:generate --show 2>&1 | Select-Object -Last 1).Trim()
# If artisan key:generate fails because .env doesn't exist yet, generate one directly
if ($appKey -notlike "base64:*") {
    $rng   = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    $bytes = New-Object byte[] 32
    $rng.GetBytes($bytes)
    $appKey = "base64:" + [Convert]::ToBase64String($bytes)
}

# Keep a stable local identity. Every locally created row records this UUID as
# its origin; changing it on reinstall would make existing batches look like
# they came from another server and can break the origin/name unique key.
$localServerUuid = ''
if (Test-Path -LiteralPath $envPath) {
    foreach ($line in [System.IO.File]::ReadAllLines($envPath)) {
        if ($line -match '^\s*LOCAL_SERVER_UUID\s*=\s*(.+?)\s*$') {
            $localServerUuid = $Matches[1].Trim().Trim('"')
            break
        }
    }
}
if ([string]::IsNullOrWhiteSpace($localServerUuid)) {
    $localServerUuid = [guid]::NewGuid().ToString()
}

# No ADMIN_* credentials are written: the Manager account is created by the
# first-browser onboarding flow. No Central Server URL is written either; the
# trusted bootstrap URL below is validated before it is used.
$envContent = @"
APP_NAME="TIMS-BARMM"
APP_ENV=production
APP_KEY=$appKey
APP_DEBUG=false
APP_URL=$AppUrl
INSTANCE_MODE=offline
LOCAL_SERVER_UUID=$localServerUuid
SYNC_BOOTSTRAP_URL=$BootstrapUrl

LOG_CHANNEL=daily
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=$DbName
DB_USERNAME=$DbUser
DB_PASSWORD=$DbPassStr

BROADCAST_DRIVER=log
CACHE_DRIVER=database
FILESYSTEM_DISK=local
QUEUE_CONNECTION=database
SESSION_DRIVER=database
SESSION_LIFETIME=480
SESSION_ENCRYPT=true

MAIL_MAILER=log
"@

[System.IO.File]::WriteAllText($envPath, $envContent, [System.Text.UTF8Encoding]::new($false))
Write-Ok ".env written to $envPath"
Write-Warn "Protect this file: it contains database credentials."

# ---------------------------------------------------------------------------
# Step 4A: Prepare writable storage directories
# ---------------------------------------------------------------------------
Write-Step "Preparing storage directories"

$storageDirs = @(
    "framework\cache\data",
    "framework\sessions",
    "framework\views",
    "app\private",
    "app\public",
    "logs"
)

foreach ($dir in $storageDirs) {
    $path = Join-Path $ProjectRoot "storage\$dir"
    if (-not (Test-Path $path)) {
        New-Item -ItemType Directory -Path $path -Force | Out-Null
    }
    $ignoreFile = Join-Path $path ".gitignore"
    if (-not (Test-Path $ignoreFile)) {
        [System.IO.File]::WriteAllText($ignoreFile, "*`n!.gitignore", [System.Text.Encoding]::UTF8)
    }
}

Write-Ok "Storage directories prepared."

# Laravel requires bootstrap/cache to exist and be writable. Packages exclude
# generated caches, so a clean package may not contain this directory.
$bootstrapCache = Join-Path $ProjectRoot "bootstrap\cache"
if (-not (Test-Path $bootstrapCache)) {
    New-Item -ItemType Directory -Path $bootstrapCache -Force | Out-Null
}
$cacheProbe = Join-Path $bootstrapCache ".write-probe"
try {
    [System.IO.File]::WriteAllText($cacheProbe, "ok")
    Remove-Item -LiteralPath $cacheProbe -Force
} catch {
    Write-Fatal "bootstrap/cache is not writable: $bootstrapCache"
}

# ---------------------------------------------------------------------------
# Step 5: Install Composer dependencies (production)
# ---------------------------------------------------------------------------
Write-Step "Checking Composer dependencies"

$vendorDir = Join-Path $ProjectRoot "vendor"
if (-not (Test-Path (Join-Path $vendorDir "autoload.php"))) {
    Write-Warn "vendor/ not found. Installing Composer dependencies (production)..."
    $composerPhar = Join-Path $ProjectRoot "composer.phar"
    if (Test-Path $composerPhar) {
        & $phpExe $composerPhar install --no-dev --optimize-autoloader --working-dir=$ProjectRoot
    } else {
        Write-Fatal "vendor/ is missing and composer.phar not found. Copy the project with vendor/ included, or install Composer and run: composer install --no-dev."
    }
} else {
    Write-Ok "Composer vendor directory present."
}

# ---------------------------------------------------------------------------
# Step 6: Run migrations
# ---------------------------------------------------------------------------
Write-Step "Running database migrations"

# A package built on a production installation must not carry that
# installation's cached config: the cache contains the central database
# credentials and would override the .env written above. Remove it and clear
# Laravel's config cache before migrating.
$configCache = Join-Path $ProjectRoot "bootstrap\cache\config.php"
if (Test-Path $configCache) {
    Remove-Item -LiteralPath $configCache -Force -ErrorAction SilentlyContinue
}
$php = Join-Path $XamppRoot "php\php.exe"
& $php (Join-Path $ProjectRoot "artisan") config:clear --no-interaction 2>&1 | Out-Null

# Run migrations with timeout - kill if exceeds 5 minutes
$migrateArgs = @("artisan", "migrate", "--force", "--no-interaction")
$migrateCmd = & $php $migrateArgs 2>&1

if ($LASTEXITCODE -ne 0) {
    # Check if it's "nothing to migrate" message
    if ($migrateCmd -match "Nothing to migrate") {
        Write-Ok "Migrations up to date."
    } else {
        Write-Fatal "Migrate failed: $migrateCmd"
    }
} else {
    Write-Ok "Migrations applied."
}

# ---------------------------------------------------------------------------
# Step 7: Prepare the account registry
# ---------------------------------------------------------------------------
Write-Step "Preparing accounts"

# No credentials are written to .env, so AdminUserSeeder is a no-op on a fresh
# offline installation: the Manager account is created by first-time onboarding
# in the browser. Running the seeder keeps the behaviour identical for a
# deliberately silent install that set ADMIN_PASSWORD in .env.
Invoke-Php @((Join-Path $ProjectRoot "artisan"), "db:seed", "--class=AdminUserSeeder", "--force", "--no-interaction")
Write-Ok "Manager account is created during first-time onboarding (or was seeded from .env)."

# ---------------------------------------------------------------------------
# Step 7b: Seed BARMM location reference data
# ---------------------------------------------------------------------------
Write-Step "Seeding BARMM location reference data"

# The BARMM locations drive the address combobox and the scope picker on this
# offline installation. The seed uses barmm-locations.json (shipped with the
# package under database/seeders/data/) and is idempotent, so it can be re-run
# on an existing installation to refresh the reference data.
Invoke-Php @((Join-Path $ProjectRoot "artisan"), "db:seed", "--class=BarmmLocationSeeder", "--force", "--no-interaction")
Write-Ok "BARMM location reference data seeded."

# ---------------------------------------------------------------------------
# Step 8: Configure Apache VirtualHost
# ---------------------------------------------------------------------------
Write-Step "Configuring Apache VirtualHost"

$httpdConf   = Join-Path $XamppRoot "apache\conf\httpd.conf"
$extraConf   = Join-Path $XamppRoot "apache\conf\extra\tims-barmm-offline.conf"

$vhostRoot = ($projectFull.Replace('\','/'))

# Serve Laravel's public/ directory as the document root. Using the XAMPP
# htdocs root with a /TIMS-BARMM/public URL depends on Laravel correctly
# stripping that subdirectory prefix and has produced 404s on some Apache
# builds. A dedicated public document root gives a clean http://<ip>/ URL.
$vhostConfig = @"
# TIMS-BARMM Offline VirtualHost
# Generated by setup-offline.ps1 on $(Get-Date -Format 'yyyy-MM-dd HH:mm')
<VirtualHost *:80>
    ServerName localhost
    DocumentRoot "$vhostRoot/public"

    <Directory "$vhostRoot/public">
        Options -Indexes -Includes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    # Block access to sensitive paths if they are ever placed under public/.
    <LocationMatch "/(\.env|\.git|composer\.(json|lock)|vendor|storage/logs|database|tests|docs)">
        Require all denied
    </LocationMatch>

    ErrorLog "$($XamppRoot.Replace('\','/'))/apache/logs/tims-barmm-error.log"
    CustomLog "$($XamppRoot.Replace('\','/'))/apache/logs/tims-barmm-access.log" combined
</VirtualHost>
"@

[System.IO.File]::WriteAllText($extraConf, $vhostConfig, [System.Text.Encoding]::UTF8)
Write-Ok "VirtualHost config written to $extraConf"

# Ensure there is exactly one TIMS include and that Apache is actually
# listening on port 80. Some machines have been changed to "Listen 8080"
# while the generated VirtualHost remained on 80, which leaves the app
# configured but unreachable (404 from an older server).
$includeStatement = "Include conf/extra/tims-barmm-offline.conf"
$httpdLines = @(Get-Content $httpdConf | Where-Object {
    $_ -notmatch '^\s*Include\s+conf/extra/tims-barmm-offline\.conf\s*$'
})
if (-not ($httpdLines | Where-Object { $_ -match '^\s*Listen\s+80\s*$' })) {
    $httpdLines += 'Listen 80'
    Write-Warn "Added 'Listen 80' to httpd.conf; verify port 80 is not used by another service."
}
$httpdLines += $includeStatement
Set-Content -LiteralPath $httpdConf -Value $httpdLines -Encoding utf8
Write-Ok "Apache include and port 80 configured."

# Test Apache config
& $httpdExe -t
if ($LASTEXITCODE -ne 0) { Write-Fatal "Apache config test failed. Review $extraConf" }
Write-Ok "Apache config syntax OK."

# ---------------------------------------------------------------------------
# Step 9: Windows Firewall - restrict Apache to LAN subnet
# ---------------------------------------------------------------------------
Write-Step "Configuring Windows Firewall"
Write-Warn "Subnet: $Subnet - verify this is correct before proceeding."

$ruleName = "TIMS-BARMM Apache LAN"

# The subnet must keep its CIDR suffix (192.168.1.0/24) so the whole LAN is
# allowed. Do NOT include "::1" here: Windows rejects loopback addresses in
# -RemoteAddress ("An unspecified, multicast, broadcast, or loopback IPv6
# address was specified").
$RemoteAddress = @("127.0.0.1", $Subnet)

try {
    # Remove old public-profile Apache rules if they exist
    $oldRules = Get-NetFirewallRule -DisplayName '*Apache*' -ErrorAction SilentlyContinue
    foreach ($rule in $oldRules) {
        if (([int]$rule.Profile) -band 4) { # 4 = Public
            Disable-NetFirewallRule -InputObject $rule -ErrorAction SilentlyContinue
            Write-Warn "Disabled public-profile rule: $($rule.DisplayName)"
        }
    }

    # Disable public-profile mysqld rule
    $mysqlRules = Get-NetFirewallRule -DisplayName '*mysqld*' -ErrorAction SilentlyContinue
    foreach ($rule in $mysqlRules) {
        Disable-NetFirewallRule -InputObject $rule -ErrorAction SilentlyContinue
        Write-Warn "Disabled mysqld firewall rule: $($rule.DisplayName)"
    }

    # Create LAN-scoped rule for Apache
    $existing = Get-NetFirewallRule -DisplayName $ruleName -ErrorAction SilentlyContinue
    if ($existing) {
        Remove-NetFirewallRule -DisplayName $ruleName -ErrorAction SilentlyContinue
    }

    New-NetFirewallRule `
        -DisplayName $ruleName `
        -Direction Inbound `
        -Action Allow `
        -Protocol TCP `
        -LocalPort 80, 443 `
        -RemoteAddress $RemoteAddress `
        -Profile Any `
        -Description "Allow TIMS-BARMM Apache access from loopback and LAN subnet $Subnet" | Out-Null

    Write-Ok "Firewall rule '$ruleName' created for subnet $Subnet."
    Write-Warn "Restart Apache from the XAMPP Control Panel to apply the new firewall rule."
} catch {
    Write-Warn "Firewall configuration could not be completed automatically."
    Write-Warn "Reason: $($_.Exception.Message)"
    Write-Warn "Add the rule manually (Administrator PowerShell):"
    Write-Warn "  New-NetFirewallRule -DisplayName '$ruleName' -Direction Inbound -Action Allow -Protocol TCP -LocalPort 80,443 -RemoteAddress '127.0.0.1','$Subnet' -Profile Any"
}

# ---------------------------------------------------------------------------
# Step 10: Scheduled task - Sync every 15 minutes
# ---------------------------------------------------------------------------
Write-Step "Creating scheduled sync task"

$taskName   = "TIMS-BARMM Offline Sync"

try {
    $taskAction = New-ScheduledTaskAction `
        -Execute $phpExe `
        -Argument "`"$(Join-Path $ProjectRoot 'artisan')`" tims:sync --quiet" `
        -WorkingDirectory $ProjectRoot

    $taskTrigger = New-ScheduledTaskTrigger -RepetitionInterval (New-TimeSpan -Minutes 15) -Once -At (Get-Date)
    $taskSettings = New-ScheduledTaskSettingsSet `
        -ExecutionTimeLimit (New-TimeSpan -Minutes 10) `
        -RestartCount 2 `
        -RestartInterval (New-TimeSpan -Minutes 1) `
        -MultipleInstances IgnoreNew

    $existingTask = Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue
    if ($existingTask) { Unregister-ScheduledTask -TaskName $taskName -Confirm:$false }

    Register-ScheduledTask `
        -TaskName $taskName `
        -Action $taskAction `
        -Trigger $taskTrigger `
        -Settings $taskSettings `
        -RunLevel Highest `
        -Description "TIMS-BARMM automatic sync to central server every 15 minutes." | Out-Null

    Write-Ok "Scheduled task '$taskName' created (every 15 min)."
} catch {
    Write-Warn "Could not create scheduled task '$taskName': $($_.Exception.Message)"
    Write-Warn "Create it manually, or run 'php artisan tims:sync' from Task Scheduler."
}

Write-Warn "NOTE: The sync task requires the server to be enrolled first (Sync Setup in the web UI)."
Write-Warn "NOTE: 'tims:sync' artisan command must exist - verify with: php artisan list | findstr tims"

# ---------------------------------------------------------------------------
# Step 11: Scheduled task - Daily backup at 2 AM
# ---------------------------------------------------------------------------
Write-Step "Creating scheduled backup task"

$backupScript = Join-Path $ProjectRoot "scripts\backup-offline.ps1"
$backupTask   = "TIMS-BARMM Daily Backup"

if (Test-Path $backupScript) {
    try {
        $backupAction = New-ScheduledTaskAction `
            -Execute "powershell.exe" `
            -Argument "-NonInteractive -File `"$backupScript`""

        $backupTrigger  = New-ScheduledTaskTrigger -Daily -At "02:00"
        $backupSettings = New-ScheduledTaskSettingsSet `
            -ExecutionTimeLimit (New-TimeSpan -Minutes 30) `
            -MultipleInstances IgnoreNew

        $existingBackup = Get-ScheduledTask -TaskName $backupTask -ErrorAction SilentlyContinue
        if ($existingBackup) { Unregister-ScheduledTask -TaskName $backupTask -Confirm:$false }

        Register-ScheduledTask `
            -TaskName $backupTask `
            -Action $backupAction `
            -Trigger $backupTrigger `
            -Settings $backupSettings `
            -RunLevel Highest `
            -Description "TIMS-BARMM daily database backup at 2 AM." | Out-Null

        Write-Ok "Scheduled backup task '$backupTask' created (daily at 02:00)."
    } catch {
        Write-Warn "Could not create scheduled backup task '$backupTask': $($_.Exception.Message)"
    }
} else {
    Write-Warn "backup-offline.ps1 not found at $backupScript - skipping backup task."
}

# ---------------------------------------------------------------------------
# Step 12: Final checks
# ---------------------------------------------------------------------------
Write-Step "Running final checks"

# Clear caches
Invoke-Php @((Join-Path $ProjectRoot "artisan"), "config:clear", "--no-interaction")
Invoke-Php @((Join-Path $ProjectRoot "artisan"), "cache:clear", "--no-interaction")

# Restart Apache - only when it runs as a Windows service.
# When Apache is owned by the XAMPP Control Panel we must NOT kill httpd.exe:
# the panel watches that PID and reports "Apache shutdown unexpectedly", and a
# forced kill leaves a stale pid file ("Unclean shutdown of previous run").
try {
    $svc = Get-Service -Name "Apache2.4" -ErrorAction SilentlyContinue
    if ($svc) {
        Restart-Service -Name "Apache2.4" -Force -ErrorAction Stop
        Write-Ok "Apache restarted (service)."
    } else {
        Write-Warn "Apache is managed by the XAMPP Control Panel and was NOT restarted automatically."
        Write-Warn "ACTION REQUIRED: in the XAMPP Control Panel click 'Stop' then 'Start' on the Apache row"
        Write-Warn "to load the new VirtualHost configuration for $ProjectRoot."
    }
} catch {
    Write-Warn "Could not restart Apache automatically: $($_.Exception.Message)"
    Write-Warn "Restart Apache from the XAMPP Control Panel before using the application."
}

# ---------------------------------------------------------------------------
# Step 13: Open the application in the default browser
# ---------------------------------------------------------------------------
Write-Step "Opening the application"

try {
    Start-Process $launchUrl -ErrorAction Stop
    Write-Ok "Opened $launchUrl in the default browser."
} catch {
    Write-Warn "Could not open a browser automatically. Open $launchUrl manually."
}

Write-Host ""
Write-Host "========================================" -ForegroundColor Green
Write-Host "  TIMS-BARMM Offline Setup Complete" -ForegroundColor Green
Write-Host "========================================" -ForegroundColor Green
Write-Host ""
Write-Host "Next steps:" -ForegroundColor Yellow
Write-Host "  1. In the XAMPP Control Panel: click 'Stop' then 'Start' on the Apache row."
Write-Host "     (This loads the new VirtualHost configuration.)"
Write-Host "  2. Open $launchUrl in a browser."
Write-Host "  3. The First-time setup page opens: enter the enrollment token issued by"
Write-Host "     the central administrator, create the Manager account, and confirm"
Write-Host "     the area this device serves."
Write-Host "  4. The initial snapshot is downloaded automatically once enrollment succeeds."
Write-Host "  5. Create encoder accounts in Admin > Encoders for the field staff."
Write-Host "  6. Verify two client devices can reach $launchUrl over the LAN."
Write-Host ""
Write-Host "IMPORTANT:" -ForegroundColor Red
Write-Host "  - Do NOT expose this server to the public internet."
Write-Host "  - Enable BitLocker or equivalent disk encryption on this laptop."
Write-Host "  - Keep a copy of the .env file securely off this machine."
Write-Host ""
