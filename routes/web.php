<?php

use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\BeneficiaryBatchController;
use App\Http\Controllers\BeneficiaryController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EncoderController;
use App\Http\Controllers\EnrollServerController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\LocalSyncController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ReviewInboxController;
use App\Http\Controllers\ReviewItemController;
use App\Http\Controllers\SyncConflictController;
use App\Http\Controllers\SyncRequestController;
use App\Http\Controllers\SyncServerController;
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

// First-time onboarding for an offline installation. It is reachable without
// authentication (there is no account until the Manager is created here) and
// is exempt from the "installation must be configured" redirect.
Route::get('/onboarding', [OnboardingController::class, 'show'])->name('onboarding.show');
Route::post('/onboarding', [OnboardingController::class, 'store'])
    ->middleware('throttle:10,1')
    ->name('onboarding.store');

Route::middleware('auth')->group(function () {
    Route::match(['get', 'post'], '/logout', [LoginController::class, 'destroy'])->name('logout');
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('/beneficiaries', [BeneficiaryController::class, 'index'])->name('beneficiaries.index');
    Route::post('/beneficiaries/duplicate-check', [BeneficiaryController::class, 'duplicateCheck'])->name('beneficiaries.duplicate-check');
    Route::get('/beneficiaries/{beneficiary}', [BeneficiaryController::class, 'show'])->name('beneficiaries.show');
    Route::get('/beneficiaries/{beneficiary}/edit', [BeneficiaryController::class, 'edit'])->name('beneficiaries.edit');
    Route::put('/beneficiaries/{beneficiary}', [BeneficiaryController::class, 'update'])
        ->middleware('offline.authorized')
        ->name('beneficiaries.update');
    Route::post('/beneficiaries/{beneficiary}/archive', [BeneficiaryController::class, 'archive'])
        ->middleware('offline.authorized')
        ->name('beneficiaries.archive');
    Route::post('/beneficiaries/{beneficiary}/unarchive', [BeneficiaryController::class, 'unarchive'])
        ->middleware('offline.authorized')
        ->name('beneficiaries.unarchive');
    Route::delete('/beneficiaries/{beneficiary}', [BeneficiaryController::class, 'destroy'])
        ->middleware(['role:admin,manager', 'offline.authorized'])
        ->name('beneficiaries.destroy');
    Route::post('/beneficiaries/{beneficiary}/duplicate-resolve', [BeneficiaryController::class, 'resolveDuplicate'])
        ->middleware(['role:admin,manager', 'offline.authorized'])
        ->name('beneficiaries.duplicate-resolve');

    // Operational release/payout batches. Batch-specific actions live on the
    // batch management view, not on the beneficiaries list. A beneficiary can
    // only be registered manually from inside a batch, so the batch is always
    // part of the creation context.
    Route::get('/batches', [BeneficiaryBatchController::class, 'index'])->name('beneficiary-batches.index');
    Route::get('/batches/{beneficiaryBatch}', [BeneficiaryBatchController::class, 'show'])
        ->name('beneficiary-batches.show');
    Route::post('/batches', [BeneficiaryBatchController::class, 'store'])
        ->middleware(['role:admin,manager', 'offline.authorized'])
        ->name('beneficiary-batches.store');
    Route::put('/batches/{beneficiaryBatch}', [BeneficiaryBatchController::class, 'update'])
        ->middleware(['role:admin,manager', 'offline.authorized'])
        ->name('beneficiary-batches.update');
    Route::delete('/batches/{beneficiaryBatch}', [BeneficiaryBatchController::class, 'destroy'])
        ->middleware(['role:admin,manager', 'offline.authorized'])
        ->name('beneficiary-batches.destroy');
    Route::get('/batches/{beneficiaryBatch}/beneficiaries/create', [BeneficiaryController::class, 'createInBatch'])
        ->name('beneficiary-batches.beneficiaries.create');
    Route::post('/batches/{beneficiaryBatch}/beneficiaries', [BeneficiaryController::class, 'storeInBatch'])
        ->middleware('offline.authorized')
        ->name('beneficiary-batches.beneficiaries.store');
    Route::delete('/batches/{beneficiaryBatch}/members', [BeneficiaryBatchController::class, 'removeMembers'])
        ->middleware(['role:admin,manager', 'offline.authorized'])
        ->name('beneficiary-batches.members.destroy');
    Route::post('/batches/{beneficiaryBatch}/members', [BeneficiaryBatchController::class, 'addMembers'])
        ->middleware(['role:admin,manager', 'offline.authorized'])
        ->name('beneficiary-batches.members.store');

    Route::middleware('role:admin,staff')->group(function () {
        Route::get('/imports', [ImportController::class, 'index'])->name('imports.index');
        Route::get('/imports/create', [ImportController::class, 'create'])->name('imports.create');
        Route::post('/imports', [ImportController::class, 'store'])
            ->middleware('offline.authorized')
            ->name('imports.store');
        Route::get('/imports/{importBatch}/mapping', [ImportController::class, 'edit'])->name('imports.edit');
        Route::put('/imports/{importBatch}/mapping', [ImportController::class, 'updateMapping'])->name('imports.update-mapping');
        Route::get('/imports/{importBatch}', [ImportController::class, 'show'])->name('imports.show');
        Route::post('/imports/{importBatch}/apply', [ImportController::class, 'apply'])
            ->middleware(['role:admin', 'offline.authorized'])
            ->name('imports.apply');
        Route::post('/imports/{importBatch}/cancel', [ImportController::class, 'cancel'])
            ->middleware('role:admin')
            ->name('imports.cancel');
        Route::post('/imports/{importBatch}/rows/{importRow}/bypass', [ImportController::class, 'bypassRow'])
            ->middleware('role:admin')->name('imports.rows.bypass');
        Route::post('/imports/{importBatch}/duplicates/{importRow}/resolve', [ImportController::class, 'resolveDuplicate'])
            ->middleware('role:admin')->name('imports.duplicates.resolve');
        Route::post('/imports/{importBatch}/invalid-options', [ImportController::class, 'resolveInvalidOption'])
            ->middleware('role:admin')->name('imports.invalid-options.resolve');
        Route::get('/imports/{importBatch}/report', [ImportController::class, 'report'])->name('imports.report');
    });

    Route::get('/locations/search', [LocationController::class, 'search'])->name('locations.search');

    Route::middleware('role:admin,staff,manager')->group(function () {
        Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
        Route::get('/reports/export', [ReportController::class, 'export'])->name('reports.export');

        Route::get('/audit', [AuditLogController::class, 'index'])->name('audit.index');
    });

    // Central server only: admin-only features
    Route::middleware(['role:admin', 'instance.mode:online'])->group(function () {
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::get('/users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
        Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');

        Route::get('/enroll-server', [EnrollServerController::class, 'index'])
            ->name('enroll-server.index');
        Route::post('/enroll-server/packages', [EnrollServerController::class, 'store'])
            ->name('enroll-server.packages.store');
        Route::get('/enroll-server/packages/status', [EnrollServerController::class, 'status'])
            ->name('enroll-server.packages.status');
        Route::get('/enroll-server/packages/{file}/download', [EnrollServerController::class, 'download'])
            ->name('enroll-server.packages.download');
        Route::delete('/enroll-server/packages/{file}', [EnrollServerController::class, 'destroy'])
            ->name('enroll-server.packages.destroy');

        Route::get('/sync-servers', [SyncServerController::class, 'index'])->name('sync-servers.index');
        Route::post('/sync-servers/enrollment-token', [SyncServerController::class, 'createToken'])
            ->name('sync-servers.enrollment-token');
        Route::get('/sync-servers/{syncServer}', [SyncServerController::class, 'show'])->name('sync-servers.show');
        Route::post('/sync-servers/{syncServer}/rotate-token', [SyncServerController::class, 'rotateToken'])
            ->name('sync-servers.rotate-token');
        Route::post('/sync-servers/{syncServer}/reauthorization-token', [SyncServerController::class, 'createReauthorizationToken'])
            ->name('sync-servers.reauthorization-token');
        Route::post('/sync-servers/{syncServer}/prepare-token', [SyncServerController::class, 'createPreparationToken'])
            ->name('sync-servers.prepare-token');
        Route::post('/sync-servers/{syncServer}/revoke', [SyncServerController::class, 'revoke'])
            ->name('sync-servers.revoke');
        Route::put('/sync-servers/{syncServer}/status', [SyncServerController::class, 'updateStatus'])
            ->name('sync-servers.status');
        Route::post('/sync-servers/{syncServer}/scopes', [SyncServerController::class, 'storeScope'])
            ->name('sync-servers.scopes.store');
        Route::delete('/sync-servers/{syncServer}/scopes/{scope}', [SyncServerController::class, 'destroyScope'])
            ->name('sync-servers.scopes.destroy');

        // Online conflict-review UI (central server)
        Route::get('/sync-conflicts', [SyncConflictController::class, 'index'])->name('sync-conflicts.index');
        Route::get('/sync-conflicts/{syncConflict}', [SyncConflictController::class, 'show'])->name('sync-conflicts.show');
        Route::post('/sync-conflicts/{syncConflict}/resolve', [SyncConflictController::class, 'resolve'])
            ->name('sync-conflicts.resolve');

        // Unified review inbox: every pending decision from both surfaces in
        // one list, each row linking to its resolution page.
        Route::get('/review-inbox', [ReviewInboxController::class, 'index'])->name('review-inbox.index');
        Route::get('/review-inbox/{reviewItem}', [ReviewItemController::class, 'show'])->name('review-inbox.show');
        Route::post('/review-inbox/{reviewItem}/decide', [ReviewItemController::class, 'decide'])
            ->name('review-inbox.decide');

        // Pending sync requests submitted by offline servers: request-level
        // review with per-record accept/reject decisions.
        Route::get('/sync-requests', [SyncRequestController::class, 'index'])->name('sync-requests.index');
        Route::get('/sync-requests/{syncRequest}', [SyncRequestController::class, 'show'])->name('sync-requests.show');
        Route::post('/sync-requests/{syncRequest}/items/{item}/approve', [SyncRequestController::class, 'approve'])
            ->name('sync-requests.items.approve');
        Route::post('/sync-requests/{syncRequest}/items/{item}/reject', [SyncRequestController::class, 'reject'])
            ->name('sync-requests.items.reject');
    });

    // Offline server only: manager-only features
    Route::middleware(['instance.mode:offline', 'role:manager'])->group(function () {
        Route::get('/encoders', [EncoderController::class, 'index'])->name('encoders.index');
        Route::post('/encoders', [EncoderController::class, 'store'])->name('encoders.store');
        Route::put('/encoders/{user}', [EncoderController::class, 'update'])->name('encoders.update');

        Route::get('/sync/setup', [LocalSyncController::class, 'index'])->name('sync.local.index');
        Route::post('/sync/setup/enroll', [LocalSyncController::class, 'enroll'])->name('sync.local.enroll');
        Route::post('/sync/setup/reauthorize', [LocalSyncController::class, 'reauthorize'])->name('sync.local.reauthorize');
        Route::post('/sync/setup/download', [LocalSyncController::class, 'download'])->name('sync.local.download');
        Route::post('/sync/setup/clean-ghosts', [LocalSyncController::class, 'cleanGhostBatches'])->name('sync.local.clean-ghosts');
        Route::post('/sync/setup/run', [LocalSyncController::class, 'run'])->name('sync.local.run');
        Route::post('/sync/setup/prepare', [LocalSyncController::class, 'prepare'])->name('sync.local.prepare');
        Route::post('/sync/setup/sync-now', [LocalSyncController::class, 'syncNow'])->name('sync.local.sync-now');
        Route::post('/sync/setup/retry-rejected', [LocalSyncController::class, 'retryRejected'])->name('sync.local.retry-rejected');
        Route::get('/sync/setup/connection', [LocalSyncController::class, 'checkConnection'])->name('sync.local.connection');
        Route::post('/sync/setup/app-update/check', [LocalSyncController::class, 'checkAppUpdate'])->name('sync.local.app-update.check');
        Route::post('/sync/setup/app-update/apply', [LocalSyncController::class, 'applyAppUpdate'])->name('sync.local.app-update.apply');
        Route::get('/sync/setup/app-update/status', [LocalSyncController::class, 'appUpdateStatus'])->name('sync.local.app-update.status');
    });
});
