<#
.SYNOPSIS
    Remove known zero-member orphan batches from a TIMS-BARMM installation.

.DESCRIPTION
    Targets the historical PAHAMUDDIN / DATU PIANG / TEST3 batches that were
    left behind as soft-deleted, zero-member tombstones. The command is a dry
    run unless -Force is supplied.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File .\scripts\purge-orphan-batches.ps1
    powershell -ExecutionPolicy Bypass -File .\scripts\purge-orphan-batches.ps1 -Force
#>

[CmdletBinding()]
param (
    [string] $ProjectRoot = "C:\xampp\htdocs\TIMS-BARMM",
    [string] $XamppRoot   = "C:\xampp",
    [switch] $Force
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

$phpExe = Join-Path $XamppRoot "php\php.exe"
$artisan = Join-Path $ProjectRoot "artisan"

if (-not (Test-Path -LiteralPath $phpExe)) {
    Throw "PHP not found: $phpExe"
}

if (-not (Test-Path -LiteralPath $artisan)) {
    Throw "Artisan not found: $artisan"
}

Push-Location $ProjectRoot
try {
    $hasCommand = (& $phpExe $artisan "list" "--raw" 2>$null | Select-String -SimpleMatch "batches:purge-orphans")

    if ($hasCommand) {
        $arguments = @($artisan, "batches:purge-orphans", "--no-interaction")
        if ($Force) { $arguments += "--force" }

        & $phpExe @arguments
        exit $LASTEXITCODE
    }

    # Fallback for installations that have not received the command yet.
    $template = @'
$names = [
    'PAHAMUDDIN, SGA, BARMM',
    'PAHAMUDDIN, SGA, BARMM (1,000)',
    'PAHAMUDIN, SGA, BARMM',
    'PAHAMUDIN, SGA, BARMM (1000)',
    'TEST3',
    'DATU PIANG',
    'PROFILE OF TUPAD BENEFICIARIES-PAHAMUDDIN, SGA, BARMM (1,000)',
    'PROFILE OF TUPAD BENEFICIARIES PAHAMUDIN, SGA, BARMM (1000)',
];
$force = __FORCE__;
$batches = \App\Models\BeneficiaryBatch::withTrashed()
    ->withCount('beneficiaries')
    ->get()
    ->filter(fn ($batch) => $batch->trashed()
        && in_array($batch->name, $names, true)
        && (int) $batch->beneficiaries_count === 0);

if ($batches->isEmpty()) {
    echo "No matching zero-member orphan batches found." . PHP_EOL;
    return;
}

foreach ($batches as $batch) {
    echo $batch->id . "  " . $batch->name . PHP_EOL;
}

if ($force) {
    foreach ($batches as $batch) {
        $batch->forceDelete();
    }
    echo "Permanently removed " . $batches->count() . " orphan batch(es)." . PHP_EOL;
} else {
    echo "Dry run only. Run this script with -Force to delete them." . PHP_EOL;
}
'@

    $code = $template.Replace('__FORCE__', $(if ($Force) { 'true' } else { 'false' }))
    & $phpExe $artisan "tinker" "--execute=$code" "--no-interaction"
    exit $LASTEXITCODE
}
finally {
    Pop-Location
}
