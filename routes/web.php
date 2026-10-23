<?php

use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\BeneficiaryBatchController;
use App\Http\Controllers\BeneficiaryController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\IdCardController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('dashboard');
});

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])
        ->middleware('throttle:5,1')
        ->name('login.attempt');
});

Route::middleware('auth')->group(function () {
    Route::match(['get', 'post'], '/logout', [LoginController::class, 'destroy'])->name('logout');
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('/beneficiaries', [BeneficiaryController::class, 'index'])->name('beneficiaries.index');
    Route::post('/beneficiaries/duplicate-check', [BeneficiaryController::class, 'duplicateCheck'])->name('beneficiaries.duplicate-check');
    Route::get('/beneficiaries/{beneficiary}', [BeneficiaryController::class, 'show'])->name('beneficiaries.show');
    Route::get('/beneficiaries/{beneficiary}/edit', [BeneficiaryController::class, 'edit'])->name('beneficiaries.edit');
    Route::put('/beneficiaries/{beneficiary}', [BeneficiaryController::class, 'update'])->name('beneficiaries.update');
    Route::post('/beneficiaries/{beneficiary}/archive', [BeneficiaryController::class, 'archive'])->name('beneficiaries.archive');
    Route::post('/beneficiaries/{beneficiary}/unarchive', [BeneficiaryController::class, 'unarchive'])->name('beneficiaries.unarchive');
    Route::post('/beneficiaries/{beneficiary}/duplicate-resolve', [BeneficiaryController::class, 'resolveDuplicate'])
        ->middleware('role:admin')
        ->name('beneficiaries.duplicate-resolve');

    // Operational release/payout batches. Batch-specific actions live on the
    // batch management view, not on the beneficiaries list. A beneficiary can
    // only be registered manually from inside a batch, so the batch is always
    // part of the creation context.
    Route::get('/batches', [BeneficiaryBatchController::class, 'index'])->name('beneficiary-batches.index');
    Route::get('/batches/{beneficiaryBatch}', [BeneficiaryBatchController::class, 'show'])
        ->name('beneficiary-batches.show');
    Route::post('/batches', [BeneficiaryBatchController::class, 'store'])
        ->middleware('role:admin')
        ->name('beneficiary-batches.store');
    Route::put('/batches/{beneficiaryBatch}', [BeneficiaryBatchController::class, 'update'])
        ->middleware('role:admin')
        ->name('beneficiary-batches.update');
    Route::delete('/batches/{beneficiaryBatch}', [BeneficiaryBatchController::class, 'destroy'])
        ->middleware('role:admin')
        ->name('beneficiary-batches.destroy');
    Route::get('/batches/{beneficiaryBatch}/beneficiaries/create', [BeneficiaryController::class, 'createInBatch'])
        ->name('beneficiary-batches.beneficiaries.create');
    Route::post('/batches/{beneficiaryBatch}/beneficiaries', [BeneficiaryController::class, 'storeInBatch'])
        ->name('beneficiary-batches.beneficiaries.store');
    Route::delete('/batches/{beneficiaryBatch}/members', [BeneficiaryBatchController::class, 'removeMembers'])
        ->middleware('role:admin')
        ->name('beneficiary-batches.members.destroy');

    Route::middleware('role:admin,staff')->group(function () {
        Route::get('/imports', [ImportController::class, 'index'])->name('imports.index');
        Route::get('/imports/create', [ImportController::class, 'create'])->name('imports.create');
        Route::post('/imports', [ImportController::class, 'store'])->name('imports.store');
        Route::get('/imports/{importBatch}/mapping', [ImportController::class, 'edit'])->name('imports.edit');
        Route::put('/imports/{importBatch}/mapping', [ImportController::class, 'updateMapping'])->name('imports.update-mapping');
        Route::get('/imports/{importBatch}', [ImportController::class, 'show'])->name('imports.show');
        Route::post('/imports/{importBatch}/apply', [ImportController::class, 'apply'])
            ->middleware('role:admin')
            ->name('imports.apply');
        Route::post('/imports/{importBatch}/cancel', [ImportController::class, 'cancel'])
            ->middleware('role:admin')
            ->name('imports.cancel');
        Route::get('/imports/{importBatch}/report', [ImportController::class, 'report'])->name('imports.report');

        Route::get('/id-cards', [IdCardController::class, 'index'])->name('id-cards.index');
        Route::get('/id-cards/print', [IdCardController::class, 'print'])->name('id-cards.print');
        Route::get('/beneficiaries/{beneficiary}/card', [IdCardController::class, 'single'])->name('beneficiaries.card');
    });

    Route::get('/locations/search', [LocationController::class, 'search'])->name('locations.search');

    Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('/reports/export', [ReportController::class, 'export'])->name('reports.export');

    Route::middleware('role:admin')->group(function () {
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::get('/users/create', [UserController::class, 'create'])->name('users.create');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::get('/users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
        Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');

        Route::get('/audit', [AuditLogController::class, 'index'])->name('audit.index');
    });
});
