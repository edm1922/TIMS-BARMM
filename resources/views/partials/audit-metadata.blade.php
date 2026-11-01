@foreach ($metadata as $key => $value)
    <div class="d-flex flex-wrap gap-1">
        @include('partials.audit-metadata-entry', ['key' => $key, 'value' => $value, 'names' => $names ?? []])
    </div>
@endforeach
