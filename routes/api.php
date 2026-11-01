<?php

use App\Http\Controllers\Api\Sync\AuditEventController;
use App\Http\Controllers\Api\Sync\ChangeController;
use App\Http\Controllers\Api\Sync\ConflictController;
use App\Http\Controllers\Api\Sync\EnrollmentController;
use App\Http\Controllers\Api\Sync\HeartbeatController;
use App\Http\Controllers\Api\Sync\OperationController;
use App\Http\Controllers\Api\Sync\OutcomeController;
use App\Http\Controllers\Api\Sync\PreparationController;
use App\Http\Controllers\Api\Sync\ReauthorizationController;
use App\Http\Controllers\Api\Sync\SnapshotController;
use Illuminate\Support\Facades\Route;

Route::prefix('sync/v1')
    ->name('api.sync.v1.')
    ->group(function (): void {
        Route::get('/compatibility', [EnrollmentController::class, 'compatibility'])
            ->name('compatibility');

        Route::post('/enroll', [EnrollmentController::class, 'enroll'])
            ->middleware('throttle:10,1')
            ->name('enroll');

        Route::middleware(['sync.server', 'throttle:120,1'])->group(function (): void {
            Route::post('/heartbeat', [HeartbeatController::class, 'store'])
                ->name('heartbeat');

            Route::get('/health', [HeartbeatController::class, 'health'])
                ->name('health');

            // Reauthorization restores the installation's write and read
            // access. It never changes audit, sync, or conflict history.
            Route::post('/reauthorize', [ReauthorizationController::class, 'store'])
                ->middleware('sync.authorized:none')
                ->name('reauthorize');

            // Prepare for a new encoding session: full sync + new authorization token.
            // Uses sync.authorized:none so it can be called even with expired authorization,
            // as the preparation token itself validates and extends the authorization.
            Route::post('/prepare', [PreparationController::class, 'store'])
                ->middleware(['sync.authorized:none', 'throttle:10,1'])
                ->name('prepare');

            Route::get('/snapshot', [SnapshotController::class, 'store'])
                ->middleware('sync.authorized:read')
                ->name('snapshot.store');
            Route::get('/snapshot/{snapshotUuid}/beneficiaries', [SnapshotController::class, 'beneficiaries'])
                ->middleware('sync.authorized:read')
                ->name('snapshot.beneficiaries');
            Route::get('/snapshot/{snapshotUuid}/reference', [SnapshotController::class, 'reference'])
                ->middleware('sync.authorized:read')
                ->name('snapshot.reference');

            Route::get('/changes', [ChangeController::class, 'index'])
                ->middleware('sync.authorized:read')
                ->name('changes');

            Route::post('/operations', [OperationController::class, 'store'])
                ->middleware('sync.authorized:write')
                ->name('operations.store');
            Route::post('/ack', [OperationController::class, 'ack'])
                ->middleware('sync.authorized:read')
                ->name('ack');

            // Per-record review decisions for records the central server held
            // (duplicates and invalid submissions). Polling is idempotent.
            Route::get('/request-outcomes', [OutcomeController::class, 'index'])
                ->middleware('sync.authorized:read')
                ->name('request-outcomes.index');

            Route::post('/audit-events', [AuditEventController::class, 'store'])
                ->middleware('sync.authorized:write')
                ->name('audit-events.store');

            Route::post('/update-package', [SnapshotController::class, 'updatePackage'])
                ->middleware('sync.authorized:write')
                ->name('update-package.store');

            Route::get('/conflicts', [ConflictController::class, 'index'])
                ->middleware('sync.authorized:read')
                ->name('conflicts.index');
            Route::post('/conflicts/{conflictUuid}/resolve', [ConflictController::class, 'resolve'])
                ->middleware('sync.authorized:write')
                ->name('conflicts.resolve');

            // Application packages published from the central server. Reading
            // is enough: an offline server must be able to fetch a fix even
            // when its encoding authorization has lapsed.
            Route::get('/app-update/check', [SnapshotController::class, 'checkUpdate'])
                ->middleware('sync.authorized:read')
                ->name('app-update.check');
            Route::get('/app-update/download/{filename}', [SnapshotController::class, 'downloadUpdate'])
                ->middleware('sync.authorized:read')
                ->name('app-update.download');
            Route::post('/app-update/ack', [SnapshotController::class, 'ackUpdate'])
                ->middleware('sync.authorized:read')
                ->name('app-update.ack');
        });
    });
