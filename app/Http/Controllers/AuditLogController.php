<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
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
                ->orWhereHas('user', fn (Builder $u) => $u
                    ->where('name', 'like', $like)
                    ->orWhere('username', 'like', $like)
                    ->orWhere('email', 'like', $like)));
        }

        if ($filters['action'] !== '') {
            $query->where('action', $filters['action']);
        }

        if ($filters['user_id'] !== '' && ctype_digit($filters['user_id'])) {
            $query->where('user_id', (int) $filters['user_id']);
        }

        if ($filters['created_from'] !== null) {
            $query->whereDate('created_at', '>=', $filters['created_from']);
        }

        if ($filters['created_to'] !== null) {
            $query->whereDate('created_at', '<=', $filters['created_to']);
        }

        return view('audit.index', [
            'filters' => $filters,
            'logs' => $query->paginate(25)->withQueryString(),
            'actions' => AuditLog::query()->select('action')->distinct()->orderBy('action')->pluck('action'),
            'users' => User::query()->orderBy('name')->get(['id', 'name']),
        ]);
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
