@extends('layouts.app')

@section('title', 'Reports unavailable')

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card border-danger">
            <div class="card-body">
                <h1 class="h4 mb-3">The reports page could not be loaded</h1>

                <p class="mb-3">{{ $message }}</p>

                @if (! empty($missing))
                    <div class="alert alert-warning">
                        <div class="fw-semibold mb-1">This server's database is missing:</div>
                        <ul class="mb-0">
                            @foreach ($missing as $item)
                                <li><code>{{ $item }}</code></li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if (! empty($pendingMigrations))
                    <div class="alert alert-warning">
                        <div class="fw-semibold mb-1">Database updates that have not been applied on this server:</div>
                        <ul class="mb-2">
                            @foreach (array_slice($pendingMigrations, 0, 10) as $migration)
                                <li><code>{{ $migration }}</code></li>
                            @endforeach
                        </ul>
                        @if (count($pendingMigrations) > 10)
                            <div class="small mb-0">and {{ count($pendingMigrations) - 10 }} more.</div>
                        @endif
                    </div>
                @endif

                <p class="mb-2">Apply the pending database updates on this server, then reload this page:</p>
                <pre class="bg-light border rounded p-3 mb-3"><code>php artisan migrate --force
php artisan config:clear
php artisan view:clear</code></pre>
                <p class="small text-muted mb-0">
                    The details of this failure were recorded in the audit trail and will reach the central server
                    with the next synchronization.
                </p>
            </div>
        </div>
    </div>
</div>
@endsection
