@extends('layouts.app')

@section('title', 'Enroll Server')

@section('content')
@php
    $enrolledState = $enrolled;
    $lastSync = $settings['last_sync_at'] ?: null;
    $lastSnapshot = $settings['last_snapshot_at'] ?: null;

    $healthState = 'ok';
    $healthLabel = 'In sync';
    $healthNote = 'Queue is clear and the installation is authorized.';

    if ($authorization['expired']) {
        $healthState = 'danger';
        $healthLabel = 'Action required';
        $healthNote = 'Authorization has expired - uploads are blocked until this installation is reauthorized.';
    } elseif ($queue['failed'] > 0 || $queue['conflicts'] > 0) {
        $healthState = 'danger';
        $healthLabel = 'Action required';
        $healthNote = number_format($queue['failed']).' failed upload(s), '.number_format($queue['conflicts']).' local conflict(s).';
    } elseif ($queue['rejected'] > 0) {
        $healthState = 'warning';
        $healthLabel = 'Needs attention';
        $healthNote = number_format($queue['rejected']).' uploaded operation(s) were rejected by the central server.';
    } elseif ($queue['awaiting_review'] > 0) {
        $healthState = 'warning';
        $healthLabel = 'Needs attention';
        $healthNote = number_format($queue['awaiting_review']).' record(s) are waiting for central review.';
    } elseif ($queue['pending'] > 0) {
        $healthState = 'warning';
        $healthLabel = 'Needs attention';
        $healthNote = number_format($queue['pending']).' change(s) are waiting to be uploaded.';
    } elseif ($settings['last_sync_error']) {
        $healthState = 'warning';
        $healthLabel = 'Needs attention';
        $healthNote = 'The last synchronization reported an error.';
    }
@endphp

<div class="sync-console">
    @include('partials.sync-console-styles')

    <header class="sync-page-head">
        <div>
            <h1 class="sync-page-title">{{ $enrolledState ? 'Installation dashboard' : 'Enroll Server' }}</h1>
            <p class="sync-page-subtitle">
                {{ $enrolledState
                    ? 'Synchronization health, enrollment, diagnostics and recent operations for this offline installation.'
                    : 'Enroll this device, authorize it, sync data, and monitor the local queue.' }}
            </p>
            @if ($isOffline && $enrolledState)
                <div class="sync-meta">
                    <span>Last sync: <strong>{{ $lastSync ?: 'Never' }}</strong></span>
                    @if ($settings['last_download_revision'])
                        <span>Cursor <strong>{{ $settings['last_download_revision'] }}</strong></span>
                    @endif
                    <span id="connection-status">
                        Central: <span class="sync-pill sync-pill--neutral" id="connection-badge">Checking...</span>
                        <small id="connection-last-checked"></small>
                    </span>
                </div>
            @endif
        </div>
        <div class="sync-page-actions">
            @if ($isOffline)
                <a class="sync-btn sync-btn--secondary" href="{{ route('encoders.index') }}">Encoders</a>
            @endif
            @if ($enrolledState)
                <form method="POST" action="{{ route('sync.local.sync-now') }}" id="sync-now-form" class="m-0">
                    @csrf
                    <button type="submit" class="sync-btn sync-btn--primary" id="sync-now-button">Sync Now</button>
                </form>
                <form method="POST" action="{{ route('sync.local.app-update.check') }}" class="m-0">
                    @csrf
                    <button type="submit" class="sync-btn sync-btn--secondary">Check for Updates</button>
                </form>
                @if ($appUpdate['ready'] && ! $appUpdate['applying'])
                    <form method="POST" action="{{ route('sync.local.app-update.apply') }}" class="m-0"
                          onsubmit="return confirm('Apply the downloaded application update now? This server may be unavailable for a few minutes while it updates.');">
                        @csrf
                        <button type="submit" class="sync-btn sync-btn--warning">Apply Update</button>
                    </form>
                @endif
            @endif
            <a class="sync-btn sync-btn--secondary" href="{{ route('dashboard') }}">Dashboard</a>
        </div>
    </header>

    @unless ($isOffline)
        <div class="sync-alerts">
            <div class="alert alert-warning">
                This installation is running in <strong>online</strong> mode. The local upload queue is disabled.
                Set <code>INSTANCE_MODE=offline</code> before enrolling an offline server.
            </div>
        </div>
    @endunless

    @if (session('sync_result'))
        @php
            $syncResult = session('sync_result');
            $syncStepLabels = [
                'connection' => 'Connection',
                'authorization' => 'Authorization',
                'upload' => 'Upload pending changes',
                'review_outcomes' => 'Central review decisions',
                'audit' => 'Audit trail push',
                'download' => 'Download central changes',
                'acknowledge' => 'Acknowledgment',
            ];
            $syncTone = match ($syncResult['status']) {
                'success' => 'ok',
                'warning' => 'warning',
                default => 'danger',
            };
        @endphp
        <div class="sync-alerts">
            <div class="sync-card" id="sync-result">
                <div class="sync-section-head">
                    <div>
                        <h2 class="sync-section-title">Synchronization result</h2>
                        <p class="sync-section-subtitle">Step-by-step outcome of the last Sync Now run.</p>
                    </div>
                    <span class="sync-pill sync-pill--{{ $syncTone }}">
                        <span class="sync-pill__dot" aria-hidden="true"></span>
                        {{ ucfirst($syncResult['status']) }}
                    </span>
                </div>
                <div class="sync-body">
                    <div class="alert {{ $syncResult['status'] === 'success' ? 'alert-success' : ($syncResult['status'] === 'warning' ? 'alert-warning' : 'alert-danger') }} py-2 mb-3">
                        {{ $syncResult['message'] }}
                    </div>

                    @if (! empty($syncResult['steps']))
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-3">
                                <tbody>
                                    @foreach ($syncResult['steps'] as $step)
                                        @php
                                            $stepBadge = match ($step['status'] ?? '') {
                                                'ok' => 'text-bg-success',
                                                'warning' => 'text-bg-warning',
                                                default => 'text-bg-danger',
                                            };
                                        @endphp
                                        <tr>
                                            <td class="small fw-medium" style="width: 30%;">
                                                {{ $syncStepLabels[$step['step']] ?? ucfirst(str_replace('_', ' ', (string) $step['step'])) }}
                                            </td>
                                            <td>
                                                <span class="badge {{ $stepBadge }}">{{ ucfirst($step['status'] ?? 'error') }}</span>
                                                <span class="small ms-1">{{ $step['message'] ?? '' }}</span>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif

                    @if (! empty($syncResult['summary']))
                        <div class="row g-2 text-center">
                            @foreach ([
                                'uploaded' => 'Uploaded',
                                'downloaded' => 'Downloaded',
                                'conflicts' => 'Conflicts',
                                'rejected' => 'Rejected',
                                'awaiting_review' => 'Awaiting review',
                                'audit_accepted' => 'Audit accepted',
                            ] as $summaryKey => $summaryLabel)
                                <div class="col-6 col-md-2">
                                    <div class="border rounded p-2">
                                        <div class="h6 mb-0">{{ number_format($syncResult['summary'][$summaryKey] ?? 0) }}</div>
                                        <div class="text-muted small">{{ $summaryLabel }}</div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    @if (($syncResult['summary']['awaiting_review'] ?? 0) > 0)
                        <div class="small text-muted mt-2">
                            Records awaiting central review are preserved locally and are not yet accepted - see the notice below.
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @endif

    @php
        $plainAlerts = [];
        if (session('status') && ! session('sync_result')) {
            $plainAlerts[] = ['class' => 'alert-success', 'text' => session('status')];
        }
        if ($errors->has('download')) {
            $plainAlerts[] = ['class' => 'alert-danger', 'text' => $errors->first('download')];
        }
        if ($errors->has('sync')) {
            $plainAlerts[] = ['class' => 'alert-danger', 'text' => $errors->first('sync')];
        }
        if (session('error')) {
            $plainAlerts[] = ['class' => 'alert-danger', 'text' => session('error')];
        }
        if ($settings['last_sync_error']) {
            $plainAlerts[] = ['class' => 'alert-warning', 'text' => $settings['last_sync_error']];
        }
    @endphp

    @if ($plainAlerts !== [])
        <div class="sync-alerts">
            @foreach ($plainAlerts as $plainAlert)
                <div class="alert {{ $plainAlert['class'] }}">{{ $plainAlert['text'] }}</div>
            @endforeach
        </div>
    @endif

    @if ($authorization['expired'])
        <div class="sync-alerts">
            <div class="alert alert-danger">
                <div class="fw-semibold">The authorization period for this installation has expired.</div>
                <div class="small mt-1">
                    New beneficiary records and modifications are blocked. Existing records, audit history, and
                    pending synchronization operations are preserved and remain visible. Upload to the central
                    server is rejected until an administrator reauthorizes this installation.
                </div>
            </div>
        </div>
    @endif

    @if ($authorization['prepared'])
        <div class="sync-alerts">
            <div class="alert alert-success">
                <div class="fw-semibold d-flex align-items-center gap-2">
                    <svg class="bi flex-shrink-0" width="18" height="18"><use xlink:href="#check-circle-fill"/></svg>
                    <span>Ready for deployment</span>
                </div>
                <div class="small mt-1">
                    This installation is prepared for an offline encoding session. Authorization expires
                    <code>{{ $authorization['expires_at'] }}</code>.
                    Pending uploads: <code>{{ $queue['pending'] }}</code>,
                    audit events pending: <code>{{ $audit['pending'] }}</code>.
                </div>
            </div>
        </div>
    @endif

    @if ($enrolledState)
        <section class="sync-summary" aria-label="Synchronization overview">
            <article class="sync-card sync-stat sync-stat--{{ $healthState }}">
                <div class="sync-stat-label">Sync status</div>
                <div class="sync-stat-value">
                    <span class="sync-pill sync-pill--{{ $healthState }}">
                        <span class="sync-pill__dot" aria-hidden="true"></span>
                        {{ $healthLabel }}
                    </span>
                </div>
                <div class="sync-stat-note">{{ $healthNote }}</div>
            </article>

            <article class="sync-card sync-stat">
                <div class="sync-stat-label">Pending uploads</div>
                <div class="sync-stat-value">{{ number_format($queue['pending']) }}</div>
                <div class="sync-stat-note">
                    {{ $queue['pending'] > 0 ? 'Waiting for the next Sync Now run.' : 'Nothing waiting to be uploaded.' }}
                </div>
            </article>

            <article class="sync-card sync-stat">
                <div class="sync-stat-label">Rejected operations</div>
                <div class="sync-stat-value">{{ number_format($queue['rejected']) }}</div>
                <div class="sync-stat-note">
                    @if ($queue['rejected'] > 0 && $queue['retryable'] > 0)
                        {{ number_format($queue['retryable']) }} of them only failed the former location-scope rule and can be retried safely.
                        <form method="POST" action="{{ route('sync.local.retry-rejected') }}" class="mt-2 m-0"
                              onsubmit="return confirm('Retry {{ number_format($queue['retryable']) }} eligible operation(s)? Only operations rejected by the former location-scope rule are requeued.');">
                            @csrf
                            <button type="submit" class="sync-btn sync-btn--warning sync-btn--small">
                                Retry {{ number_format($queue['retryable']) }} eligible {{ Str::plural('operation', $queue['retryable']) }}
                            </button>
                        </form>
                    @elseif ($queue['rejected'] > 0)
                        None of them are eligible for automatic retry.
                    @else
                        No operations have been rejected by the central server.
                    @endif
                </div>
            </article>

            <article class="sync-card sync-stat">
                <div class="sync-stat-label">Last successful sync</div>
                <div class="sync-stat-value" style="font-size: 22px;">{{ $lastSync ?: 'Never' }}</div>
                <div class="sync-stat-note">
                    @if ($settings['last_download_revision'])
                        Download cursor {{ $settings['last_download_revision'] }}.
                    @else
                        Run Sync Now to reconcile with the central server.
                    @endif
                </div>
            </article>
        </section>

        @if ($queue['awaiting_review'] > 0)
            <div class="sync-alerts">
                <div class="alert alert-info small">
                    <strong>{{ number_format($queue['awaiting_review']) }}</strong> uploaded record(s) are waiting for central review
                    (possible duplicates or records that need a decision). They are preserved locally and are <em>not</em>
                    treated as accepted; the next <strong>Sync Now</strong> pulls the reviewer's decision.
                </div>
            </div>
        @endif

        @if ($queue['rejected'] > 0)
            <div class="sync-alerts">
                <div class="alert alert-warning small">
                    <strong>{{ number_format($queue['rejected']) }}</strong> uploaded operation(s) were rejected by the central server.
                    @if ($queue['retryable'] > 0)
                        <strong>{{ number_format($queue['retryable']) }}</strong> of them only failed the former location-scope rule and can be retried safely.
                    @endif
                    See the operations table below for the exact errors.
                </div>
            </div>
        @endif
    @endif

    <div class="sync-grid sync-grid--main">
        <div class="sync-grid" style="margin-bottom: 0;">
            <section class="sync-card" aria-labelledby="sync-enrollment-title">
                <div class="sync-section-head">
                    <div>
                        <h2 class="sync-section-title" id="sync-enrollment-title">Enrollment</h2>
                        <p class="sync-section-subtitle">Registration, coverage and authorization of this installation.</p>
                    </div>
                    @if ($enrolledState)
                        @if ($authorization['expired'])
                            <span class="sync-pill sync-pill--danger">Expired</span>
                        @elseif ($authorization['expires_at'])
                            <span class="sync-pill sync-pill--ok"><span class="sync-pill__dot" aria-hidden="true"></span>Authorized</span>
                        @else
                            <span class="sync-pill sync-pill--neutral">Not authorized</span>
                        @endif
                    @endif
                </div>

                @if ($enrolledState)
                    <div class="sync-body">
                        <dl class="sync-kv">
                            <div class="sync-kv-row"><dt class="sync-kv-label">Central URL</dt><dd class="sync-kv-value"><code>{{ $settings['central_url'] }}</code></dd></div>
                            <div class="sync-kv-row"><dt class="sync-kv-label">Server name</dt><dd class="sync-kv-value">{{ $settings['server_name'] ?: '-' }}</dd></div>
                            <div class="sync-kv-row"><dt class="sync-kv-label">Local server UUID</dt><dd class="sync-kv-value"><code>{{ $settings['server_uuid'] }}</code></dd></div>
                            <div class="sync-kv-row">
                                <dt class="sync-kv-label">Coverage</dt>
                                <dd class="sync-kv-value">
                                    @if (($settings['scope_type'] ?? null) === 'region')
                                        Entire BARMM region
                                    @else
                                        {{ ucfirst(str_replace('_', ' ', (string) $settings['scope_type'])) }}
                                        <code>{{ $settings['scope_code'] }}</code>
                                    @endif
                                </dd>
                            </div>
                            <div class="sync-kv-row"><dt class="sync-kv-label">Enrolled at</dt><dd class="sync-kv-value">{{ $settings['enrolled_at'] ?: '-' }}</dd></div>
                            <div class="sync-kv-row"><dt class="sync-kv-label">Authorized at</dt><dd class="sync-kv-value">{{ $authorization['authorized_at'] ?: '-' }}</dd></div>
                            <div class="sync-kv-row">
                                <dt class="sync-kv-label">Authorization expires</dt>
                                <dd class="sync-kv-value">
                                    @if ($authorization['expires_at'])
                                        {{ $authorization['expires_at'] }}
                                        @if ($authorization['expired'])
                                            <span class="badge text-bg-danger">Expired</span>
                                        @else
                                            <span class="badge text-bg-success">Authorized</span>
                                        @endif
                                    @else
                                        -
                                    @endif
                                </dd>
                            </div>
                            <div class="sync-kv-row">
                                <dt class="sync-kv-label">Last snapshot</dt>
                                <dd class="sync-kv-value">
                                    {{ $lastSnapshot ?: 'Never' }}
                                    @if ($settings['last_snapshot_revision'])
                                        <span class="text-muted">(revision {{ $settings['last_snapshot_revision'] }})</span>
                                    @endif
                                </dd>
                            </div>
                            <div class="sync-kv-row">
                                <dt class="sync-kv-label">Last sync</dt>
                                <dd class="sync-kv-value">
                                    {{ $lastSync ?: 'Never' }}
                                    @if ($settings['last_download_revision'])
                                        <span class="text-muted">(cursor {{ $settings['last_download_revision'] }})</span>
                                    @endif
                                </dd>
                            </div>
                        </dl>
                    </div>

                    <div class="sync-body--divided">
                        <h3 class="sync-subhead">Reauthorize this installation</h3>
                        <p class="sync-help">
                            Ask a central administrator for a reauthorization token, then enter it here. Reauthorization
                            only extends the authorization period: audit history, sync history, and pending operations are
                            preserved, and encoder login sessions are not extended. Enrollment tokens cannot be used here.
                        </p>
                        <form method="POST" action="{{ route('sync.local.reauthorize') }}" class="sync-form-row">
                            @csrf
                            <div class="sync-field">
                                <label for="reauthorization_token">Reauthorization token</label>
                                <input type="text" class="sync-input font-monospace @error('reauthorization_token') is-invalid @enderror"
                                       id="reauthorization_token" name="reauthorization_token"
                                       value="{{ old('reauthorization_token') }}" autocomplete="off" required>
                                @error('reauthorization_token')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div>
                                <button class="sync-btn sync-btn--secondary" type="submit">Reauthorize</button>
                            </div>
                        </form>
                    </div>

                    <details class="sync-recovery">
                        <summary>Recover this installation (revoked or lost server token)</summary>
                        <div class="sync-recovery-body">
                            <p class="sync-help">
                                If the central server revoked this installation or its server token is no longer valid,
                                generate a new <strong>enrollment token</strong> on the central Offline Servers page and enter
                                it here. This reactivates the same server UUID and replaces the stored server token. Local
                                beneficiaries, batches, audit history, and pending operations are preserved.
                            </p>
                            <form method="POST" action="{{ route('sync.local.enroll') }}" class="sync-form-row"
                                  onsubmit="return confirm('Re-enroll this installation with the new token? The stored server token will be replaced.');">
                                @csrf
                                <div class="sync-field">
                                    <label for="recovery_server_name">Server name</label>
                                    <input type="text" class="sync-input @error('server_name') is-invalid @enderror"
                                           id="recovery_server_name" name="server_name"
                                           value="{{ old('server_name', $settings['server_name']) }}" required>
                                    @error('server_name')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                                <div class="sync-field">
                                    <label for="recovery_enrollment_token">New enrollment token</label>
                                    <input type="text" class="sync-input font-monospace @error('enrollment_token') is-invalid @enderror"
                                           id="recovery_enrollment_token" name="enrollment_token"
                                           value="{{ old('enrollment_token') }}" autocomplete="off" required>
                                    @error('enrollment_token')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                                <div>
                                    <button class="sync-btn sync-btn--secondary" type="submit">Re-enroll</button>
                                </div>
                            </form>
                        </div>
                    </details>

                    <details class="sync-recovery">
                        <summary>Recover data (download the full snapshot again)</summary>
                        <div class="sync-recovery-body">
                            <p class="sync-help">
                                Re-downloads every beneficiary and reference record in this server's coverage, not just
                                recent changes. Use this only when local data looks wrong or was restored from a backup -
                                routine synchronization never needs it. Existing local records are updated in place;
                                nothing is deleted.
                            </p>
                            <form method="POST" action="{{ route('sync.local.download') }}"
                                  onsubmit="return confirm('Re-download the full snapshot now? This re-imports all in-scope records and may take several minutes.');">
                                @csrf
                                <button class="sync-btn sync-btn--warning" type="submit">Download full snapshot</button>
                            </form>
                        </div>
                    </details>

                    @if (($ghosts['total'] ?? 0) > 0)
                        <details class="sync-recovery sync-recovery--attention" open>
                            <summary>
                                Ghost records detected: {{ number_format($ghosts['total']) }}
                                active {{ Str::plural('beneficiary', $ghosts['total']) }} in deleted batches
                            </summary>
                            <div class="sync-recovery-body">
                                <p class="sync-help">
                                    These records are still filed in release batches that have been deleted. They inflate the
                                    local beneficiary total and cannot be removed by sync - a snapshot download only creates
                                    or updates records, it never deletes. Archiving them is safe: the records are kept and
                                    remain searchable under Archived, they just stop counting as active.
                                </p>
                                <div class="table-responsive mb-2">
                                    <table class="table table-sm small mb-0">
                                        <thead>
                                            <tr>
                                                <th>Deleted batch</th>
                                                <th class="text-end">Active members</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($ghosts['groups'] as $ghost)
                                                <tr>
                                                    <td>{{ $ghost['name'] }}</td>
                                                    <td class="text-end">{{ number_format($ghost['active_members']) }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                                <form method="POST" action="{{ route('sync.local.clean-ghosts') }}"
                                      onsubmit="return confirm('Archive {{ number_format($ghosts['total']) }} ghost records now? They will be kept as archived records.');">
                                    @csrf
                                    <button class="sync-btn sync-btn--warning" type="submit">Archive ghost records</button>
                                </form>
                            </div>
                        </details>
                    @endif
                @else
                    <div class="sync-body">
                        <p class="sync-help">
                            Generate a one-time enrollment token on the central server, then enter it here with a name
                            for this installation. The local server UUID is generated automatically and becomes the origin
                            of all locally created records.
                        </p>
                        <div class="alert alert-light border small py-2 mb-3">
                            <div>
                                <span class="text-muted">Central Server:</span>
                                @if ($bootstrapUrl)
                                    <code>{{ $bootstrapUrl }}</code>
                                @else
                                    <span class="text-danger">not provisioned - reinstall from a package that includes the Central Server address.</span>
                                @endif
                            </div>
                            @if ($bootstrapIsLoopback)
                                <div class="text-danger mt-1">
                                    This is a loopback address: it points at this device, not at the Central Server, so
                                    enrollment will fail. Set <code>APP_URL</code> on the Central Server to its LAN
                                    address (for example <code>http://192.168.1.122/TIMS-BARMM/public</code>) and rebuild
                                    the package - or set <code>SYNC_BOOTSTRAP_URL</code> in this device's <code>.env</code>
                                    and run <code>php artisan config:clear</code>.
                                </div>
                            @endif
                            <div class="text-muted mt-1">Coverage: entire BARMM region.</div>
                        </div>
                        <form method="POST" action="{{ route('sync.local.enroll') }}" class="sync-form-row">
                            @csrf
                            <div class="sync-field">
                                <label for="server_name">Server name</label>
                                <input type="text" class="sync-input @error('server_name') is-invalid @enderror"
                                       id="server_name" name="server_name" value="{{ old('server_name') }}"
                                       placeholder="e.g. Lanao del Sur Offline" required>
                                @error('server_name')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="sync-field">
                                <label for="enrollment_token">Enrollment token</label>
                                <input type="text" class="sync-input font-monospace @error('enrollment_token') is-invalid @enderror"
                                       id="enrollment_token" name="enrollment_token" value="{{ old('enrollment_token') }}"
                                       autocomplete="off" required>
                                @error('enrollment_token')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div>
                                <button class="sync-btn sync-btn--primary" type="submit">Enroll this server</button>
                            </div>
                        </form>
                    </div>
                @endif
            </section>

            @if ($enrolledState)
                @php $update = $appUpdate['status']; @endphp
                <section class="sync-card" aria-labelledby="sync-update-title">
                    <div class="sync-section-head">
                        <div>
                            <h2 class="sync-section-title" id="sync-update-title">Application Update</h2>
                            <p class="sync-section-subtitle">Application packages are published from the central server. Data sync is separate.</p>
                        </div>
                        @if ($appUpdate['ready'])
                            <span class="sync-pill sync-pill--warning">Ready to apply</span>
                        @elseif ($update['has_update'])
                            <span class="sync-pill sync-pill--info">Available</span>
                        @else
                            <span class="sync-pill sync-pill--neutral">Up to date</span>
                        @endif
                    </div>
                    <div class="sync-body">
                        <dl class="sync-kv">
                            @php
                                $currentBuild = $update['applied_label']
                                    ?: ($update['applied_at'] ? ($update['label'] ?: 'Not recorded') : 'Not recorded');
                            @endphp
                            <div class="sync-kv-row"><dt class="sync-kv-label">Current build</dt><dd class="sync-kv-value">{{ $currentBuild }}</dd></div>
                            <div class="sync-kv-row"><dt class="sync-kv-label">Published version</dt><dd class="sync-kv-value">{{ $update['label'] ?: 'None published for this server' }}</dd></div>
                            <div class="sync-kv-row">
                                <dt class="sync-kv-label">Package</dt>
                                <dd class="sync-kv-value">
                                    @if ($update['filename'])
                                        <code>{{ $update['filename'] }}</code>
                                        @if ($update['size'])
                                            <span class="text-muted">({{ number_format($update['size'] / 1048576, 2) }} MB)</span>
                                        @endif
                                    @else
                                        <span class="text-muted">-</span>
                                    @endif
                                </dd>
                            </div>
                            <div class="sync-kv-row">
                                <dt class="sync-kv-label">Checksum</dt>
                                <dd class="sync-kv-value">
                                    @if ($update['sha256'])
                                        <code class="text-break">{{ $update['sha256'] }}</code>
                                        @if ($update['verified'])
                                            <span class="badge text-bg-success">Verified</span>
                                        @endif
                                    @else
                                        <span class="text-muted">-</span>
                                    @endif
                                </dd>
                            </div>
                            <div class="sync-kv-row"><dt class="sync-kv-label">Last checked</dt><dd class="sync-kv-value">{{ $update['checked_at'] ?: 'Never' }}</dd></div>
                            <div class="sync-kv-row"><dt class="sync-kv-label">Last applied</dt><dd class="sync-kv-value">{{ $update['applied_at'] ?: 'Never' }}</dd></div>
                        </dl>

                        <div id="update-progress" class="mt-3" hidden
                             data-status-url="{{ route('sync.local.app-update.status') }}"
                             data-applying="{{ $appUpdate['applying'] ? '1' : '0' }}"
                             data-state="{{ $appUpdate['progress']['state'] }}"
                             data-percent="{{ $appUpdate['progress']['percent'] }}"
                             data-step="{{ $appUpdate['progress']['step'] }}">
                            <div class="d-flex justify-content-between small mb-1">
                                <span id="update-progress-step">{{ $appUpdate['progress']['step'] }}</span>
                                <span id="update-progress-percent">{{ $appUpdate['progress']['percent'] }}%</span>
                            </div>
                            <div class="sync-progress" role="progressbar" aria-label="Update progress"
                                 aria-valuemin="0" aria-valuemax="100"
                                 aria-valuenow="{{ $appUpdate['progress']['percent'] }}">
                                <div class="h-100 bg-primary" id="update-progress-bar"
                                     style="width: {{ $appUpdate['progress']['percent'] }}%; transition: width .2s ease;"></div>
                            </div>
                            <div class="small text-muted mt-1" id="update-progress-note">
                                Do not close this page while the update is running.
                            </div>
                        </div>

                        @if ($update['error'])
                            <div class="alert alert-danger small mb-0 mt-3">{{ $update['error'] }}</div>
                        @endif
                    </div>
                </section>
            @endif
        </div>

        <div class="sync-grid" style="margin-bottom: 0;">
            <section class="sync-card" aria-labelledby="sync-diagnostics-title">
                <div class="sync-section-head">
                    <div>
                        <h2 class="sync-section-title" id="sync-diagnostics-title">Diagnostics</h2>
                        <p class="sync-section-subtitle">Live health of this installation and its connection to central.</p>
                    </div>
                </div>
                <div class="sync-diag">
                    @foreach ($checks as $check)
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

            @if ($isOffline)
                <section class="sync-card" aria-labelledby="sync-audit-title">
                    <div class="sync-section-head">
                        <div>
                            <h2 class="sync-section-title" id="sync-audit-title">Audit trail</h2>
                            <p class="sync-section-subtitle">Audit records stay on this device and are sent with the next sync.</p>
                        </div>
                        <a class="sync-btn sync-btn--secondary sync-btn--small" href="{{ route('audit.index') }}">Open audit log</a>
                    </div>
                    <div class="sync-body">
                        <div class="sync-audit">
                            <div>
                                <div class="sync-audit-value">{{ number_format($audit['pending']) }}</div>
                                <div class="sync-audit-label">Events waiting to be reported</div>
                            </div>
                            <div>
                                <div class="sync-audit-value" style="font-size: 16px;">{{ $audit['last_sync_at'] ?: 'Never' }}</div>
                                <div class="sync-audit-label">Last reported to the central server</div>
                            </div>
                            <div>
                                <div class="sync-audit-value" style="font-size: 16px;">
                                    @if ($audit['error'])
                                        <span class="text-danger">Failed</span>
                                    @else
                                        <span class="text-success">Reported</span>
                                    @endif
                                </div>
                                <div class="sync-audit-label">Status</div>
                            </div>
                        </div>
                        @if ($audit['error'])
                            <div class="alert alert-warning small mt-3 mb-0">{{ $audit['error'] }}</div>
                        @endif
                        <div class="small text-muted mt-2">
                            Encoder accounts are reported as a read-only identity registry; passwords never leave this device.
                        </div>
                    </div>
                </section>
            @endif
        </div>
    </div>

    <section class="sync-card mb-3" aria-labelledby="sync-network-title">
        <div class="sync-section-head">
            <div>
                <h2 class="sync-section-title" id="sync-network-title">Local network access</h2>
                <p class="sync-section-subtitle">Open this installation from client devices on the same network.</p>
            </div>
            <span class="sync-pill sync-pill--info" id="lan-status-badge">Detecting IP...</span>
        </div>
        <div class="sync-body">
            <div class="sync-network-grid">
                <div>
                    <label class="sync-kv-label d-block mb-1" for="local-app-url">Application URL for client devices</label>
                    <div class="sync-url-group mb-2">
                        <input type="text" class="sync-input" id="local-app-url" value="{{ url('/') }}" readonly>
                        <button class="sync-btn sync-btn--secondary" type="button" id="copy-url-btn"
                                onclick="navigator.clipboard.writeText(document.getElementById('local-app-url').value)
                                           .then(() => { this.textContent = 'Copied!'; setTimeout(() => this.textContent = 'Copy', 1500); })">
                            Copy
                        </button>
                    </div>

                    <p class="sync-help mb-2">
                        Connect client devices to the same isolated hotspot or LAN and open this URL.
                        Allow Apache only on the <strong>Private</strong> network profile in Windows Firewall.
                        Do <strong>not</strong> expose this offline installation to the public internet or an untrusted network.
                    </p>

                    <div class="sync-callout mb-0" role="alert">
                        <div>
                            <strong>Sleep / Hibernate:</strong>
                            If this laptop sleeps or hibernates, client devices will lose connection and data entry may be
                            interrupted. Disable sleep in Windows Power Options when the offline server is in active use.
                        </div>
                    </div>
                </div>

                <div class="sync-qr">
                    <div class="sync-kv-label mb-1">Scan to open on a client device</div>
                    <canvas id="url-qrcode" width="160" height="160" aria-label="QR code for the local application URL"></canvas>
                    <div class="sync-qr-url" id="qr-url-label"></div>
                </div>
            </div>
        </div>
    </section>

    <section class="sync-card" aria-labelledby="sync-operations-title">
        <div class="sync-section-head">
            <div>
                <h2 class="sync-section-title" id="sync-operations-title">Recent local operations</h2>
                <p class="sync-section-subtitle">The 20 most recent queued, applied or rejected synchronization operations.</p>
            </div>
            @if ($queue['retryable'] > 0)
                <form method="POST" action="{{ route('sync.local.retry-rejected') }}" class="m-0"
                      onsubmit="return confirm('Retry {{ number_format($queue['retryable']) }} eligible operation(s)? Only operations rejected by the former location-scope rule are requeued.');">
                    @csrf
                    <button type="submit" class="sync-btn sync-btn--warning sync-btn--small">
                        Retry {{ number_format($queue['retryable']) }} eligible {{ Str::plural('operation', $queue['retryable']) }}
                    </button>
                </form>
            @endif
        </div>

        @if ($operations->isEmpty())
            <div class="sync-empty">
                <div class="sync-empty-title">No local operations recorded yet</div>
                <div class="sync-empty-note">Operations appear here after beneficiaries or batches are created, changed or deleted.</div>
            </div>
        @else
            <div class="table-responsive">
                <table class="sync-ops-table">
                    <thead>
                        <tr>
                            <th scope="col">When</th>
                            <th scope="col">Entity</th>
                            <th scope="col">Operation</th>
                            <th scope="col">Revision</th>
                            <th scope="col">Status</th>
                            <th scope="col">Attempts</th>
                            <th scope="col">Error</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($operations as $operation)
                            @php
                                $opStatus = (string) $operation->status;
                                $opPill = match ($opStatus) {
                                    'synced' => 'sync-pill--ok',
                                    'awaiting_review' => 'sync-pill--info',
                                    'failed' => 'sync-pill--warning',
                                    'rejected', 'conflict' => 'sync-pill--danger',
                                    default => 'sync-pill--neutral',
                                };
                                $opLabel = ucfirst(str_replace('_', ' ', $opStatus));
                            @endphp
                            <tr>
                                <td class="sync-ops-when">{{ $operation->created_at?->diffForHumans() }}</td>
                                <td>
                                    <div class="sync-ops-entity">
                                        <span class="sync-ops-kind">{{ $operation->entity_type }}</span>
                                        <code class="sync-ops-uuid" title="{{ $operation->entity_uuid }}">{{ $operation->entity_uuid }}</code>
                                        <button type="button" class="sync-copy" data-copy="{{ $operation->entity_uuid }}"
                                                aria-label="Copy entity ID">Copy</button>
                                    </div>
                                </td>
                                <td>{{ $operation->operation }}</td>
                                <td class="sync-ops-num">{{ $operation->base_revision }}</td>
                                <td><span class="sync-pill {{ $opPill }}">{{ $opLabel }}</span></td>
                                <td class="sync-ops-num">{{ $operation->attempts }}</td>
                                <td class="sync-ops-error">
                                    {{ $operation->last_error ?: '' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</div>
@endsection

@push('scripts')
<script>
{{-- Sync Now: block a second initiation while the run is in flight. The
     server also holds a cache lock, so a duplicate request that slips
     through is rejected with "already in progress" rather than run twice.
     Progress beyond this is not available: the run is one synchronous
     request, and the real per-step outcome is rendered from the flashed
     result when the page reloads. --}}
(function () {
    var form = document.getElementById('sync-now-form');
    var button = document.getElementById('sync-now-button');

    if (form && button) {
        form.addEventListener('submit', function () {
            button.disabled = true;
            button.textContent = 'Syncing…';
        });
    }
})();
</script>

{{-- Minimal QR code generator (qr.js, MIT license, ~4KB inline) --}}
<script>
/**
 * qrcode-generator v1.4.4 (MIT)
 * https://github.com/kazuhikoarase/qrcode-generator
 * Minified inline to avoid CDN dependency on the offline server.
 */
var qrcode=function(){var qrcode=function(typeNumber,errorCorrectionLevel){var PAD0=0xEC,PAD1=0x11,_typeNumber=typeNumber,_errorCorrectionLevel=QRErrorCorrectionLevel[errorCorrectionLevel],_modules=null,_moduleCount=0,_dataCache=null,_dataList=[],_this={},_test=function(row,col){return _modules[row][col]},_getModuleCount=function(){return _moduleCount};_this.addData=function(data,mode){mode=mode||'Byte';_dataList.push(qrBitBuffer(data,mode))};_this.isDark=_test;_this.getModuleCount=_getModuleCount;_this.make=function(){if(_typeNumber<1){var typeNumber=1;for(typeNumber=1;typeNumber<40;typeNumber++){var rsBlocks=QRRSBlock.getRSBlocks(typeNumber,_errorCorrectionLevel);var buffer=qrBitBuf();for(var i=0;i<_dataList.length;i++){buffer.put(_dataList[i].getMode(),4);buffer.putBytes(_dataList[i].getBytes(),_dataList[i].getLengthInBits(typeNumber))}var totalDataCount=0;for(var i=0;i<rsBlocks.length;i++){totalDataCount+=rsBlocks[i].dataCount}if(buffer.getLengthInBits()<=totalDataCount*8)break}_typeNumber=typeNumber}_makeImpl(false,_getBestMaskPattern())};_this.createTableTag=function(cellSize,margin){cellSize=cellSize||2;margin=typeof margin=='undefined'?cellSize*4:margin;var qrHtml='';qrHtml+='<table style="border:0;border-collapse:collapse;">';for(var r=0;r<_moduleCount+margin*2/cellSize;r++){qrHtml+='<tr>';for(var c=0;c<_moduleCount+margin*2/cellSize;c++){var dark=false;if(r>=margin/cellSize&&r<_moduleCount+margin/cellSize&&c>=margin/cellSize&&c<_moduleCount+margin/cellSize){dark=_test(r-margin/cellSize,c-margin/cellSize)}qrHtml+='<td style="border:0;width:'+cellSize+'px;height:'+cellSize+'px;background:'+(dark?'#000':'#fff')+';"></td>'}qrHtml+='</tr>'}qrHtml+='</table>';return qrHtml};_this.renderTo2dContext=function(context,cellSize){cellSize=cellSize||1;for(var r=0;r<_moduleCount;r++){for(var c=0;c<_moduleCount;c++){context.fillStyle=_test(r,c)?'#000000':'#ffffff';context.fillRect(c*cellSize,r*cellSize,cellSize,cellSize)}}};var _makeImpl=function(test,maskPattern){_moduleCount=_typeNumber*4+17;_modules=new Array(_moduleCount);for(var row=0;row<_moduleCount;row++){_modules[row]=new Array(_moduleCount)}setupPositionProbePattern(0,0);setupPositionProbePattern(_moduleCount-7,0);setupPositionProbePattern(0,_moduleCount-7);setupPositionAdjustPattern();setupTimingPattern();setupTypeInfo(test,maskPattern);if(_typeNumber>=7)setupTypeNumber(test);if(_dataCache==null)_dataCache=createData(_typeNumber,_errorCorrectionLevel,_dataList);mapData(_dataCache,maskPattern)};var setupPositionProbePattern=function(row,col){for(var r=-1;r<=7;r++){if(row+r<=-1||_moduleCount<=row+r)continue;for(var c=-1;c<=7;c++){if(col+c<=-1||_moduleCount<=col+c)continue;if((0<=r&&r<=6&&(c==0||c==6))||(0<=c&&c<=6&&(r==0||r==6))||(2<=r&&r<=4&&2<=c&&c<=4)){_modules[row+r][col+c]=true}else{_modules[row+r][col+c]=false}}}};var _getBestMaskPattern=function(){var minLostPoint=0;var pattern=0;for(var i=0;i<8;i++){_makeImpl(true,i);var lostPoint=QRUtil.getLostPoint(_this);if(i==0||minLostPoint>lostPoint){minLostPoint=lostPoint;pattern=i}}return pattern};var setupTimingPattern=function(){for(var r=8;r<_moduleCount-8;r++){if(_modules[r][6]!=null)continue;_modules[r][6]=(r%2==0)}for(var c=8;c<_moduleCount-8;c++){if(_modules[6][c]!=null)continue;_modules[6][c]=(c%2==0)}};var setupPositionAdjustPattern=function(){var pos=QRUtil.getPatternPosition(_typeNumber);for(var i=0;i<pos.length;i++){for(var j=0;j<pos.length;j++){var row=pos[i];var col=pos[j];if(_modules[row][col]!=null)continue;for(var r=-2;r<=2;r++){for(var c=-2;c<=2;c++){if(r==-2||r==2||c==-2||c==2||(r==0&&c==0)){_modules[row+r][col+c]=true}else{_modules[row+r][col+c]=false}}}}}};var setupTypeNumber=function(test){var bits=QRUtil.getBCHTypeNumber(_typeNumber);for(var i=0;i<18;i++){var mod=(!test&&((bits>>i)&1)==1);_modules[Math.floor(i/3)][i%3+_moduleCount-8-3]=mod}for(var i=0;i<18;i++){var mod=(!test&&((bits>>i)&1)==1);_modules[i%3+_moduleCount-8-3][Math.floor(i/3)]=mod}};var setupTypeInfo=function(test,maskPattern){var data=(_errorCorrectionLevel<<3)|maskPattern;var bits=QRUtil.getBCHTypeInfo(data);for(var i=0;i<15;i++){var mod=(!test&&((bits>>i)&1)==1);if(i<6){_modules[i][8]=mod}else if(i<8){_modules[i+1][8]=mod}else{_modules[_moduleCount-15+i][8]=mod}}for(var i=0;i<15;i++){var mod=(!test&&((bits>>i)&1)==1);if(i<8){_modules[8][_moduleCount-i-1]=mod}else if(i<9){_modules[8][15-i-1+1]=mod}else{_modules[8][15-i-1]=mod}}_modules[_moduleCount-8][8]=(!test)};var mapData=function(data,maskPattern){var inc=-1;var row=_moduleCount-1;var bitIndex=7;var byteIndex=0;var maskFunc=QRUtil.getMaskFunction(maskPattern);for(var col=_moduleCount-1;col>0;col-=2){if(col==6)col--;while(true){for(var c=0;c<2;c++){if(_modules[row][col-c]==null){var dark=false;if(byteIndex<data.length)dark=(((data[byteIndex]>>>bitIndex)&1)==1);if(maskFunc(row,col-c))dark=!dark;_modules[row][col-c]=dark;bitIndex--;if(bitIndex==-1){byteIndex++;bitIndex=7}}}row+=inc;if(row<0||_moduleCount<=row){row-=inc;inc=-inc;break}}}};var createData=function(typeNumber,errorCorrectionLevel,dataList){var rsBlocks=QRRSBlock.getRSBlocks(typeNumber,errorCorrectionLevel);var buffer=qrBitBuf();for(var i=0;i<dataList.length;i++){buffer.put(dataList[i].getMode(),4);buffer.putBytes(dataList[i].getBytes(),dataList[i].getLengthInBits(typeNumber))}var totalDataCount=0;for(var i=0;i<rsBlocks.length;i++){totalDataCount+=rsBlocks[i].dataCount}if(buffer.getLengthInBits()>totalDataCount*8){throw'code length overflow. ('+buffer.getLengthInBits()+'>'+totalDataCount*8+')'}if(buffer.getLengthInBits()+4<=totalDataCount*8)buffer.put(0,4);while(buffer.getLengthInBits()%8!=0)buffer.putBit(false);while(true){if(buffer.getLengthInBits()>=totalDataCount*8)break;buffer.put(PAD0,8);if(buffer.getLengthInBits()>=totalDataCount*8)break;buffer.put(PAD1,8)}return createBytes(buffer,rsBlocks)};var createBytes=function(buffer,rsBlocks){var offset=0;var maxDcCount=0;var maxEcCount=0;var dcdata=new Array(rsBlocks.length);var ecdata=new Array(rsBlocks.length);for(var r=0;r<rsBlocks.length;r++){var dcCount=rsBlocks[r].dataCount;var ecCount=rsBlocks[r].totalCount-dcCount;maxDcCount=Math.max(maxDcCount,dcCount);maxEcCount=Math.max(maxEcCount,ecCount);dcdata[r]=new Array(dcCount);for(var i=0;i<dcdata[r].length;i++){dcdata[r][i]=0xff&buffer.getBuffer()[i+offset]}offset+=dcCount;var rsPoly=QRUtil.getErrorCorrectPolynomial(ecCount);var rawPoly=qrPolynomial(dcdata[r],rsPoly.getLength()-1);var modPoly=rawPoly.mod(rsPoly);ecdata[r]=new Array(rsPoly.getLength()-1);for(var i=0;i<ecdata[r].length;i++){var modIndex=i+modPoly.getLength()-ecdata[r].length;ecdata[r][i]=(modIndex>=0)?modPoly.getAt(modIndex):0}}var totalCodeCount=0;for(var i=0;i<rsBlocks.length;i++){totalCodeCount+=rsBlocks[i].totalCount}var data=new Array(totalCodeCount);var index=0;for(var i=0;i<maxDcCount;i++){for(var r=0;r<rsBlocks.length;r++){if(i<dcdata[r].length){data[index]=dcdata[r][i];index++}}}for(var i=0;i<maxEcCount;i++){for(var r=0;r<rsBlocks.length;r++){if(i<ecdata[r].length){data[index]=ecdata[r][i];index++}}}return data};return _this};var QRMode={MODE_NUMBER:1,MODE_ALPHA_NUM:2,MODE_8BIT_BYTE:4,MODE_KANJI:8};var QRErrorCorrectionLevel={L:1,M:0,Q:3,H:2};var QRMaskPattern={PATTERN000:0,PATTERN001:1,PATTERN010:2,PATTERN011:3,PATTERN100:4,PATTERN101:5,PATTERN110:6,PATTERN111:7};var QRUtil=function(){var PATTERN_POSITION_TABLE=[[],[6,18],[6,22],[6,26],[6,30],[6,34],[6,22,38],[6,24,42],[6,26,46],[6,28,50],[6,30,54],[6,32,58],[6,34,62],[6,26,46,66],[6,26,48,70],[6,26,50,74],[6,30,54,78],[6,30,56,82],[6,30,58,86],[6,34,62,90],[6,28,50,72,94],[6,26,50,74,98],[6,30,54,78,102],[6,28,54,80,106],[6,32,58,84,110],[6,30,58,86,114],[6,34,62,90,118],[6,26,50,74,98,122],[6,30,54,78,102,126],[6,26,52,78,104,130],[6,30,56,82,108,134],[6,34,60,86,112,138],[6,30,58,86,114,142],[6,34,62,90,118,146],[6,30,54,78,102,126,150],[6,24,50,76,102,128,154],[6,28,54,80,106,132,158],[6,32,58,84,110,136,162],[6,26,54,82,110,138,166],[6,30,58,86,114,142,170]];var G15=(1<<10)|(1<<8)|(1<<5)|(1<<4)|(1<<2)|(1<<1)|(1<<0);var G18=(1<<12)|(1<<11)|(1<<10)|(1<<9)|(1<<8)|(1<<5)|(1<<2)|(1<<0);var G15_MASK=(1<<14)|(1<<12)|(1<<10)|(1<<4)|(1<<1);var _this={};var getBCHDigit=function(data){var digit=0;while(data!=0){digit+=1;data>>>=1}return digit};_this.getBCHTypeInfo=function(data){var d=data<<10;while(getBCHDigit(d)-getBCHDigit(G15)>=0){d^=(G15<<(getBCHDigit(d)-getBCHDigit(G15)))}return((data<<10)|d)^G15_MASK};_this.getBCHTypeNumber=function(data){var d=data<<12;while(getBCHDigit(d)-getBCHDigit(G18)>=0){d^=(G18<<(getBCHDigit(d)-getBCHDigit(G18)))}return(data<<12)|d};_this.getPatternPosition=function(typeNumber){return PATTERN_POSITION_TABLE[typeNumber-1]};_this.getMaskFunction=function(maskPattern){switch(maskPattern){case QRMaskPattern.PATTERN000:return function(i,j){return(i+j)%2==0};case QRMaskPattern.PATTERN001:return function(i,j){return i%2==0};case QRMaskPattern.PATTERN010:return function(i,j){return j%3==0};case QRMaskPattern.PATTERN011:return function(i,j){return(i+j)%3==0};case QRMaskPattern.PATTERN100:return function(i,j){return(Math.floor(i/2)+Math.floor(j/3))%2==0};case QRMaskPattern.PATTERN101:return function(i,j){return(i*j)%2+(i*j)%3==0};case QRMaskPattern.PATTERN110:return function(i,j){return((i*j)%2+(i*j)%3)%2==0};case QRMaskPattern.PATTERN111:return function(i,j){return((i*j)%3+(i+j)%2)%2==0};default:throw'bad maskPattern:'+maskPattern}};_this.getErrorCorrectPolynomial=function(errorCorrectLength){var a=qrPolynomial([1],0);for(var i=0;i<errorCorrectLength;i++){a=a.multiply(qrPolynomial([1,QRMath.gexp(i)],0))}return a};_this.getLostPoint=function(qrcode){var moduleCount=qrcode.getModuleCount();var lostPoint=0;for(var row=0;row<moduleCount;row++){for(var col=0;col<moduleCount;col++){var sameCount=0;var dark=qrcode.isDark(row,col);for(var r=-1;r<=1;r++){if(row+r<0||moduleCount<=row+r)continue;for(var c=-1;c<=1;c++){if(col+c<0||moduleCount<=col+c)continue;if(r==0&&c==0)continue;if(dark==qrcode.isDark(row+r,col+c)){sameCount++}}}if(sameCount>5){lostPoint+=(3+sameCount-5)}}}for(var row=0;row<moduleCount-1;row++){for(var col=0;col<moduleCount-1;col++){var count=0;if(qrcode.isDark(row,col))count++;if(qrcode.isDark(row+1,col))count++;if(qrcode.isDark(row,col+1))count++;if(qrcode.isDark(row+1,col+1))count++;if(count==0||count==4){lostPoint+=3}}}for(var row=0;row<moduleCount;row++){for(var col=0;col<moduleCount-6;col++){if(qrcode.isDark(row,col)&&!qrcode.isDark(row,col+1)&&qrcode.isDark(row,col+2)&&qrcode.isDark(row,col+3)&&qrcode.isDark(row,col+4)&&!qrcode.isDark(row,col+5)&&qrcode.isDark(row,col+6)){lostPoint+=40}}}for(var col=0;col<moduleCount;col++){for(var row=0;row<moduleCount-6;row++){if(qrcode.isDark(row,col)&&!qrcode.isDark(row+1,col)&&qrcode.isDark(row+2,col)&&qrcode.isDark(row+3,col)&&qrcode.isDark(row+4,col)&&!qrcode.isDark(row+5,col)&&qrcode.isDark(row+6,col)){lostPoint+=40}}}var darkCount=0;for(var col=0;col<moduleCount;col++){for(var row=0;row<moduleCount;row++){if(qrcode.isDark(row,col)){darkCount++}}}var ratio=Math.abs(100*darkCount/moduleCount/moduleCount-50)/5;lostPoint+=ratio*10;return lostPoint};return _this}();var QRMath=function(){var EXP_TABLE=new Array(256);var LOG_TABLE=new Array(256);for(var i=0;i<8;i++){EXP_TABLE[i]=1<<i}for(var i=8;i<256;i++){EXP_TABLE[i]=EXP_TABLE[i-4]^EXP_TABLE[i-5]^EXP_TABLE[i-6]^EXP_TABLE[i-8]}for(var i=0;i<255;i++){LOG_TABLE[EXP_TABLE[i]]=i}var _this={};_this.glog=function(n){if(n<1)throw'glog('+n+')';return LOG_TABLE[n]};_this.gexp=function(n){while(n<0)n+=255;while(n>=256)n-=255;return EXP_TABLE[n]};return _this}();function qrPolynomial(num,shift){if(typeof num.length=='undefined')throw num.length+'/'+shift;var _num=function(){var offset=0;while(offset<num.length&&num[offset]==0)offset++;var _num=new Array(num.length-offset+shift);for(var i=0;i<num.length-offset;i++){_num[i]=num[i+offset]}return _num}();var _this={};_this.getAt=function(index){return _num[index]};_this.getLength=function(){return _num.length};_this.multiply=function(e){var num=new Array(_this.getLength()+e.getLength()-1);for(var i=0;i<_this.getLength();i++){for(var j=0;j<e.getLength();j++){num[i+j]^=QRMath.gexp(QRMath.glog(_this.getAt(i))+QRMath.glog(e.getAt(j)))}}return qrPolynomial(num,0)};_this.mod=function(e){if(_this.getLength()-e.getLength()<0)return _this;var ratio=QRMath.glog(_this.getAt(0))-QRMath.glog(e.getAt(0));var num=new Array(_this.getLength());for(var i=0;i<_this.getLength();i++){num[i]=_this.getAt(i)}for(var i=0;i<e.getLength();i++){num[i]^=QRMath.gexp(QRMath.glog(e.getAt(i))+ratio)}return qrPolynomial(num,0).mod(e)};return _this}var QRRSBlock=function(){var RS_BLOCK_TABLE=[[1,26,19],[1,26,16],[1,26,13],[1,26,9],[1,44,34],[1,44,28],[1,44,22],[1,44,16],[1,70,55],[1,70,44],[2,35,17],[2,35,13],[1,100,80],[2,50,32],[2,50,24],[4,25,9],[1,134,108],[2,67,43],[2,33,15,2,34,16],[2,33,11,2,34,12],[2,86,68],[4,43,27],[4,43,19],[4,43,15],[2,98,78],[4,49,31],[2,32,14,4,33,15],[4,39,13,1,40,14],[2,121,97],[2,60,38,2,61,39],[4,40,18,2,41,19],[4,40,14,2,41,15],[2,146,116],[3,58,36,2,59,37],[4,36,16,4,37,17],[4,36,12,4,37,13],[2,86,68,2,87,69],[4,69,43,1,70,44],[6,43,19,2,44,20],[6,43,15,2,44,16],[4,101,81],[1,80,50,4,81,51],[4,50,22,4,51,23],[3,36,12,8,37,13],[2,116,92,2,117,93],[6,58,36,2,59,37],[4,46,20,6,47,21],[7,42,14,4,43,15],[4,133,107],[8,59,37,1,60,38],[8,44,20,4,45,21],[12,33,11,4,34,12],[3,145,115,1,146,116],[4,64,40,5,65,41],[11,36,16,5,37,17],[11,36,12,5,37,13],[5,109,87,1,110,88],[5,65,41,5,66,42],[5,54,24,7,55,25],[11,36,12],[5,122,98,1,123,99],[7,73,45,3,74,46],[15,43,19,2,44,20],[3,45,15,13,46,16],[1,135,107,5,136,108],[10,74,46,1,75,47],[1,50,22,15,51,23],[2,42,14,17,43,15],[5,150,120,1,151,121],[9,69,43,4,70,44],[17,50,22,1,51,23],[2,42,14,19,43,15],[3,141,113,4,142,114],[3,70,44,11,71,45],[17,51,21,4,52,22],[9,39,13,16,40,14],[3,135,107,5,136,108],[3,67,41,13,68,42],[15,54,24,5,55,25],[15,43,15,10,44,16],[4,144,116,4,145,117],[17,68,42],[17,50,22,6,51,23],[19,46,16,6,47,17],[2,139,111,7,140,112],[17,74,46],[7,54,24,16,55,25],[34,37,13],[4,151,121,5,152,122],[4,75,47,14,76,48],[11,54,24,14,55,25],[16,45,15,14,46,16],[6,147,117,4,148,118],[6,73,45,14,74,46],[11,54,24,16,55,25],[30,46,16,2,47,17],[8,132,106,4,133,107],[8,75,47,13,76,48],[7,54,24,22,55,25],[22,45,15,13,46,16],[10,142,114,2,143,115],[19,74,46,4,75,47],[28,50,22,6,51,23],[33,46,16,4,47,17],[8,152,122,4,153,123],[22,73,45,3,74,46],[8,53,23,26,54,24],[12,45,15,28,46,16],[3,147,117,10,148,118],[3,73,45,23,74,46],[4,54,24,31,55,25],[11,45,15,31,46,16],[7,146,116,7,147,117],[21,73,45,7,74,46],[1,53,23,37,54,24],[19,45,15,26,46,16],[5,145,115,10,146,116],[19,75,47,10,76,48],[15,54,24,25,55,25],[23,45,15,25,46,16],[13,145,115,3,146,116],[2,74,46,29,75,47],[42,54,24,1,55,25],[23,45,15,28,46,16],[17,145,115],[10,74,46,23,75,47],[10,54,24,35,55,25],[19,45,15,35,46,16],[17,145,115,1,146,116],[14,74,46,21,75,47],[29,54,24,19,55,25],[11,45,15,46,46,16],[13,145,115,6,146,116],[14,74,46,23,75,47],[44,54,24,7,55,25],[59,46,16,1,47,17],[12,151,121,7,152,122],[12,75,47,26,76,48],[39,54,24,14,55,25],[22,45,15,41,46,16],[6,151,121,14,152,122],[6,75,47,34,76,48],[46,54,24,10,55,25],[2,45,15,64,46,16],[17,152,122,4,153,123],[29,74,46,14,75,47],[49,54,24,10,55,25],[24,45,15,46,46,16],[4,152,122,18,153,123],[13,74,46,32,75,47],[48,54,24,14,55,25],[42,45,15,32,46,16],[20,147,117,4,148,118],[40,75,47,7,76,48],[43,54,24,22,55,25],[10,45,15,67,46,16],[19,148,118,6,149,119],[18,75,47,31,76,48],[34,54,24,34,55,25],[20,45,15,61,46,16]];var qrRSBlock=function(totalCount,dataCount){var _this={};_this.totalCount=totalCount;_this.dataCount=dataCount;return _this};var _this={};var getRsBlockTable=function(typeNumber,errorCorrectionLevel){switch(errorCorrectionLevel){case QRErrorCorrectionLevel.L:return RS_BLOCK_TABLE[(typeNumber-1)*4+0];case QRErrorCorrectionLevel.M:return RS_BLOCK_TABLE[(typeNumber-1)*4+1];case QRErrorCorrectionLevel.Q:return RS_BLOCK_TABLE[(typeNumber-1)*4+2];case QRErrorCorrectionLevel.H:return RS_BLOCK_TABLE[(typeNumber-1)*4+3];default:return undefined}};_this.getRSBlocks=function(typeNumber,errorCorrectionLevel){var rsBlock=getRsBlockTable(typeNumber,errorCorrectionLevel);if(typeof rsBlock=='undefined')throw'bad rs block @ typeNumber:'+typeNumber+'/errorCorrectionLevel:'+errorCorrectionLevel;var length=rsBlock.length/3;var list=[];for(var i=0;i<length;i++){var count=rsBlock[i*3+0];var totalCount=rsBlock[i*3+1];var dataCount=rsBlock[i*3+2];for(var j=0;j<count;j++){list.push(qrRSBlock(totalCount,dataCount))}}return list};return _this}();var qrBitBuffer=function(data,mode){var _mode=QRMode.MODE_8BIT_BYTE;var _bytes=[];if(typeof data==='string'){for(var i=0;i<data.length;i++){var c=data.charCodeAt(i);if(c<0x80){_bytes.push(c)}else if(c<0x800){_bytes.push(0xc0|(c>>>6));_bytes.push(0x80|(c&0x3f))}else{_bytes.push(0xe0|(c>>>12));_bytes.push(0x80|((c>>>6)&0x3f));_bytes.push(0x80|(c&0x3f))}}}var _this={};_this.getMode=function(){return _mode};_this.getLength=function(){return _bytes.length};_this.getBytes=function(){return _bytes};_this.getLengthInBits=function(typeNumber){if(1<=typeNumber&&typeNumber<10)return 8;else if(typeNumber<27)return 16;else if(typeNumber<41)return 16;else throw'type:'+typeNumber};return _this};var qrBitBuf=function(){var _buffer=[];var _length=0;var _this={};_this.getBuffer=function(){return _buffer};_this.getAt=function(index){var bufIndex=Math.floor(index/8);return((_buffer[bufIndex]>>>(7-index%8))&1)==1};_this.put=function(num,length){for(var i=0;i<length;i++){_this.putBit(((num>>>(length-i-1))&1)==1)}};_this.getLengthInBits=function(){return _length};_this.putBit=function(bit){var bufIndex=Math.floor(_length/8);if(_buffer.length<=bufIndex){_buffer.push(0)}if(bit){_buffer[bufIndex]|=(0x80>>>(_length%8))}_length++};_this.putBytes=function(bytes,length){for(var i=0;i<length;i++){_this.put(bytes[i],8)}};return _this};return qrcode}();
</script>

<script>
(function () {
    // Generate QR code on the canvas
    var canvas = document.getElementById('url-qrcode');
    var label  = document.getElementById('qr-url-label');
    var badge  = document.getElementById('lan-status-badge');
    var url    = document.getElementById('local-app-url')?.value || window.location.origin;

    if (canvas && typeof qrcode !== 'undefined') {
        try {
            var qr = qrcode(0, 'M');
            qr.addData(url);
            qr.make();

            var ctx = canvas.getContext('2d');
            var moduleCount = qr.getModuleCount();
            var cellSize = Math.floor(160 / moduleCount);
            canvas.width  = moduleCount * cellSize;
            canvas.height = moduleCount * cellSize;
            canvas.style.width  = (moduleCount * cellSize) + 'px';
            canvas.style.height = (moduleCount * cellSize) + 'px';

            qr.renderTo2dContext(ctx, cellSize);
            if (label) label.textContent = url;
        } catch (e) {
            canvas.style.display = 'none';
            if (label) label.textContent = 'QR generation failed.';
        }
    }

    // Detect LAN IP from the current hostname
    if (badge) {
        var hostname = window.location.hostname;
        var isIp = /^\d{1,3}(\.\d{1,3}){3}$/.test(hostname);
        if (isIp && hostname !== '127.0.0.1') {
            badge.textContent = 'LAN IP: ' + hostname;
            badge.classList.remove('text-bg-info');
            badge.classList.add('text-bg-success');
        } else if (hostname === 'localhost' || hostname === '127.0.0.1') {
            badge.textContent = 'Localhost only';
            badge.classList.remove('text-bg-info');
            badge.classList.add('text-bg-warning');
        } else {
            badge.textContent = hostname;
            badge.classList.remove('text-bg-info');
            badge.classList.add('text-bg-success');
        }
    }

    // Central server connection status polling
    var connectionBadge = document.getElementById('connection-badge');
    var connectionLastChecked = document.getElementById('connection-last-checked');
    var connectionStatusUrl = '{{ route('sync.local.connection') }}';

    function updateConnectionBadge(data) {
        if (!connectionBadge) return;

        var statusMap = {
            'connected': { class: 'text-bg-success', text: 'Connected' },
            'offline': { class: 'text-bg-danger', text: 'Offline' },
            'auth_error': { class: 'text-bg-warning', text: 'Auth error' },
            'sync_error': { class: 'text-bg-warning', text: 'Sync error' },
            'not_configured': { class: 'text-bg-secondary', text: 'Not configured' },
        };

        var info = statusMap[data.status] || { class: 'text-bg-secondary', text: 'Unknown' };
        connectionBadge.textContent = info.text;
        connectionBadge.className = 'badge ' + info.class;

        if (connectionLastChecked) {
            connectionLastChecked.textContent = '(checked ' + new Date().toLocaleTimeString() + ')';
        }
    }

    function pollConnection() {
        if (!connectionStatusUrl) return;

        fetch(connectionStatusUrl, {
            method: 'GET',
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin',
        })
        .then(function(response) {
            return response.json();
        })
        .then(function(data) {
            updateConnectionBadge(data);
        })
        .catch(function() {
            if (connectionBadge) {
                connectionBadge.textContent = 'Check failed';
                connectionBadge.className = 'badge text-bg-danger';
            }
        });
    }

    // Initial check and then every 30 seconds
    if (connectionBadge) {
        pollConnection();
        setInterval(pollConnection, 30000);
    }
})();
</script>

<script>
(function () {
    var panel = document.getElementById('update-progress');

    if (!panel) {
        return;
    }

    var statusUrl = panel.getAttribute('data-status-url');
    var bar = document.getElementById('update-progress-bar');
    var stepEl = document.getElementById('update-progress-step');
    var percentEl = document.getElementById('update-progress-percent');
    var noteEl = document.getElementById('update-progress-note');
    var finished = false;
    var timer = null;

    function setWidth(percent) {
        bar.style.width = percent + '%';
        bar.setAttribute('aria-valuenow', percent);
        percentEl.textContent = percent + '%';
    }

    function showPanel() {
        panel.hidden = false;
    }

    function renderRunning(progress) {
        showPanel();
        bar.classList.add('progress-bar-striped', 'progress-bar-animated');
        bar.classList.remove('bg-success', 'bg-danger');
        setWidth(Math.max(0, Math.min(100, parseInt(progress.percent, 10) || 0)));
        stepEl.textContent = progress.step || 'Updating…';
        noteEl.textContent = 'Do not close this page while the update is running.';
    }

    function renderUnavailable() {
        showPanel();
        bar.classList.add('progress-bar-striped', 'progress-bar-animated');
        bar.classList.remove('bg-success', 'bg-danger');
        stepEl.textContent = 'Server is applying the update';
        noteEl.textContent = 'The server is temporarily unavailable while files and migrations are updated. Retrying…';
    }

    function renderFailed(progress) {
        showPanel();
        finished = true;
        stopPolling();
        bar.classList.remove('progress-bar-animated');
        bar.classList.add('bg-danger');
        stepEl.textContent = progress.step || 'Update failed';
        noteEl.textContent = progress.message || 'The update failed and the previous version was restored.';
        enableButtons();
    }

    function renderCompleted(reload) {
        showPanel();
        finished = true;
        stopPolling();
        bar.classList.remove('progress-bar-animated', 'progress-bar-striped');
        bar.classList.add('bg-success');
        setWidth(100);
        stepEl.textContent = 'Update complete';
        noteEl.textContent = 'The new version is running.';

        if (reload) {
            noteEl.textContent = 'The new version is running. Reloading…';
            window.setTimeout(function () { window.location.reload(); }, 1500);
        }
    }

    function stopPolling() {
        if (timer !== null) {
            window.clearInterval(timer);
            timer = null;
        }
    }

    function applyState(data) {
        var progress = data.progress || {};

        if (progress.state === 'failed') {
            renderFailed(progress);
            return;
        }

        if (progress.state === 'completed') {
            renderCompleted(true);
            return;
        }

        if (data.applying || progress.state === 'running' || progress.state === 'starting') {
            renderRunning(progress);
            return;
        }

        stopPolling();
        panel.hidden = true;
        enableButtons();
    }

    function poll() {
        if (finished) {
            return;
        }

        fetch(statusUrl, {
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin',
            cache: 'no-store',
        })
        .then(function (response) {
            if (response.status === 503) {
                renderUnavailable();
                return null;
            }

            if (!response.ok) {
                throw new Error('status ' + response.status);
            }

            return response.json();
        })
        .then(function (data) {
            if (data) {
                applyState(data);
            }
        })
        .catch(function () {
            renderUnavailable();
        });
    }

    function startPolling() {
        showPanel();
        poll();
        if (timer === null) {
            timer = window.setInterval(poll, 2000);
        }
    }

    function disableButtons() {
        document.querySelectorAll('form[action*="app-update"] button').forEach(function (button) {
            button.disabled = true;
        });
    }

    function enableButtons() {
        document.querySelectorAll('form[action*="app-update"] button').forEach(function (button) {
            button.disabled = false;
        });
    }

    function pending(message) {
        showPanel();
        bar.classList.add('progress-bar-striped', 'progress-bar-animated');
        bar.classList.remove('bg-success', 'bg-danger');
        setWidth(4);
        percentEl.textContent = '';
        stepEl.textContent = message;
        noteEl.textContent = 'Please wait…';
        disableButtons();
    }

    var checkForm = document.querySelector('form[action$="/app-update/check"]');
    var applyForm = document.querySelector('form[action$="/app-update/apply"]');

    if (checkForm) {
        checkForm.addEventListener('submit', function (event) {
            event.preventDefault();
            pending('Downloading and verifying the update package…');

            fetch(checkForm.action, {
                method: 'POST',
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin',
                body: new FormData(checkForm),
            })
            .finally(function () {
                window.location.reload();
            });
        });
    }

    if (applyForm) {
        applyForm.addEventListener('submit', function (event) {
            event.preventDefault();
            pending('Starting the updater…');

            fetch(applyForm.action, {
                method: 'POST',
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin',
                body: new FormData(applyForm),
            })
            .then(function (response) {
                // A 422 means the request was rejected (nothing started), so
                // reload to show the validation error. Anything else — even a
                // 503 from an already-enabled maintenance mode — means the
                // updater is running and the status endpoint will catch up.
                if (response.status === 422) {
                    window.location.reload();
                    return;
                }

                startPolling();
            })
            .catch(function () {
                startPolling();
            });
        });
    }

    if (panel.getAttribute('data-applying') === '1'
        || ['starting', 'running', 'completed', 'failed'].indexOf(panel.getAttribute('data-state')) !== -1) {
        var initial = {
            state: panel.getAttribute('data-state'),
            percent: panel.getAttribute('data-percent'),
            step: panel.getAttribute('data-step'),
        };

        if (initial.state === 'completed') {
            renderCompleted(false);
        } else if (initial.state === 'failed') {
            renderFailed(initial);
        } else {
            renderRunning(initial);
            startPolling();
        }
    }
})();
</script>
@endpush

@push('scripts')
<script>
(function () {
    // Copy entity UUIDs from the operations table without leaving the page.
    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-copy]');
        if (!button) {
            return;
        }

        var value = button.getAttribute('data-copy') || '';
        var done = function () {
            button.textContent = 'Copied';
            window.setTimeout(function () { button.textContent = 'Copy'; }, 1500);
        };

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(value).then(done).catch(function () {
                button.textContent = 'Copy failed';
            });
        }
    });
})();
</script>
@endpush
