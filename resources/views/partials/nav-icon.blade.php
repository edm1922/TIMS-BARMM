@php
    $paths = [
        'dashboard' => '<rect x="1.5" y="1.5" width="5.5" height="5.5" rx="1"/><rect x="9" y="1.5" width="5.5" height="5.5" rx="1"/><rect x="1.5" y="9" width="5.5" height="5.5" rx="1"/><rect x="9" y="9" width="5.5" height="5.5" rx="1"/>',
        'beneficiaries' => '<circle cx="8" cy="5" r="3"/><path d="M2 15c0-3.314 2.686-6 6-6s6 2.686 6 6H2z"/>',
        'batches' => '<path d="M1.5 3.5A1.5 1.5 0 0 1 3 2h3.086a1.5 1.5 0 0 1 1.06.44L8.207 3.5H13a1.5 1.5 0 0 1 1.5 1.5v7A1.5 1.5 0 0 1 13 13.5H3A1.5 1.5 0 0 1 1.5 12v-8.5z"/>',
        'imports' => '<path d="M8 1.5 11.5 5H9.5v4h-3V5H4L8 1.5z"/><path d="M2.5 11h2v1.5h7V11h2v3h-11v-3z"/>',
        'reports' => '<rect x="2" y="8" width="3" height="6"/><rect x="6.5" y="4" width="3" height="10"/><rect x="11" y="6.5" width="3" height="7.5"/>',
        'audit' => '<path d="M8 1 2.5 3.2v4.3c0 3.2 2.3 6.2 5.5 7.3 3.2-1.1 5.5-4.1 5.5-7.3V3.2L8 1z"/>',
        'users' => '<path d="M7 14s-1 0-1-1 1-4 5-4 5 3 5 4-1 1-1 1zm4-6a3 3 0 1 0 0-6 3 3 0 0 0 0 6m-5.784 6A2.24 2.24 0 0 1 5 13c0-1.355.68-2.75 1.936-3.72A6.3 6.3 0 0 0 5 9c-4 0-5 3-5 4s1 1 1 1zM4.5 8a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5"/>',
        'sync' => '<path d="M13.5 8a5.5 5.5 0 0 1-9.6 3.6l1.1-1.1A4 4 0 0 0 12 8h-1.75l2.25-3 2.25 3H13.5zM2.5 8a5.5 5.5 0 0 1 9.6-3.6L11 5.5A4 4 0 0 0 4 8h1.75L3.5 11 1.25 8H2.5z"/>',
        'logout' => '<path d="M2 3.5A1.5 1.5 0 0 1 3.5 2H8v1.5H3.5v9H8V14H3.5A1.5 1.5 0 0 1 2 12.5v-9z"/><path d="M9.5 4.25 8.44 5.31l2.19 2.19H6.5v1.5h4.13l-2.19 2.19L9.5 12.25 13.75 8l-4.25-4.25z"/>',
    ];

    $path = $paths[$name] ?? $paths['dashboard'];
@endphp
<svg class="app-nav-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" width="16" height="16"
     fill="currentColor" aria-hidden="true" focusable="false">{!! $path !!}</svg>
