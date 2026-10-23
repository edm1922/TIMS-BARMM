@php
    $status = $status ?? null;
    $size = $size ?? '';
    $map = [
        'adult' => ['text-bg-success', \App\Services\AgeService::label('adult')],
        'underage' => ['text-bg-danger', \App\Services\AgeService::label('underage')],
        'unknown' => ['text-bg-warning', \App\Services\AgeService::label('unknown')],
        'ineligible' => ['text-bg-danger', \App\Services\AgeService::label('ineligible')],
    ];
    $class = $map[$status][0] ?? 'text-bg-secondary';
    $label = $map[$status][1] ?? strtoupper((string) $status);
@endphp

@if ($status !== null && $status !== '')
    <span class="badge {{ $class }} {{ $size }}">{{ $label }}</span>
@endif
