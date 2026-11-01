<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class EncoderController extends Controller
{
    /**
     * Encoder accounts are managed on the installation that owns them. The
     * central server only receives the read-only identity registry.
     */
    public function index(): View
    {
        $encoders = User::query()
            ->where('role', User::ROLE_ENCODER)
            ->orderBy('username')
            ->get();

        $activity = AuditLog::query()
            ->whereIn('action', ['encoder.created', 'encoder.updated', 'encoder.deactivated'])
            ->latest('id')
            ->limit(20)
            ->get();

        return view('encoders.index', [
            'encoders' => $encoders,
            'activity' => $activity,
            'activeCount' => $encoders->where('is_active', true)->count(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:50', 'alpha_dash', 'unique:users,username'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $username = strtolower(trim($validated['username']));

        $encoder = User::create([
            'name' => trim($validated['name']),
            'username' => $username,
            'email' => $username.'@encoders.local',
            'password' => $validated['password'],
            'role' => User::ROLE_ENCODER,
            'is_active' => true,
        ]);

        AuditLogger::log('encoder.created', 'user', $encoder->id, [
            'username' => $encoder->username,
            'role' => $encoder->role,
            'encoder_uuid' => $encoder->uuid,
        ]);

        return redirect()
            ->route('encoders.index')
            ->with('success', "Encoder account '{$encoder->username}' created.");
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        abort_unless($user->role === User::ROLE_ENCODER, 404);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:50', 'alpha_dash', Rule::unique('users', 'username')->ignore($user->id)],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $data = [
            'name' => trim($validated['name']),
            'username' => strtolower(trim($validated['username'])),
            'is_active' => $request->boolean('is_active'),
        ];

        if (! empty($validated['password'])) {
            $data['password'] = $validated['password'];
        }

        $wasActive = (bool) $user->is_active;
        $user->update($data);

        AuditLogger::log('encoder.updated', 'user', $user->id, [
            'username' => $user->username,
            'is_active' => $user->is_active,
            'password_reset' => ! empty($validated['password']),
            'encoder_uuid' => $user->uuid,
        ]);

        if ($wasActive && ! $user->is_active) {
            AuditLogger::log('encoder.deactivated', 'user', $user->id, [
                'username' => $user->username,
                'encoder_uuid' => $user->uuid,
            ]);
        }

        return redirect()
            ->route('encoders.index')
            ->with('success', "Encoder account '{$user->username}' updated.");
    }
}
