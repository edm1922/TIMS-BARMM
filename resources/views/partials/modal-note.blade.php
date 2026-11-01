@php
    $variant = $variant ?? 'info';
    $title = $title ?? null;
@endphp
<div class="tims-note tims-note--{{ $variant }}" role="{{ $variant === 'danger' ? 'alert' : 'note' }}">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        @if ($variant === 'danger' || $variant === 'warning')
            <path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/>
            <path d="M12 9v4"/><path d="M12 17h.01"/>
        @elseif ($variant === 'success')
            <path d="M20 6 9 17l-5-5"/>
        @else
            <circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>
        @endif
    </svg>
    <div>
        @if ($title)
            <strong>{{ $title }}</strong>
        @endif
        {{ $slot ?? $message ?? '' }}
    </div>
</div>
