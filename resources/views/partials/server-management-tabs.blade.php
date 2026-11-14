@php
    $serverManagementPending = app(\App\Services\Sync\ReviewQueueService::class)->pendingCount();

    $serverManagementTabs = [
        [
            'label' => 'Servers',
            'url' => route('sync-servers.index'),
            'active' => request()->routeIs('sync-servers.*'),
        ],
        [
            'label' => 'Review Queue',
            'url' => route('review-inbox.index'),
            'active' => request()->routeIs('review-inbox.*'),
            'badge' => $serverManagementPending,
        ],
        [
            'label' => 'Upload History',
            'url' => route('sync-requests.index'),
            'active' => request()->routeIs('sync-requests.*'),
        ],
        [
            'label' => 'Conflicts & Mappings',
            'url' => route('sync-conflicts.index'),
            'active' => request()->routeIs('sync-conflicts.*'),
        ],
    ];
@endphp

<nav class="server-management-tabs d-flex flex-wrap gap-2 mb-3" aria-label="Server management sections">
    @foreach ($serverManagementTabs as $tab)
        <a class="sync-btn sync-btn--small {{ $tab['active'] ? 'sync-btn--primary' : 'sync-btn--secondary' }}"
           href="{{ $tab['url'] }}"
           @if ($tab['active']) aria-current="page" @endif>
            {{ $tab['label'] }}
            @if (($tab['badge'] ?? 0) > 0)
                <span class="badge {{ $tab['active'] ? 'text-bg-light border' : 'text-bg-warning' }} ms-1">{{ number_format($tab['badge']) }}</span>
            @endif
        </a>
    @endforeach
</nav>
