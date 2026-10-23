<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') — {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('assets/css/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/css/app.css') }}">
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
                            {{ config('app.name') }}
                            <small>TUPAD Beneficiary LOB</small>
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
                                @if (Route::has('imports.create') && ! auth()->user()->isEncoder())
                                    <li class="nav-item">
                                        <a class="nav-link {{ request()->routeIs('imports.*') ? 'active' : '' }}"
                                           href="{{ route('imports.create') }}">
                                            @include('partials.nav-icon', ['name' => 'imports'])
                                            <span>Excel Import</span>
                                        </a>
                                    </li>
                                @endif
                                @if (Route::has('id-cards.index') && ! auth()->user()->isEncoder())
                                    <li class="nav-item">
                                        <a class="nav-link {{ request()->routeIs('id-cards.*') ? 'active' : '' }}"
                                           href="{{ route('id-cards.index') }}">
                                            @include('partials.nav-icon', ['name' => 'id-cards'])
                                            <span>ID Cards</span>
                                        </a>
                                    </li>
                                @endif
                                @if (Route::has('users.index') && auth()->user()->isAdmin())
                                    <li class="nav-item">
                                        <a class="nav-link {{ request()->routeIs('users.*') ? 'active' : '' }}"
                                           href="{{ route('users.index') }}">
                                            @include('partials.nav-icon', ['name' => 'users'])
                                            <span>Users</span>
                                        </a>
                                    </li>
                                @endif
                                @if (Route::has('reports.index'))
                                    <li class="nav-item">
                                        <a class="nav-link {{ request()->routeIs('reports.*') ? 'active' : '' }}"
                                           href="{{ route('reports.index') }}">
                                            @include('partials.nav-icon', ['name' => 'reports'])
                                            <span>Reports</span>
                                        </a>
                                    </li>
                                @endif
                                @if (Route::has('audit.index') && auth()->user()->isAdmin())
                                    <li class="nav-item">
                                        <a class="nav-link {{ request()->routeIs('audit.*') ? 'active' : '' }}"
                                           href="{{ route('audit.index') }}">
                                            @include('partials.nav-icon', ['name' => 'audit'])
                                            <span>Audit log</span>
                                        </a>
                                    </li>
                                @endif
                            </ul>
                        </nav>

                        <div class="app-sidebar-footer">
                            <div class="app-sidebar-user">
                                <div class="app-sidebar-user-name">{{ auth()->user()->name }}</div>
                                <span class="badge text-bg-secondary">{{ auth()->user()->role }}</span>
                            </div>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-outline-light w-100">
                                    @include('partials.nav-icon', ['name' => 'logout'])
                                    <span>Log out</span>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </aside>
        </div>

        <div class="app-content">
            <header class="app-topbar d-lg-none no-print">
                <button class="btn btn-outline-secondary btn-sm" type="button" data-bs-toggle="offcanvas"
                        data-bs-target="#appSidebar" aria-controls="appSidebar" aria-label="Open navigation">
                    <span class="navbar-toggler-icon"></span>
                </button>
                <span class="app-topbar-title">{{ config('app.name') }}</span>
                <span class="app-topbar-user ms-auto">{{ auth()->user()->name }}</span>
            </header>

            <main class="app-main container-fluid py-4 px-4">
                @include('partials.flash-messages')
                @yield('content')
            </main>
        </div>
    </div>
@else
    <main class="container-fluid py-4 px-4">
        @include('partials.flash-messages')
        @yield('content')
    </main>
@endauth

<script src="{{ asset('assets/js/bootstrap.bundle.min.js') }}"></script>
@stack('scripts')
</body>
</html>
