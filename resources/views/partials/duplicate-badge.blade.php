@php
    /**
     * Duplicate flag badge.
     *
     * A candidate shows the reason it was flagged (the detector rule) so the
     * operator can see why at a glance. Resolved flags show their state:
     * a confirmed duplicate stays red, a cleared one turns green.
     *
     * @var string|null $status
     * @var string|null $rule
     * @var string $size
     */
    $status = $status ?? 'none';
    $size = $size ?? '';
    $rule = $rule ?? null;
    $ruleLabel = is_string($rule) && $rule !== '' ? \App\Services\DuplicateDetector::ruleLabel($rule) : null;
    $map = [
        'candidate' => ['text-bg-warning', $ruleLabel ?? 'Duplicate candidate'],
        'confirmed_duplicate' => ['text-bg-danger', 'Confirmed duplicate'],
        'cleared' => ['text-bg-success', 'Cleared duplication'],
    ];
    $badge = $map[$status] ?? null;
    $title = $status === 'candidate' || $ruleLabel === null
        ? null
        : 'Flagged because: '.$ruleLabel;
@endphp

@if ($badge)
    <span class="badge {{ $badge[0] }} {{ $size }}" @if ($title) title="{{ $title }}" @endif>{{ $badge[1] }}</span>
@endif
