<?php

namespace App\Http\Controllers;

use App\Models\SyncSetting;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Sync\LocalEnrollmentService;
use App\Services\Sync\ScopeService;
use App\Services\Sync\SnapshotDownloadService;
use App\Support\SyncIdentity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

/**
 * First-browser-access onboarding for an offline installation.
 *
 * The installer no longer asks for URLs or credentials. On first visit the
 * operator supplies the enrollment token issued by the central administrator,
 * the Manager account credentials, and the area name. The trusted Central
 * Server address comes from the deployment-level bootstrap URL, and is
 * verified before it is trusted.
 */
class OnboardingController extends Controller
{
    public function show(ScopeService $scopes): View|RedirectResponse
    {
        if (! SyncIdentity::isOffline()) {
            return redirect()->route('dashboard');
        }

        if ($this->isConfigured()) {
            return redirect()->route('login');
        }

        return view('onboarding.show', [
            'bootstrapUrl' => $this->bootstrapUrl(),
            'scopeSuggestions' => $scopes->provinceSuggestions(),
        ]);
    }

    public function store(
        Request $request,
        ScopeService $scopes,
        LocalEnrollmentService $enrollment,
        SnapshotDownloadService $snapshots,
    ): RedirectResponse {
        if (! SyncIdentity::isOffline() || $this->isConfigured()) {
            return redirect()->route('login');
        }

        $data = $request->validate([
            'enrollment_token' => ['required', 'string', 'max:255'],
            'manager_name' => ['required', 'string', 'max:255'],
            'manager_username' => ['required', 'string', 'max:50', 'alpha_dash', 'unique:users,username'],
            'manager_password' => ['required', 'string', 'min:12', 'confirmed'],
            'area_name' => ['required', 'string', 'max:120'],
            'scope_type' => ['required', 'in:region,province,city_municipality,barangay'],
            'scope_code' => ['required', 'string', 'regex:/^(BARMM|\d{4,10})$/'],
        ]);

        $bootstrapUrl = $this->bootstrapUrl();

        if ($bootstrapUrl === null) {
            return back()->withInput()->withErrors([
                'enrollment_token' => 'This installation was not provisioned with a trusted Central Server address. Reinstall from a package that includes SYNC_BOOTSTRAP_URL, or set it in .env.',
            ]);
        }

        if ($data['scope_type'] === ScopeService::SCOPE_TYPE_REGION) {
            $data['scope_code'] = ScopeService::REGION_SENTINEL;
        }

        if (! $scopes->scopeMatchesLocations($data['scope_type'], $data['scope_code'])) {
            return back()->withInput()->withErrors([
                'scope_code' => 'That area does not match any BARMM location. Choose a valid province, city/municipality, or barangay, or the entire BARMM region.',
            ]);
        }

        $username = strtolower(trim($data['manager_username']));

        $manager = DB::transaction(fn (): User => User::create([
            'name' => trim($data['manager_name']),
            'username' => $username,
            'email' => $username.'@managers.local',
            'password' => Hash::make($data['manager_password']),
            'role' => User::ROLE_MANAGER,
            'is_active' => true,
        ]));

        try {
            $enrollment->enroll(
                $bootstrapUrl,
                $data['enrollment_token'],
                $data['area_name'],
                $data['scope_type'],
                $data['scope_code'],
            );
        } catch (RuntimeException $exception) {
            // Enrolling failed, so leave no half-created account behind.
            $manager->delete();

            return back()->withInput()->withErrors(['enrollment_token' => $exception->getMessage()]);
        }

        SyncSetting::set('area_name', $data['area_name']);
        SyncSetting::flushCache();

        AuditLogger::log('onboarding.completed', 'installation', null, [
            'area_name' => $data['area_name'],
            'username' => $username,
            'scope_type' => $data['scope_type'],
            'scope_code' => $data['scope_code'],
        ]);

        // The initial snapshot is best-effort: the device is enrolled, and a
        // partial download can be resumed later from Sync Setup.
        try {
            $snapshots->download();
        } catch (Throwable $exception) {
            return redirect()->route('login')->with(
                'warning',
                'Setup complete, but the initial data download did not finish: '.$exception->getMessage().' Sign in as '.$username.' and run Sync Now to retry.'
            );
        }

        return redirect()
            ->route('login')
            ->with('success', 'Setup complete. Sign in as '.$username.' to begin.');
    }

    private function isConfigured(): bool
    {
        if (trim((string) SyncSetting::get('server_uuid')) !== '') {
            return true;
        }

        // Mirrors EnsureInstallationConfigured: a device with accounts has been
        // through setup, so onboarding must not run again.
        return User::query()->exists();
    }

    private function bootstrapUrl(): ?string
    {
        $url = SyncSetting::get('bootstrap_url') ?: config('sync.bootstrap_url');
        $url = is_string($url) ? trim($url) : '';

        return $url === '' ? null : rtrim($url, '/');
    }
}
