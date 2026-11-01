@if (is_bool($value))
    <span class="text-body">{{ $value ? 'Yes' : 'No' }}</span>
@elseif ($value === null || $value === '')
    <span class="text-muted">—</span>
@elseif (is_array($value))
    @if ($value === [])
        <span class="text-muted">—</span>
    @elseif (array_is_list($value))
        @if (count($value) > 8)
            <details>
                <summary class="text-body">{{ count($value) }} items</summary>
                <div class="mt-1">
                    @foreach ($value as $item)
                        <div class="text-body text-break">
                            @include('partials.audit-metadata-value', ['value' => $item, 'names' => $names ?? []])
                        </div>
                    @endforeach
                </div>
            </details>
        @else
            <span class="text-body text-break">
                @foreach ($value as $item)
                    @if (! $loop->first)<span class="text-muted">, </span>@endif
                    @include('partials.audit-metadata-value', ['value' => $item, 'names' => $names ?? []])
                @endforeach
            </span>
        @endif
    @else
        <div class="ps-2 border-start">
            @foreach ($value as $childKey => $childValue)
                <div class="d-flex flex-wrap gap-1">
                    @include('partials.audit-metadata-entry', ['key' => $childKey, 'value' => $childValue, 'names' => $names ?? []])
                </div>
            @endforeach
        </div>
    @endif
@else
    <span class="text-body text-break">{{ $value }}</span>
@endif
