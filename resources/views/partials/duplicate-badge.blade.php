@php
    $status = $status ?? 'none';
    $size = $size ?? '';
    $map = [
        'candidate' => ['text-bg-warning', 'Duplicate candidate'],
        'confirmed_duplicate' => ['text-bg-danger', 'Confirmed duplicate'],
        'cleared' => ['text-bg-info', 'Duplicate cleared'],
    ];
    $badge = $map[$status] ?? null;
@endphp

@if ($badge)
    <span class="badge {{ $badge[0] }} {{ $size }}">{{ $badge[1] }}</span>
@endif
