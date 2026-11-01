<?php

namespace App\Http\Controllers\Api\Sync;

use App\Http\Controllers\Controller;
use App\Http\Resources\Sync\BeneficiarySyncResource;
use App\Models\Beneficiary;
use App\Models\BeneficiaryBatch;
use App\Models\Location;
use App\Models\OfflineServer;
use App\Models\OfflineServerUpdate;
use App\Models\SyncChange;
use App\Models\SyncSnapshot;
use App\Models\Training;
use App\Services\Sync\ScopeService;
use App\Support\SyncIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SnapshotController extends Controller
{
    public function store(Request $request, ScopeService $scopes): JsonResponse
    {
        $server = $this->server($request);
        $beneficiaryQuery = $scopes->applyToBeneficiaryQuery(Beneficiary::query(), $server);
        $locationQuery = $scopes->applyToLocationQuery(Location::query(), $server);
        $beneficiaryCount = (clone $beneficiaryQuery)->count();
        $locationCount = (clone $locationQuery)->count();
        $batchCount = BeneficiaryBatch::withTrashed()->count();
        $trainingCount = Training::query()->count();

        $snapshot = SyncSnapshot::create([
            'snapshot_uuid' => SyncIdentity::newUuid(),
            'offline_server_id' => $server->id,
            'status' => 'ready',
            'revision_to' => (int) SyncChange::query()->max('id'),
            'row_count' => $beneficiaryCount,
            'expires_at' => now()->addHours(2),
            'completed_at' => now(),
            'manifest' => [
                'beneficiaries' => $beneficiaryCount,
                'locations' => $locationCount,
                'batches' => $batchCount,
                'trainings' => $trainingCount,
                'page_size' => (int) config('sync.snapshot_page_size', 500),
                'scope_prefixes' => $scopes->prefixes($server),
            ],
        ]);

        return response()->json([
            'data' => [
                'snapshot_uuid' => $snapshot->snapshot_uuid,
                'status' => $snapshot->status,
                'created_at' => $snapshot->created_at?->toIso8601String(),
                'expires_at' => $snapshot->expires_at?->toIso8601String(),
                'revision_to' => $snapshot->revision_to,
                'manifest' => $snapshot->manifest,
            ],
        ], 201);
    }

    public function beneficiaries(
        Request $request,
        string $snapshotUuid,
        ScopeService $scopes,
    ): JsonResponse {
        $snapshot = $this->snapshot($request, $snapshotUuid);

        if ($this->expired($snapshot)) {
            return $this->error('snapshot_expired', 'This snapshot has expired.', 410);
        }

        $data = $request->validate([
            'cursor' => ['nullable', 'integer', 'min:0'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        $limit = (int) ($data['limit'] ?? config('sync.snapshot_page_size', 500));
        $cursor = isset($data['cursor']) ? (int) $data['cursor'] : null;

        $query = $scopes->applyToBeneficiaryQuery(Beneficiary::query(), $snapshot->server)
            ->with('beneficiaryBatch:id,global_uuid')
            ->orderBy('id');

        if ($cursor !== null) {
            $query->where('id', '>', $cursor);
        }

        $rows = $query->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $page = $hasMore ? $rows->take($limit) : $rows;

        return response()->json([
            'data' => BeneficiarySyncResource::collection($page)->resolve($request),
            'meta' => [
                'snapshot_uuid' => $snapshot->snapshot_uuid,
                'next_cursor' => $page->last()?->id,
                'has_more' => $hasMore,
                'count' => $page->count(),
            ],
        ]);
    }

    public function reference(
        Request $request,
        string $snapshotUuid,
        ScopeService $scopes,
    ): JsonResponse {
        $snapshot = $this->snapshot($request, $snapshotUuid);

        if ($this->expired($snapshot)) {
            return $this->error('snapshot_expired', 'This snapshot has expired.', 410);
        }

        $locations = $scopes->applyToLocationQuery(Location::query(), $snapshot->server)
            ->orderBy('psgc_code')
            ->get()
            ->map(fn (Location $location) => [
                'psgc_code' => $location->psgc_code,
                'barangay' => $location->barangay,
                'city_municipality' => $location->city_municipality,
                'province' => $location->province,
                'full_address' => $location->full_address,
                'is_city' => $location->is_city,
            ])
            ->all();

        $batches = BeneficiaryBatch::withTrashed()
            ->orderBy('id')
            ->get()
            ->map(fn (BeneficiaryBatch $batch) => [
                'global_uuid' => $batch->global_uuid,
                'origin_server_uuid' => $batch->origin_server_uuid,
                'name' => $batch->name,
                'description' => $batch->description,
                'status' => $batch->status,
                'manager_uuid' => $batch->manager_uuid,
                'revision' => $batch->revision,
                'deleted_at' => $batch->deleted_at?->toIso8601String(),
            ])
            ->all();

        $trainings = Training::query()
            ->orderBy('category')
            ->orderBy('name')
            ->get()
            ->map(fn (Training $training) => [
                'category' => $training->category,
                'name' => $training->name,
            ])
            ->all();

        return response()->json([
            'data' => [
                'locations' => $locations,
                'batches' => $batches,
                'trainings' => $trainings,
            ],
            'meta' => [
                'snapshot_uuid' => $snapshot->snapshot_uuid,
                'location_count' => count($locations),
                'batch_count' => count($batches),
                'training_count' => count($trainings),
            ],
        ]);
    }

    private function snapshot(Request $request, string $snapshotUuid): SyncSnapshot
    {
        return SyncSnapshot::query()
            ->with('server')
            ->where('snapshot_uuid', $snapshotUuid)
            ->where('offline_server_id', $this->server($request)->id)
            ->firstOrFail();
    }

    private function server(Request $request): OfflineServer
    {
        /** @var OfflineServer $server */
        $server = $request->attributes->get('sync_server');

        return $server;
    }

    private function expired(SyncSnapshot $snapshot): bool
    {
        return $snapshot->expires_at !== null && $snapshot->expires_at->isPast();
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], $status);
    }

    /**
     * Store an update package from the central server for offline servers to fetch.
     */
    public function updatePackage(Request $request): JsonResponse
    {
        $server = $this->server($request);

        $validated = $request->validate([
            'package' => ['required', 'file', 'mimes:zip'],
            'label' => ['required', 'string', 'max:120'],
            'size' => ['required', 'integer', 'min:1'],
            'sha256' => ['required', 'string', 'size:64'],
            'files' => ['required', 'integer', 'min:0'],
        ]);

        $file = $request->file('package');

        // Verify SHA256 checksum
        $actualSha256 = hash_file('sha256', $file->getRealPath());
        if (! hash_equals($validated['sha256'], $actualSha256)) {
            return $this->error('checksum_mismatch', 'Package SHA256 checksum does not match.', 422);
        }

        // Verify size
        if ($file->getSize() !== $validated['size']) {
            return $this->error('size_mismatch', 'Package size does not match.', 422);
        }

        // Store the update package
        $filename = $file->getClientOriginalName();
        $path = $file->storeAs('offline-updates', $filename, 'private');

        OfflineServerUpdate::create([
            'offline_server_id' => $server->id,
            'filename' => $filename,
            'path' => $path,
            'size' => $validated['size'],
            'sha256' => $validated['sha256'],
            'files' => $validated['files'],
            'label' => $validated['label'],
            'status' => 'available',
        ]);

        return response()->json([
            'data' => [
                'message' => 'Update package uploaded successfully.',
                'filename' => $filename,
                'size' => $validated['size'],
            ],
        ], 201);
    }

    /**
     * Check for available updates for an offline server.
     */
    public function checkUpdate(Request $request): JsonResponse
    {
        $server = $this->server($request);

        $update = OfflineServerUpdate::query()
            ->where('offline_server_id', $server->id)
            ->where('status', 'available')
            ->latest('created_at')
            ->first();

        if (! $update) {
            // A server enrolled after a package was published has no
            // per-server update row. Adopt the latest available package from
            // another server so newly enrolled devices can still receive it.
            $templates = OfflineServerUpdate::query()
                ->where('status', 'available')
                ->where('offline_server_id', '!=', $server->id)
                ->latest('created_at')
                ->limit(10)
                ->get();

            $template = $templates->first(
                fn (OfflineServerUpdate $candidate): bool => Storage::disk('private')->exists($candidate->path)
            );

            if ($template && ! OfflineServerUpdate::query()
                ->where('offline_server_id', $server->id)
                ->where('filename', $template->filename)
                ->exists()) {
                $update = OfflineServerUpdate::query()->create([
                    'offline_server_id' => $server->id,
                    'filename' => $template->filename,
                    'path' => $template->path,
                    'size' => $template->size,
                    'sha256' => $template->sha256,
                    'files' => $template->files,
                    'label' => $template->label,
                    'status' => 'available',
                ]);
            }
        }

        if (! $update) {
            return response()->json([
                'data' => [
                    'has_update' => false,
                    'current_version' => config('app.version', '1.0.0'),
                ],
            ]);
        }

        return response()->json([
            'data' => [
                'has_update' => true,
                'current_version' => config('app.version', '1.0.0'),
                'latest_version' => $update->label,
                'filename' => $update->filename,
                'size' => $update->size,
                'sha256' => $update->sha256,
                'download_url' => route('api.sync.v1.app-update.download', $update->filename),
                'created_at' => $update->created_at->toIso8601String(),
            ],
        ]);
    }

    /**
     * Download an update package.
     */
    public function downloadUpdate(Request $request, string $filename): BinaryFileResponse
    {
        $server = $this->server($request);

        $update = OfflineServerUpdate::query()
            ->where('offline_server_id', $server->id)
            ->where('filename', $filename)
            ->where('status', 'available')
            ->firstOrFail();

        $path = Storage::disk('private')->path($update->path);

        abort_if(! file_exists($path), 404);

        return response()->download($path, $filename, [
            'Content-Type' => 'application/zip',
        ]);
    }

    /**
     * Mark a published package as applied so it is no longer offered.
     */
    public function ackUpdate(Request $request): JsonResponse
    {
        $server = $this->server($request);

        $validated = $request->validate([
            'sha256' => ['required', 'string', 'size:64'],
        ]);

        OfflineServerUpdate::query()
            ->where('offline_server_id', $server->id)
            ->where('sha256', strtolower($validated['sha256']))
            ->update(['status' => 'applied']);

        return response()->json([
            'data' => [
                'message' => 'Update acknowledged.',
            ],
        ]);
    }
}
