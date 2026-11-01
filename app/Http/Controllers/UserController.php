<?php

namespace App\Http\Controllers;

use App\Models\OfflineServerEncoder;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class UserController extends Controller
{
    public const ROLES = [
        User::ROLE_ADMIN => 'Administrator',
        User::ROLE_ENCODER => 'Encoder',
        User::ROLE_STAFF => 'Staff',
    ];

    public const ROLE_LABELS = [
        User::ROLE_ADMIN => 'Administrator',
        User::ROLE_MANAGER => 'Manager',
        User::ROLE_ENCODER => 'Encoder',
        User::ROLE_STAFF => 'Staff',
    ];

    public function index(Request $request): View
    {
        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'role' => (string) $request->query('role', ''),
            'status' => (string) $request->query('status', ''),
        ];

        $query = User::query()->latest('id');

        if ($filters['q'] !== '') {
            $term = '%'.$filters['q'].'%';
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', $term)
                    ->orWhere('username', 'like', $term)
                    ->orWhere('email', 'like', $term);
            });
        }

        if (array_key_exists($filters['role'], self::ROLES)) {
            $query->where('role', $filters['role']);
        }

        if ($filters['status'] === 'active') {
            $query->where('is_active', true);
        } elseif ($filters['status'] === 'inactive') {
            $query->where('is_active', false);
        }

        $users = $query->paginate(20)->withQueryString();

        $offlineAccounts = OfflineServerEncoder::query()
            ->with('server')
            ->orderByDesc('reported_at')
            ->orderBy('username')
            ->limit(100)
            ->get();

        $roleCounts = [
            'total' => User::count(),
            'admin' => User::where('role', User::ROLE_ADMIN)->count(),
            'encoder' => User::where('role', User::ROLE_ENCODER)->count(),
            'staff' => User::where('role', User::ROLE_STAFF)->count(),
            'inactive' => User::where('is_active', false)->count(),
        ];

        return view('users.index', [
            'users' => $users,
            'offlineAccounts' => $offlineAccounts,
            'filters' => $filters,
            'roles' => self::ROLES,
            'roleCounts' => $roleCounts,
        ]);
    }

    public function edit(User $user): View
    {
        return view('users.edit', [
            'user' => $user,
            'roles' => self::ROLES,
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:50', 'alpha_dash', Rule::unique('users', 'username')->ignore($user->id)],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
            'role' => ['required', Rule::in(array_keys(self::ROLES))],
            'is_active' => ['nullable', 'boolean'],
        ]);

        // Guard against an admin locking themselves out
        if ($user->id === auth()->id()) {
            if ($validated['role'] !== User::ROLE_ADMIN) {
                return back()
                    ->withInput()
                    ->withErrors(['role' => 'You cannot change your own administrator role.']);
            }
            if (! $request->boolean('is_active', true)) {
                return back()
                    ->withInput()
                    ->withErrors(['is_active' => 'You cannot deactivate your own account.']);
            }
        }

        $data = [
            'name' => trim($validated['name']),
            'username' => strtolower(trim($validated['username'])),
            'email' => strtolower(trim($validated['email'])),
            'role' => $validated['role'],
            'is_active' => $request->boolean('is_active', true),
        ];

        if (! empty($validated['password'])) {
            $data['password'] = $validated['password'];
        }

        $user->update($data);

        AuditLogger::log('user.updated', 'user', $user->id, [
            'username' => $user->username,
            'role' => $user->role,
            'is_active' => $user->is_active,
        ]);

        return redirect()
            ->route('users.index')
            ->with('success', "User '{$user->username}' updated successfully.");
    }

    public function destroy(User $user): RedirectResponse
    {
        if ($user->id === auth()->id()) {
            return back()->with('error', 'You cannot delete your own account.');
        }

        $username = $user->username;
        $user->delete();

        AuditLogger::log('user.deleted', 'user', $user->id, [
            'username' => $username,
        ]);

        return redirect()
            ->route('users.index')
            ->with('success', "User '{$username}' deleted successfully.");
    }
}
