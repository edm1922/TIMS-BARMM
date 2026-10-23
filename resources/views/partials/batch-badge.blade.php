@if (! empty($batch))
    <span class="badge text-bg-primary">{{ $batch->name }}</span>
@else
    <span class="badge text-bg-light border">Unassigned</span>
@endif
