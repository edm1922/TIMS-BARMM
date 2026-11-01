@extends('layouts.app')

@section('title', 'Enroll Server')

@section('content')
@php
    $packageCount = count($packages);
    $packageBytes = collect($packages)->sum('size');
    $serverCount = $servers->count();
    $activeServers = $servers->where('status', 'active')->count();
    $expiredAuthorizations = $servers->filter(fn ($server) => $server->authorizationStatus() === 'expired')->count();
    $expiringSoon = $servers->filter(function ($server) {
        return $server->status === 'active'
            && $server->authorization_expires_at !== null
            && $server->authorization_expires_at->isFuture()
            && $server->authorization_expires_at->lessThanOrEqualTo(now()->addDays(7));
    })->count();
    $buildState = $buildStatus['state'] ?? 'idle';
    $isBuilding = in_array($buildState, ['queued', 'running'], true);
@endphp

<div class="sync-console">
    @include('partials.sync-console-styles')

    <style>
        .sync-console .sync-steps { list-style: none; margin: 0; padding: 0; counter-reset: sync-step; }
        .sync-console .sync-steps > li {
            position: relative;
            counter-increment: sync-step;
            padding: 0 0 18px 40px;
            font-size: 13.5px;
        }
        .sync-console .sync-steps > li:last-child { padding-bottom: 0; }
        .sync-console .sync-steps > li::before {
            content: counter(sync-step);
            position: absolute;
            left: 0;
            top: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 26px;
            height: 26px;
            border-radius: 50%;
            background: #eff6ff;
            color: var(--sync-primary);
            font-size: 12.5px;
            font-weight: 700;
        }
        .sync-console .sync-steps > li:not(:last-child)::after {
            content: '';
            position: absolute;
            left: 12.5px;
            top: 30px;
            bottom: 6px;
            width: 1px;
            background: var(--sync-border);
        }
        .sync-console .sync-steps strong { display: block; margin-bottom: 2px; }
        .sync-console .sync-steps code { font-size: 12px; overflow-wrap: anywhere; }
    </style>

    <header class="sync-page-head">
        <div>
            <h1 class="sync-page-title">Enroll Server</h1>
            <p class="sync-page-subtitle">
                Prepare an installation package, hand it to the target device, and track its authorization.
                Enrollment tokens are issued from the Offline Servers page.
            </p>
            <div class="sync-meta">
                <span>{{ number_format($packageCount) }} {{ Str::plural('package', $packageCount) }} available</span>
                <span>{{ number_format($serverCount) }} registered {{ Str::plural('installation', $serverCount) }}</span>
                <span>{{ number_format($activeServers) }} active</span>
            </div>
        </div>
        <div class="sync-page-actions">
            <button class="sync-btn sync-btn--primary" type="button" id="build-btn"
                    data-bs-toggle="modal" data-bs-target="#buildPackageModal">
                <span class="btn-text">Build package</span>
                <span class="btn-loading d-none" role="status">
                    <span class="spinner-border spinner-border-sm me-1"></span>Building...
                </span>
            </button>
            <a class="sync-btn sync-btn--secondary" href="{{ route('sync-servers.index') }}">Offline Servers</a>
            <a class="sync-btn sync-btn--secondary" href="{{ route('dashboard') }}">Dashboard</a>
        </div>
    </header>

    @if (session('status'))
        <div class="sync-alerts">
            <div class="alert alert-success mb-0">{{ session('status') }}</div>
        </div>
    @endif

    @if ($errors->any())
        <div class="sync-alerts">
            <div class="alert alert-danger mb-0">
                @foreach ($errors->all() as $error)
                    <div>{{ $error }}</div>
                @endforeach
            </div>
        </div>
    @endif

    @if ($bootstrapWarning)
        <div class="sync-alerts">
            <div class="alert alert-warning mb-0">
                <strong>Packages built now will not be able to enroll devices.</strong>
                {{ $bootstrapWarning }}
            </div>
        </div>
    @endif

    @if (\App\Support\SyncIdentity::isOffline())
        <div class="sync-alerts">
            <div class="alert alert-warning mb-0">
                This installation is running in <strong>offline</strong> mode. Packages are prepared on the central server.
                Use the local setup page to enroll this device and manage its authorization period.
                <a class="alert-link ms-1" href="{{ route('sync.local.index') }}">Open the local setup page</a>.
            </div>
        </div>
    @endif

    <section class="sync-summary" aria-label="Installation overview">
        <article class="sync-card sync-stat">
            <div class="sync-stat-label">Installation packages</div>
            <div class="sync-stat-value">{{ number_format($packageCount) }}</div>
            <div class="sync-stat-note">
                {{ $packageCount > 0 ? number_format($packageBytes / 1048576, 1).' MB total - ready to hand to devices.' : 'No package built yet.' }}
            </div>
        </article>

        <article class="sync-card sync-stat">
            <div class="sync-stat-label">Registered installations</div>
            <div class="sync-stat-value">{{ number_format($serverCount) }}</div>
            <div class="sync-stat-note">{{ number_format($activeServers) }} active {{ Str::plural('device', $activeServers) }} enrolled.</div>
        </article>

        <article class="sync-card sync-stat {{ $expiringSoon > 0 ? 'sync-stat--warning' : '' }}">
            <div class="sync-stat-label">Authorizations expiring soon</div>
            <div class="sync-stat-value">{{ number_format($expiringSoon) }}</div>
            <div class="sync-stat-note">
                @if ($expiredAuthorizations > 0)
                    {{ number_format($expiredAuthorizations) }} {{ Str::plural('authorization', $expiredAuthorizations) }} already expired.
                @else
                    Expiring within the next 7 days.
                @endif
            </div>
        </article>

        <article class="sync-card sync-stat {{ $isBuilding ? 'sync-stat--warning' : 'sync-stat--ok' }}">
            <div class="sync-stat-label">Package build</div>
            <div class="sync-stat-value" style="font-size: 20px;">
                <span class="sync-pill {{ $isBuilding ? 'sync-pill--warning' : 'sync-pill--ok' }}">
                    <span class="sync-pill__dot" aria-hidden="true"></span>
                    {{ $isBuilding ? ucfirst($buildState) : 'Idle' }}
                </span>
            </div>
            <div class="sync-stat-note">{{ $isBuilding ? 'A package is being archived in the background.' : 'Ready to build a new installation package.' }}</div>
        </article>
    </section>

    <div class="sync-grid sync-grid--main">
        <section class="sync-card" aria-labelledby="sync-procedure-title">
            <div class="sync-section-head">
                <div>
                    <h2 class="sync-section-title" id="sync-procedure-title">Installation procedure</h2>
                    <p class="sync-section-subtitle">From package build to an enrolled device.</p>
                </div>
            </div>
            <div class="sync-body">
                <ol class="sync-steps">
                    <li>
                        <strong>Generate the package.</strong>
                        Enter the device label below. The package contains the application code, the Composer
                        dependencies, and the setup scripts. No credentials are included.
                    </li>
                    <li>
                        <strong>Install XAMPP on the target device</strong> and copy the package to
                        <code>C:\xampp\htdocs\TIMS-BARMM</code>.
                    </li>
                    <li>
                        <strong>Run the setup script</strong> in an elevated PowerShell:
                        <code>powershell -ExecutionPolicy Bypass -File .\scripts\setup-offline.ps1</code>.
                        The script refuses to overwrite a populated database and opens the application in the browser.
                    </li>
                    <li>
                        <strong>Issue an enrollment token</strong> on the
                        <a href="{{ route('sync-servers.index') }}">Offline Servers</a> page. Set the authorization
                        period that this installation is allowed to operate.
                    </li>
                    <li>
                        <strong>Enroll the device</strong> by entering the token on the device's Enroll Server page,
                        then download the initial snapshot.
                    </li>
                </ol>
            </div>
        </section>

        <section class="sync-card" aria-labelledby="sync-prereq-title">
            <div class="sync-section-head">
                <div>
                    <h2 class="sync-section-title" id="sync-prereq-title">Server prerequisites</h2>
                    <p class="sync-section-subtitle">Checks performed on this central server.</p>
                </div>
            </div>
            <div class="sync-diag">
                @foreach ($prerequisites as $check)
                    @php
                        $diagPill = match ($check['status']) {
                            'ok' => 'sync-pill--ok',
                            'warning' => 'sync-pill--warning',
                            default => 'sync-pill--danger',
                        };
                    @endphp
                    <div class="sync-diag-row">
                        <span class="sync-diag-label">{{ $check['label'] }}</span>
                        <span class="sync-diag-detail">{{ $check['detail'] }}</span>
                        <span class="sync-pill {{ $diagPill }}">{{ ucfirst($check['status']) }}</span>
                    </div>
                @endforeach
            </div>
        </section>
    </div>

    <section class="sync-card mb-3" aria-labelledby="sync-build-title">
        <div class="sync-section-head">
            <div>
                <h2 class="sync-section-title" id="sync-build-title">Generate an installation package</h2>
                <p class="sync-section-subtitle">
                    Build an offline installation package containing the application code, dependencies, and setup scripts.
                </p>
            </div>
            <button class="sync-btn sync-btn--primary sync-btn--small" type="button"
                    data-bs-toggle="modal" data-bs-target="#buildPackageModal">
                Build package
            </button>
        </div>
        <div class="sync-body">
            <p class="sync-help mb-2">
                The package excludes <code>.env</code>, keys, logs, backups, generated caches, and the git history.
                Each package includes a <code>SHA256SUMS.txt</code> manifest for verification on the target device.
                Building archives the whole dependency tree, so it runs in the background while this page reports the progress.
            </p>

            <div id="build-progress" class="d-none mt-2">
                <div class="alert alert-info alert-dismissible fade show mb-2" role="alert" id="build-progress-alert">
                    <strong id="build-progress-title">Building the installation package</strong>
                    <span id="build-progress-detail">Waiting for the build to start.</span>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <div class="sync-progress mt-2">
                    <div class="h-100 bg-primary" id="build-progress-bar" role="progressbar"
                         aria-valuenow="0" aria-valuemin="0" aria-valuemax="100" style="width: 0%; transition: width .2s ease;"></div>
                </div>
                <div class="small text-muted mt-1">
                    <span id="build-progress-elapsed">Elapsed 0s</span>
                    <span id="build-progress-detail2" class="float-end"></span>
                </div>
            </div>
        </div>
    </section>

    <section class="sync-card mb-3" aria-labelledby="sync-packages-title">
        <div class="sync-section-head">
            <div>
                <h2 class="sync-section-title" id="sync-packages-title">Generated packages</h2>
                <p class="sync-section-subtitle">Download a package or delete an old build from this server.</p>
            </div>
        </div>
        @if ($packages === [])
            <div class="sync-empty">
                <div class="sync-empty-title">No installation package generated yet</div>
                <div class="sync-empty-note">Build a package to hand it to an offline device.</div>
            </div>
        @else
            <div class="table-responsive">
                <table class="sync-ops-table">
                    <thead>
                        <tr>
                            <th scope="col">Package</th>
                            <th scope="col">Size</th>
                            <th scope="col">Built</th>
                            <th scope="col" class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($packages as $package)
                            <tr>
                                <td class="font-monospace" style="font-size: 12.5px;">{{ $package['filename'] }}</td>
                                <td class="sync-ops-num text-nowrap">{{ number_format($package['size'] / 1048576, 1) }} MB</td>
                                <td class="sync-ops-when">{{ date('M j, Y H:i', $package['created_at']) }}</td>
                                <td class="text-end text-nowrap">
                                    <a class="sync-btn sync-btn--secondary sync-btn--small"
                                       href="{{ route('enroll-server.packages.download', $package['filename']) }}">Download</a>
                                    <form method="POST" class="d-inline m-0"
                                          action="{{ route('enroll-server.packages.destroy', $package['filename']) }}"
                                          onsubmit="return confirm('Delete this package from the server?');">
                                        @csrf
                                        @method('DELETE')
                                        <button class="sync-btn sync-btn--small" type="submit"
                                                style="color: var(--sync-danger); background: #fff; border-color: #fecaca;">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section class="sync-card" aria-labelledby="sync-servers-title">
        <div class="sync-section-head">
            <div>
                <h2 class="sync-section-title" id="sync-servers-title">Registered offline installations</h2>
                <p class="sync-section-subtitle">Devices that enrolled with this central server and their authorization state.</p>
            </div>
            <a class="sync-btn sync-btn--secondary sync-btn--small" href="{{ route('sync-servers.index') }}">Offline servers</a>
        </div>
        @if ($servers->isEmpty())
            <div class="sync-empty">
                <div class="sync-empty-title">No offline installation has enrolled yet</div>
                <div class="sync-empty-note">Build a package, enroll a device, then issue its enrollment token.</div>
            </div>
        @else
            <div class="table-responsive">
                <table class="sync-ops-table">
                    <thead>
                        <tr>
                            <th scope="col">Installation</th>
                            <th scope="col">Status</th>
                            <th scope="col">Authorization</th>
                            <th scope="col">Expires</th>
                            <th scope="col">Last seen</th>
                            <th scope="col" class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($servers as $server)
                            @php
                                $statusPill = match ($server->status) {
                                    'active' => 'sync-pill--ok',
                                    'suspended' => 'sync-pill--warning',
                                    default => 'sync-pill--neutral',
                                };
                                $authorizationPill = match ($server->authorizationStatus()) {
                                    'active' => 'sync-pill--ok',
                                    'expired', 'revoked' => 'sync-pill--danger',
                                    default => 'sync-pill--neutral',
                                };
                            @endphp
                            <tr>
                                <td>
                                    <div style="font-weight: 600;">{{ $server->name }}</div>
                                    <div class="sync-ops-entity mt-1">
                                        <code class="sync-ops-uuid" title="{{ $server->server_uuid }}">{{ $server->server_uuid }}</code>
                                    </div>
                                </td>
                                <td><span class="sync-pill {{ $statusPill }}">{{ ucfirst($server->status) }}</span></td>
                                <td><span class="sync-pill {{ $authorizationPill }}">{{ ucfirst($server->authorizationStatus()) }}</span></td>
                                <td class="sync-ops-when">{{ $server->authorization_expires_at?->format('M j, Y') ?? 'Not set' }}</td>
                                <td class="sync-ops-when">{{ $server->last_seen_at?->diffForHumans() ?? 'Never' }}</td>
                                <td class="text-end text-nowrap">
                                    <a class="sync-btn sync-btn--secondary sync-btn--small"
                                       href="{{ route('sync-servers.show', $server) }}">Manage</a>
                                    @if ($server->status === 'active' && ! $server->isRevoked())
                                        <form method="POST"
                                              action="{{ route('sync-servers.prepare-token', $server) }}"
                                              class="d-inline m-0"
                                              onsubmit="return confirm('Generate a preparation token for this installation? The token can be used once to prepare the device for a new encoding session.');">
                                            @csrf
                                            @method('POST')
                                            <button type="submit" class="sync-btn sync-btn--small"
                                                    style="color: var(--sync-primary); background: #fff; border-color: #bfdbfe;">
                                                Prepare Session
                                            </button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <div class="modal fade" id="buildPackageModal" tabindex="-1" aria-labelledby="buildPackageModalLabel"
         aria-describedby="buildPackageModalDescription" aria-hidden="true">
        <div class="modal-dialog tims-modal--lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <span class="tims-modal-icon" aria-hidden="true">
                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                             stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21 8v13H3V8"/><path d="M1 3h22v5H1z"/><path d="M10 12h4"/>
                        </svg>
                    </span>
                    <div>
                        <h2 class="modal-title" id="buildPackageModalLabel">Build installation package</h2>
                        <p class="tims-modal-subtitle" id="buildPackageModalDescription">
                            Create a signed-off installation archive to deploy on an offline device.
                        </p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="{{ route('enroll-server.packages.store') }}" id="build-package-form">
                    @csrf
                    <input type="hidden" name="action" value="publish">
                    <div class="modal-body">
                        <p class="mb-2">Build and publish a new offline installation package?</p>
                        <p class="sync-help mb-2">The package becomes available to enrolled offline installations after the build.</p>
                        <div class="tims-note">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>
                            </svg>
                            <div>
                                The package excludes <code>.env</code>, keys, logs, backups, generated caches, and the git history.
                                Each package includes a <code>SHA256SUMS.txt</code> manifest for verification on the target device.
                                Building archives the whole dependency tree, so it runs in the background while this page reports the progress.
                            </div>
                        </div>
                        @error('device_label')
                            <div class="alert alert-danger py-2 small mt-3 mb-0">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="sync-btn sync-btn--secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="sync-btn sync-btn--primary" id="confirm-build-btn">
                            Confirm build
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const btn = document.getElementById('build-btn');
    const panel = document.getElementById('build-progress');
    const alertBox = document.getElementById('build-progress-alert');
    const title = document.getElementById('build-progress-title');
    const elapsed = document.getElementById('build-progress-elapsed');
    const bar = document.getElementById('build-progress-bar');
    const detail = document.getElementById('build-progress-detail');
    const statusUrl = @json(route('enroll-server.packages.status'));

    let poller = null;
    let clock = null;
    let startedAt = Date.now();
    let serverElapsed = 0;

    function formatBytes(bytes) {
        const value = Number(bytes || 0);

        if (value < 1024) {
            return value + ' B';
        }

        if (value < 1048576) {
            return (value / 1024).toFixed(0) + ' KB';
        }

        return (value / 1048576).toFixed(1) + ' MB';
    }

    function tickClock() {
        if (!elapsed) {
            return;
        }

        const seconds = Math.max(serverElapsed, Math.round((Date.now() - startedAt) / 1000));

        elapsed.textContent = seconds < 60
            ? 'Elapsed ' + seconds + 's'
            : 'Elapsed ' + Math.floor(seconds / 60) + 'm ' + (seconds % 60) + 's';
    }

    function render(status) {
        const state = status.state || 'idle';
        const total = Number(status.total_files || 0);
        const processed = Number(status.processed_files || 0);
        const percent = total > 0 ? Math.min(100, Math.round((processed / total) * 100)) : 0;

        serverElapsed = Number(status.elapsed_seconds || 0);
        tickClock();

        if (bar) {
            bar.style.width = percent + '%';
            bar.setAttribute('aria-valuenow', String(percent));
        }

        if (state === 'queued') {
            if (title) title.textContent = 'Waiting for the build to start';
            if (detail) detail.textContent = 'The package build was accepted and is starting.';
        } else if (state === 'running') {
            if (title) title.textContent = 'Building the installation package';
            if (detail) {
                detail.textContent = processed + ' of ' + total + ' files · ' +
                    formatBytes(status.bytes_processed) +
                    (status.current_file ? ' · ' + status.current_file : '');
            }
        }
    }

    function stopPolling() {
        if (poller) {
            clearInterval(poller);
            poller = null;
        }

        if (clock) {
            clearInterval(clock);
            clock = null;
        }
    }

    function fail(message) {
        stopPolling();
        setBusy(false);

        if (panel) panel.classList.remove('d-none');
        if (alertBox) alertBox.classList.remove('alert-info', 'alert-success');
        if (alertBox && !alertBox.classList.contains('alert-danger')) alertBox.classList.add('alert-danger');
        if (title) title.textContent = 'The package could not be generated';
        if (detail) detail.textContent = message;
        if (bar) bar.style.width = '0%';
    }

    function poll() {
        fetch(statusUrl, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('The build status could not be read.');
                }

                return response.json();
            })
            .then(function (status) {
                if (status.state === 'completed') {
                    stopPolling();
                    window.location.reload();
                    return;
                }

                if (status.state === 'failed') {
                    fail(status.error || 'The package could not be generated.');
                    return;
                }

                render(status);
            })
            .catch(function (error) {
                fail(error.message);
            });
    }

    function watch() {
        startedAt = Date.now();
        stopPolling();
        poll();
        poller = setInterval(poll, 1500);
        clock = setInterval(tickClock, 1000);
    }

    // Build is submitted as a normal POST from the confirmation dialog;
    // the status polling below reports progress after the redirect.

    function setBusy(busy) {
        if (!btn) return;
        btn.disabled = busy;
        btn.querySelector('.btn-text')?.classList.toggle('d-none', busy);
        btn.querySelector('.btn-loading')?.classList.toggle('d-none', !busy);
    }

    const initial = @json($buildStatus);

    if (initial && (initial.state === 'queued' || initial.state === 'running')) {
        setBusy(true);

        if (panel) panel.classList.remove('d-none');
        render(initial);
        watch();
    }
});
</script>
@endpush
@endsection