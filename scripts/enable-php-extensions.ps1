<#
.SYNOPSIS
    Enable required PHP extensions in an offline TIMS-BARMM XAMPP installation.

.DESCRIPTION
    Edits C:\xampp\php\php.ini as Administrator, removes the leading semicolon
    from the requested PHP extension lines (or appends the lines when missing),
    backs up php.ini first, verifies each extension with php.exe, and optionally
    restarts Apache.

.EXAMPLE
    # Enable the single extension reported as missing
    powershell -ExecutionPolicy Bypass -File .\scripts\enable-php-extensions.ps1 -Extensions zip

.EXAMPLE
    # Enable every extension the installer requires
    powershell -ExecutionPolicy Bypass -File .\scripts\enable-php-extensions.ps1 -RestartApache

.EXAMPLE
    # Only report status without editing php.ini
    powershell -ExecutionPolicy Bypass -File .\scripts\enable-php-extensions.ps1 -CheckOnly
#>

[CmdletBinding(SupportsShouldProcess)]
param (
    [string]   $XamppRoot = "C:\xampp",
    [string[]] $Extensions = @(
        "pdo_mysql", "openssl", "mbstring", "tokenizer", "ctype",
        "json", "fileinfo", "xml", "zip"
    ),
    [switch]   $RestartApache,
    [switch]   $CheckOnly
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

function Write-Ok   { param([string] $Message) Write-Host "    [OK]  $Message" -ForegroundColor Green }
function Write-Warn { param([string] $Message) Write-Host "    [!!]  $Message" -ForegroundColor Yellow }
function Write-Fail { param([string] $Message) Write-Host "    [XX]  $Message" -ForegroundColor Red }

$phpExe = Join-Path $XamppRoot "php\php.exe"
$iniPath = Join-Path $XamppRoot "php\php.ini"
$extDir  = Join-Path $XamppRoot "php\ext"
$httpd   = Join-Path $XamppRoot "apache\bin\httpd.exe"

if (-not (Test-Path -LiteralPath $phpExe)) {
    Write-Fail "PHP executable not found: $phpExe"
    exit 1
}

if (-not (Test-Path -LiteralPath $iniPath)) {
    Write-Fail "php.ini not found: $iniPath"
    Write-Fail "Start Apache once from XAMPP Control Panel, then run this script again."
    exit 1
}

function Test-Loaded {
    param([string] $Extension)

    $value = (& $phpExe -r "echo extension_loaded('$Extension') ? '1' : '0';" 2>$null)
    return (($value | Select-Object -Last 1).Trim() -eq "1")
}

Write-Host ""
Write-Host "TIMS-BARMM PHP extension setup" -ForegroundColor Cyan
Write-Host "  PHP : $phpExe"
Write-Host "  INI : $iniPath"
Write-Host ""

$missing = @()
foreach ($extension in $Extensions) {
    if (Test-Loaded $extension) {
        Write-Ok "$extension is already enabled."
    } else {
        Write-Warn "$extension is not loaded."
        $missing += $extension
    }
}

if ($missing.Count -eq 0) {
    Write-Host ""
    Write-Ok "All requested PHP extensions are already enabled."
    exit 0
}

if ($CheckOnly) {
    Write-Host ""
    Write-Warn "Missing extensions: $($missing -join ', ')"
    Write-Warn "Run again without -CheckOnly to enable them."
    exit 1
}

$backupPath = "$iniPath.bak-$(Get-Date -Format 'yyyyMMdd-HHmmss')"
Copy-Item -LiteralPath $iniPath -Destination $backupPath -Force
Write-Ok "Backed up php.ini to $backupPath"

$contents = [System.IO.File]::ReadAllText($iniPath)
$updated  = $contents
$changed  = @()

foreach ($extension in $missing) {
    # Matches both forms used by XAMPP:
    #   ;extension=zip
    #   ;extension=php_zip.dll
    # It also normalizes an active but non-working line.
    $pattern = '(?im)^[ \t]*;?[ \t]*extension[ \t]*=[ \t]*"?[ \t]*(?:php_)?' +
               [regex]::Escape($extension) + '(?:\.dll)?[ \t]*"?[ \t]*$'

    if ([regex]::IsMatch($updated, $pattern)) {
        $updated = [regex]::Replace($updated, $pattern, "extension=$extension")
        $changed += $extension
        Write-Ok "Uncommented extension=$extension in php.ini."
        continue
    }

    if ($updated -match "(?im)^[ \t]*extension[ \t]*=[ \t]*`"?[ \t]*(?:php_)?$([regex]::Escape($extension))(?:\.dll)?") {
        $changed += $extension
        Write-Ok "extension=$extension is present but still not loading; check the DLL below."
        continue
    }

    $updated = $updated.TrimEnd() + "`r`nextension=$extension`r`n"
    $changed += $extension
    Write-Ok "Added extension=$extension to php.ini."
}

if ($updated -ne $contents) {
    [System.IO.File]::WriteAllText(
        $iniPath,
        $updated,
        (New-Object System.Text.UTF8Encoding($false))
    )
}

Write-Host ""
Write-Host "Verifying extensions with php.exe..." -ForegroundColor Cyan

$failed = @()
foreach ($extension in $Extensions) {
    if (Test-Loaded $extension) {
        Write-Ok "$extension is loaded."
    } else {
        Write-Fail "$extension is still not loaded."
        $dll = Join-Path $extDir ("php_" + $extension + ".dll")
        if (Test-Path -LiteralPath $dll) {
            Write-Warn "DLL exists: $dll"
        } else {
            Write-Warn "DLL not found: $dll"
        }
        $failed += $extension
    }
}

if ($failed.Count -gt 0) {
    Write-Host ""
    Write-Fail "Could not enable: $($failed -join ', ')"
    Write-Fail "Verify extension_dir in php.ini points to $extDir and that the DLL exists."
    Write-Fail "A backup of php.ini is at $backupPath"
    exit 1
}

if ($RestartApache) {
    if (-not (Test-Path -LiteralPath $httpd)) {
        Write-Warn "httpd.exe not found at $httpd. Restart Apache manually."
    } else {
        Write-Host ""
        Write-Host "Restarting Apache..." -ForegroundColor Cyan
        & $httpd -k restart
        if ($LASTEXITCODE -ne 0) {
            Write-Warn "Apache restart returned code $LASTEXITCODE. Restart it manually from XAMPP Control Panel."
        } else {
            Write-Ok "Apache restarted."
        }
    }
} else {
    Write-Host ""
    Write-Warn "Restart Apache from the XAMPP Control Panel so the web server uses the new php.ini."
}

Write-Host ""
Write-Ok "Done. Backup: $backupPath"
exit 0
