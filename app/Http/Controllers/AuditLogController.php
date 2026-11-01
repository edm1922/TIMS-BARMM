<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Beneficiary;
use App\Models\OfflineServer;
use App\Models\OfflineServerEncoder;
use App\Models\User;
use App\Support\AuditActions;
use App\Support\SyncIdentity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class AuditLogController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', AuditLog::class);

        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'action' => (string) $request->query('action', ''),
            'user_id' => (string) $request->query('user_id', ''),
            'origin' => (string) $request->query('origin', ''),
            'created_from' => $this->dateOrNull($request->query('created_from')),
            'created_to' => $this->dateOrNull($request->query('created_to')),
        ];

        $query = AuditLog::query()->with('user')->latest('created_at')->latest('id');

        if ($filters['q'] !== '') {
            $like = '%'.$filters['q'].'%';
            $query->where(fn (Builder $q) => $q
                ->where('action', 'like', $like)
                ->orWhere('entity_type', 'like', $like)
                ->orWhere('ip', 'like', $like)
                ->orWhere('actor_username', 'like', $like)
                ->orWhereHas('user', fn (Builder $u) => $u
                    ->where('name', 'like', $like)
                    ->orWhere('username', 'like', $like)
                    ->orWhere('email', 'like', $like)));
        }

        if ($filters['action'] !== '') {
            $query->whereIn('action', AuditActions::matches($filters['action']));
        }

        if ($filters['user_id'] !== '' && ctype_digit($filters['user_id'])) {
            $query->where('user_id', (int) $filters['user_id']);
        }

        if ($filters['origin'] !== '') {
            $query->where('origin_server_uuid', $filters['origin']);
        }

        if ($filters['created_from'] !== null) {
            $query->whereDate('created_at', '>=', $filters['created_from']);
        }

        if ($filters['created_to'] !== null) {
            $query->whereDate('created_at', '<=', $filters['created_to']);
        }

        $logs = $query->paginate(25)->withQueryString();

        return view('audit.index', [
            'filters' => $filters,
            'logs' => $logs,
            'beneficiaryNames' => $this->beneficiaryNames($logs),
            'actorNames' => $this->actorNames($logs),
            'roleLabels' => User::ROLE_LABELS,
            'actionGroups' => AuditActions::options(
                AuditLog::query()
                    ->selectRaw('action, COUNT(*) as aggregate')
                    ->groupBy('action')
                    ->pluck('aggregate', 'action')
                    ->all()
            ),
            'actionLabels' => AuditActions::labels(),
            'users' => User::query()->orderBy('name')->get(['id', 'name']),
            'origins' => AuditLog::query()
                ->whereNotNull('origin_server_uuid')
                ->select('origin_server_uuid')
                ->distinct()
                ->orderBy('origin_server_uuid')
                ->pluck('origin_server_uuid'),
        ]);
    }

    /**
     * The person behind each entry on this page. A local entry is named from
     * its user account; an entry that came from another installation carries
     * the account's name (or, for older entries, is named through the account
     * registry that installation reports). Only when no name is known is the
     * username shown.
     *
     * @param  LengthAwarePaginator<int, AuditLog>  $logs
     * @return array<int, string>
     */
    private function actorNames($logs): array
    {
        $items = collect($logs->items());
        $localOrigin = (string) SyncIdentity::originUuid();

        $serverIds = OfflineServer::query()
            ->whereIn('server_uuid', $items->pluck('origin_server_uuid')->filter()->unique()->values())
            ->pluck('id', 'server_uuid');

        $registry = [];

        $wanted = $items
            ->filter(fn (AuditLog $log): bool => blank($log->actor_name) && $log->actor_uuid !== null)
            ->groupBy(fn (AuditLog $log): ?int => $serverIds[$log->origin_server_uuid] ?? null)
            ->filter(fn ($group, $serverId): bool => $serverId !== null && $serverId !== '');

        if ($wanted->isNotEmpty()) {
            OfflineServerEncoder::query()
                ->whereIn('offline_server_id', $wanted->keys())
                ->get(['offline_server_id', 'encoder_uuid', 'name'])
                ->each(function (OfflineServerEncoder $encoder) use (&$registry): void {
                    if (is_string($encoder->name) && $encoder->name !== '') {
                        $registry[$encoder->offline_server_id.'|'.$encoder->encoder_uuid] = $encoder->name;
                    }
                });
        }

        $names = [];

        foreach ($items as $log) {
            $name = $log->actor_name;
            $origin = (string) ($log->origin_server_uuid ?? '');
            $isLocal = $origin === '' || $origin === $localOrigin;

            if (blank($name) && ! $isLocal) {
                $serverId = $serverIds[$log->origin_server_uuid] ?? null;

                if ($serverId !== null && $log->actor_uuid !== null) {
                    $name = $registry[$serverId.'|'.$log->actor_uuid] ?? null;
                }
            }

            if (blank($name) && $isLocal) {
                $name = $log->user?->name;
            }

            if (is_string($name) && $name !== '') {
                $names[$log->id] = $name;
            }
        }

        return $names;
    }

    /**
     * Names for the beneficiaries referenced by the metadata of each entry on
     * this page, so operators read a name instead of a bare row id.
     *
     * An entry that came from another installation carries that installation's
     * own record ids, so those records are found through the beneficiary code
     * the same installation recorded for them. Records deleted since the entry
     * was written are named from their own deletion entry.
     *
     * @param  LengthAwarePaginator<int, AuditLog>  $logs
     * @return array<int, array<int, string>>
     */
    private function beneficiaryNames($logs): array
    {
        $localOrigin = (string) SyncIdentity::originUuid();

        $originByLog = [];
        $idsByLog = [];
        $localIds = [];
        $foreign = [];

        foreach ($logs->items() as $log) {
            $ids = $log->metadata['beneficiary_ids'] ?? null;

            if (! is_array($ids)) {
                continue;
            }

            $ids = array_values(array_unique(array_map(
                fn ($id): int => (int) $id,
                array_filter($ids, fn ($id): bool => is_numeric($id)),
            )));

            if ($ids === []) {
                continue;
            }

            $origin = (string) ($log->origin_server_uuid ?? '');
            $origin = $origin === '' ? $localOrigin : $origin;

            $originByLog[$log->id] = $origin;
            $idsByLog[$log->id] = $ids;

            foreach ($ids as $id) {
                if ($origin === $localOrigin) {
                    $localIds[$id] = $id;
                } else {
                    $foreign[$origin.'|'.$id] = ['origin' => $origin, 'id' => $id];
                }
            }
        }

        if ($idsByLog === []) {
            return [];
        }

        $names = [];

        foreach ($this->localBeneficiaryNames(array_values($localIds)) as $id => $name) {
            $names[$localOrigin.'|'.$id] = $name;
        }

        $names += $this->foreignBeneficiaryNames($foreign);

        $byLog = [];

        foreach ($idsByLog as $logId => $ids) {
            $origin = $originByLog[$logId];

            foreach ($ids as $id) {
                $name = $names[$origin.'|'.$id] ?? null;

                if ($name !== null) {
                    $byLog[$logId][$id] = $name;
                }
            }
        }

        return $byLog;
    }

    /**
     * @param  array<int, int>  $ids
     * @return array<int, string>
     */
    private function localBeneficiaryNames(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $names = Beneficiary::query()
            ->whereIn('id', $ids)
            ->get(['id', 'first_name', 'middle_name', 'last_name', 'extension_name'])
            ->mapWithKeys(fn (Beneficiary $beneficiary): array => [$beneficiary->id => $beneficiary->full_name])
            ->all();

        $missing = array_values(array_diff($ids, array_keys($names)));

        if ($missing === []) {
            return $names;
        }

        AuditLog::query()
            ->where('action', 'beneficiary.deleted')
            ->whereIn('entity_id', $missing)
            ->get(['entity_id', 'metadata'])
            ->each(function (AuditLog $log) use (&$names): void {
                $name = $log->metadata['full_name'] ?? null;

                if (is_string($name) && $name !== '' && ! isset($names[(int) $log->entity_id])) {
                    $names[(int) $log->entity_id] = $name;
                }
            });

        return $names;
    }

    /**
     * Records created by another installation are named through the
     * beneficiary code that installation recorded for the same local id.
     *
     * @param  array<string, array{origin: string, id: int}>  $foreign
     * @return array<string, string>
     */
    private function foreignBeneficiaryNames(array $foreign): array
    {
        if ($foreign === []) {
            return [];
        }

        $codes = AuditLog::query()
            ->whereIn('entity_id', array_values(array_unique(array_column($foreign, 'id'))))
            ->whereNotNull('origin_server_uuid')
            ->get(['origin_server_uuid', 'entity_id', 'metadata'])
            ->mapWithKeys(function (AuditLog $log): array {
                $code = $log->metadata['beneficiary_code'] ?? null;

                if (! is_string($code) || $code === '') {
                    return [];
                }

                return [$log->origin_server_uuid.'|'.(int) $log->entity_id => $code];
            })
            ->all();

        $codes = array_intersect_key($codes, $foreign);

        if ($codes === []) {
            return [];
        }

        $namesByCode = Beneficiary::query()
            ->whereIn('beneficiary_code', array_values($codes))
            ->get(['beneficiary_code', 'first_name', 'middle_name', 'last_name', 'extension_name'])
            ->mapWithKeys(fn (Beneficiary $beneficiary): array => [$beneficiary->beneficiary_code => $beneficiary->full_name])
            ->all();

        $names = [];

        foreach ($codes as $key => $code) {
            if (isset($namesByCode[$code])) {
                $names[$key] = $namesByCode[$code];
            }
        }

        return $names;
    }

    private function dateOrNull(mixed $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value ? $value : null;
    }
}
