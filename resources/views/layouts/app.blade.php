<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <script>
        (function () {
            try {
                var stored = window.localStorage.getItem('tims-theme');
                var theme = stored === 'dark' || stored === 'light'
                    ? stored
                    : (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
                document.documentElement.setAttribute('data-bs-theme', theme);

                if (window.localStorage.getItem('tims-sidebar') === 'collapsed') {
                    document.documentElement.setAttribute('data-sidebar', 'collapsed');
                }
            } catch (error) {
                document.documentElement.setAttribute('data-bs-theme', 'light');
            }
        })();
    </script>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') — {{ config('app.name') }}</title>
    @php
        $faviconFile = public_path('favicon.ico');
        $faviconPng = public_path('favicon.png');
        $favicon32 = public_path('favicon-32x32.png');
        $favicon16 = public_path('favicon-16x16.png');
        $touchIcon = public_path('apple-touch-icon.png');
    @endphp
    <link rel="icon" href="{{ asset('favicon.ico') }}?v={{ filemtime($faviconFile) }}" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32"
          href="{{ asset('favicon-32x32.png') }}?v={{ filemtime($favicon32) }}">
    <link rel="icon" type="image/png" sizes="16x16"
          href="{{ asset('favicon-16x16.png') }}?v={{ filemtime($favicon16) }}">
    <link rel="icon" type="image/png" sizes="64x64"
          href="{{ asset('favicon.png') }}?v={{ filemtime($faviconPng) }}">
    <link rel="apple-touch-icon" sizes="180x180"
          href="{{ asset('apple-touch-icon.png') }}?v={{ filemtime($touchIcon) }}">
    <meta name="theme-color" content="#172337">
    <link rel="stylesheet" href="{{ asset('assets/css/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/css/app.css') }}?v={{ filemtime(public_path('assets/css/app.css')) }}">
    @include('partials.modal-system')
    @include('partials.theme-styles')
    @auth
    <style>
        /* Critical sidebar brand rules. Kept inline so a stale/cached
           app.css on an offline installation cannot blow up the logo. */
        .app-sidebar-brand {
            display: flex;
            flex-direction: row;
            align-items: center;
            gap: .75rem;
        }
        .app-sidebar-brand-logo {
            width: 36px;
            height: 36px;
            max-width: 36px;
            max-height: 36px;
            object-fit: contain;
            flex: 0 0 auto;
        }
        html[data-sidebar="collapsed"] .app-sidebar-brand-logo {
            width: 30px;
            height: 30px;
            max-width: 30px;
            max-height: 30px;
        }
        @media (max-width: 991.98px) {
            .app-sidebar-brand-logo {
                width: 36px;
                height: 36px;
                max-width: 36px;
                max-height: 36px;
            }
        }
        .app-sidebar-brand-copy {
            display: flex;
            flex-direction: column;
            gap: .2rem;
            min-width: 0;
            overflow: hidden;
        }
        .app-sidebar-brand-copy > :first-child {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .app-topbar-logo {
            width: 28px;
            height: 28px;
            max-width: 28px;
            max-height: 28px;
            object-fit: contain;
            flex: 0 0 auto;
        }
    </style>
    @endauth
</head>
<body class="bg-body-tertiary">
@auth
    <div class="app-shell">
        <div class="navbar-expand-lg app-sidebar-wrapper">
            <aside class="app-sidebar offcanvas-lg offcanvas-start" tabindex="-1" id="appSidebar"
                   aria-labelledby="appSidebarLabel">
                <div class="offcanvas-header app-sidebar-header">
                    <h2 class="offcanvas-title h6 mb-0" id="appSidebarLabel">Navigation</h2>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas"
                            data-bs-target="#appSidebar" aria-label="Close"></button>
                </div>
                <div class="offcanvas-body">
                    <div class="app-sidebar-inner">
                        <a class="app-sidebar-brand" href="{{ Route::has('dashboard') ? route('dashboard') : '#' }}">
                            <img src="{{ asset('images/RGB-DOLE-LOGO-WITH-BLACK-WORDMARK-PNG.png') }}"
                                 alt="Department of Labor and Employment"
                                 class="app-sidebar-brand-logo" width="569" height="600" decoding="async">
                            <span class="app-sidebar-brand-copy">
                                {{ config('app.name') }}
                                <small class="app-sidebar-brand-subtitle">DOLE 12</small>
                            </span>
                        </a>

                        <nav class="app-nav" aria-label="Main navigation">
                            <ul class="nav flex-column">
                                <li class="nav-item">
                                    <a class="nav-link {{ request()->routeIs('dashboard') ? 'active' : '' }}"
                                       href="{{ route('dashboard') }}">
                                        @include('partials.nav-icon', ['name' => 'dashboard'])
                                        <span>Dashboard</span>
                                    </a>
                                </li>
                                @if (Route::has('beneficiaries.index'))
                                    <li class="nav-item">
                                        <a class="nav-link {{ request()->routeIs('beneficiaries.*') ? 'active' : '' }}"
                                           href="{{ route('beneficiaries.index') }}">
                                            @include('partials.nav-icon', ['name' => 'beneficiaries'])
                                            <span>Beneficiaries</span>
                                        </a>
                                    </li>
                                @endif
                                @if (Route::has('beneficiary-batches.index'))
                                    <li class="nav-item">
                                        <a class="nav-link {{ request()->routeIs('beneficiary-batches.*') ? 'active' : '' }}"
                                           href="{{ route('beneficiary-batches.index') }}">
                                            @include('partials.nav-icon', ['name' => 'batches'])
                                            <span>Batches</span>
                                        </a>
                                    </li>
                                @endif
                                @if (Route::has('imports.create') && auth()->user()->canAccessImports())
                                    <li class="nav-item">
                                        <a class="nav-link {{ request()->routeIs('imports.*') ? 'active' : '' }}"
                                           href="{{ route('imports.create') }}">
                                            @include('partials.nav-icon', ['name' => 'imports'])
                                            <span>Excel Import</span>
                                        </a>
                                    </li>
                                @endif
                                {{-- Central-only: the user registry is a central account-management
                                     feature. Offline installations show their accounts through User
                                     Monitoring on the central per-server page instead. --}}
                                @if (Route::has('users.index') && auth()->user()->isAdmin() && \App\Support\SyncIdentity::isOnline())
                                    <li class="nav-item">
                                        <a class="nav-link {{ request()->routeIs('users.*') ? 'active' : '' }}"
                                           href="{{ route('users.index') }}">
                                            @include('partials.nav-icon', ['name' => 'users'])
                                            <span>Users</span>
                                        </a>
                                    </li>
                                @endif
                                @if (Route::has('enroll-server.index') && auth()->user()->isAdmin())
                                    <li class="nav-item">
                                        <a class="nav-link {{ request()->routeIs('enroll-server.*') ? 'active' : '' }}"
                                           href="{{ route('enroll-server.index') }}">
                                            @include('partials.nav-icon', ['name' => 'sync'])
                                            <span>Enroll Server</span>
                                        </a>
                                    </li>
                                @endif
                                @if (Route::has('sync.local.index') && auth()->user()->isManager())
                                    <li class="nav-item">
                                        <a class="nav-link {{ request()->routeIs('sync.local.*') ? 'active' : '' }}"
                                           href="{{ route('sync.local.index') }}">
                                            @include('partials.nav-icon', ['name' => 'sync'])
                                            <span>Enroll Server</span>
                                        </a>
                                    </li>
                                @endif
                                @if (Route::has('sync-servers.index') && auth()->user()->isAdmin())
                                    <li class="nav-item">
                                        <a class="nav-link {{ request()->routeIs('sync-servers.*') ? 'active' : '' }}"
                                           href="{{ route('sync-servers.index') }}">
                                            @include('partials.nav-icon', ['name' => 'sync'])
                                            <span>Offline Servers</span>
                                        </a>
                                    </li>
                                @endif
                                @if (Route::has('review-inbox.index') && auth()->user()->isAdmin())
                                    @php
                                        // The inbox badge mirrors the inbox "Waiting" total:
                                        // every open conflict plus every held upload item that
                                        // is not already represented by an open conflict.
                                        $openConflictUuids = \App\Models\SyncConflict::query()
                                            ->where('status', 'open')
                                            ->pluck('conflict_uuid');
                                        $reviewInboxCount = $openConflictUuids->count() + \App\Models\SyncRequestItem::query()
                                            ->where('status', 'awaiting_review')
                                            ->where(function ($query) use ($openConflictUuids): void {
                                                $query->whereNull('conflict_uuid');
                                                if ($openConflictUuids->isNotEmpty()) {
                                                    $query->orWhereNotIn('conflict_uuid', $openConflictUuids);
                                                }
                                            })
                                            ->count();
                                    @endphp
                                    <li class="nav-item">
                                        <a class="nav-link {{ request()->routeIs('review-inbox.*') ? 'active' : '' }}"
                                           href="{{ route('review-inbox.index') }}">
                                            @include('partials.nav-icon', ['name' => 'sync'])
                                            <span>Review inbox</span>
                                            @if ($reviewInboxCount > 0)
                                                <span class="badge text-bg-warning ms-1">{{ $reviewInboxCount }}</span>
                                            @endif
                                        </a>
                                    </li>
                                @endif
                                @if (Route::has('reports.index') && auth()->user()->canAccessReports())
                                    <li class="nav-item">
                                        <a class="nav-link {{ request()->routeIs('reports.*') ? 'active' : '' }}"
                                           href="{{ route('reports.index') }}">
                                            @include('partials.nav-icon', ['name' => 'reports'])
                                            <span>Reports</span>
                                        </a>
                                    </li>
                                @endif
                                @if (Route::has('audit.index') && auth()->user()->canAccessAudit())
                                    <li class="nav-item">
                                        <a class="nav-link {{ request()->routeIs('audit.*') ? 'active' : '' }}"
                                           href="{{ route('audit.index') }}">
                                            @include('partials.nav-icon', ['name' => 'audit'])
                                            <span>Audit log</span>
                                        </a>
                                    </li>
                                @endif
                                @if (Route::has('encoders.index') && auth()->user()->isManager())
                                    <li class="nav-item">
                                        <a class="nav-link {{ request()->routeIs('encoders.*') ? 'active' : '' }}"
                                           href="{{ route('encoders.index') }}">
                                            @include('partials.nav-icon', ['name' => 'users'])
                                            <span>Encoders</span>
                                        </a>
                                    </li>
                                @endif
                            </ul>
                        </nav>

                        <div class="app-sidebar-footer">
                            <button type="button" class="theme-toggle" data-theme-toggle aria-pressed="false"
                                    aria-label="Switch to dark theme" title="Switch to dark theme">
                                <svg class="theme-icon-sun" width="15" height="15" viewBox="0 0 24 24" fill="none"
                                     stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/>
                                    <path d="m4.93 4.93 1.41 1.41"/><path d="m17.66 17.66 1.41 1.41"/>
                                    <path d="M2 12h2"/><path d="M20 12h2"/><path d="m6.34 17.66-1.41 1.41"/>
                                    <path d="m19.07 4.93-1.41 1.41"/>
                                </svg>
                                <svg class="theme-icon-moon" width="15" height="15" viewBox="0 0 24 24" fill="none"
                                     stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79Z"/>
                                </svg>
                                <span data-theme-label>Dark mode</span>
                            </button>
                            <div class="app-sidebar-user">
                                <div class="app-sidebar-avatar" aria-hidden="true">
                                    {{ mb_strtoupper(mb_substr(trim(auth()->user()->name), 0, 1)) }}
                                </div>
                                <div class="app-sidebar-user-copy">
                                    <div class="app-sidebar-user-name">{{ auth()->user()->name }}</div>
                                    <span class="badge text-bg-secondary">{{ \App\Models\User::ROLE_LABELS[auth()->user()->role] ?? auth()->user()->role }}</span>
                                </div>
                            </div>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-outline-light w-100"
                                        aria-label="Log out" title="Log out">
                                    @include('partials.nav-icon', ['name' => 'logout'])
                                    <span>Log out</span>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </aside>
            <button type="button" class="app-sidebar-edge-toggle" id="sidebarToggle"
                    aria-label="Collapse sidebar" aria-expanded="true" aria-controls="appSidebar"
                    title="Collapse sidebar">
                <svg class="app-sidebar-edge-toggle-icon" width="14" height="14" viewBox="0 0 24 24"
                     fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                     stroke-linejoin="round" aria-hidden="true">
                    <path d="m11 17-5-5 5-5"/><path d="m18 17-5-5 5-5"/>
                </svg>
                <span class="visually-hidden" data-sidebar-label>Collapse sidebar</span>
            </button>
        </div>

        <div class="app-content">
            <header class="app-topbar d-lg-none no-print">
                <button class="btn btn-outline-secondary btn-sm" type="button" data-bs-toggle="offcanvas"
                        data-bs-target="#appSidebar" aria-controls="appSidebar" aria-label="Open navigation">
                    <span class="navbar-toggler-icon"></span>
                </button>
                <img src="{{ asset('images/RGB-DOLE-LOGO-WITH-BLACK-WORDMARK-PNG.png') }}"
                     alt="Department of Labor and Employment"
                     class="app-topbar-logo" width="569" height="600" decoding="async">
                <span class="app-topbar-title">{{ config('app.name') }}</span>
                <span class="app-topbar-user ms-auto">{{ auth()->user()->name }}</span>
                <button type="button" class="theme-toggle theme-toggle--compact" data-theme-toggle aria-pressed="false"
                        aria-label="Switch to dark theme" title="Switch to dark theme">
                    <svg class="theme-icon-sun" width="15" height="15" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/>
                        <path d="m4.93 4.93 1.41 1.41"/><path d="m17.66 17.66 1.41 1.41"/>
                        <path d="M2 12h2"/><path d="M20 12h2"/><path d="m6.34 17.66-1.41 1.41"/>
                        <path d="m19.07 4.93-1.41 1.41"/>
                    </svg>
                    <svg class="theme-icon-moon" width="15" height="15" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79Z"/>
                    </svg>
                    <span class="visually-hidden" data-theme-label>Dark mode</span>
                </button>
            </header>

            <main class="app-main container-fluid py-4 px-4">
                @include('partials.flash-messages')
                @yield('content')
            </main>
        </div>
    </div>
@else
    <main class="container-fluid py-4 px-4">
        @unless (request()->routeIs('login'))
            @include('partials.flash-messages')
        @endunless
        @yield('content')
    </main>
@endauth

<script src="{{ asset('assets/js/bootstrap.bundle.min.js') }}"></script>
@include('partials.modal-scripts')
@include('partials.theme-scripts')
@include('partials.sidebar-scripts')
@stack('scripts')
</body>
</html>