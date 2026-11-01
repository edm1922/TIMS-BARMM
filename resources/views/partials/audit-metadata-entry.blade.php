@php
    $names = $names ?? [];
    $key = (string) $key;

    // A row id means nothing to an operator, so the beneficiary is named when
    // the record can still be found. Missing names keep the id visible.
    $named = false;

    if ($key === 'beneficiary_ids' && is_array($value) && $names !== []) {
        $value = array_map(function ($id) use ($names, &$named): string {
            $name = $names[(int) $id] ?? null;

            if ($name !== null) {
                $named = true;

                return $name;
            }

            return '#'.$id;
        }, $value);
    }

    $labels = [
        'beneficiary_ids' => $named ? 'Beneficiaries' : 'Beneficiary IDs',
        'beneficiary_batch_uuid' => 'Batch UUID',
        'deleted_beneficiary_count' => 'Deleted beneficiaries',
        'deleted_with_batch' => 'Deleted with batch',
        'source_import_batch_id' => 'Source import batch',
        'target_batch_id' => 'Target batch',
        'data_rows' => 'Data rows',
        'original_filename' => 'Original file name',
    ];

    $acronyms = [
        'id' => 'ID',
        'ids' => 'IDs',
        'uuid' => 'UUID',
        'uuids' => 'UUIDs',
        'ip' => 'IP',
        'url' => 'URL',
        'pg' => 'PG',
        'psgc' => 'PSGC',
        'cos' => 'COS',
    ];

    $label = $labels[$key] ?? \Illuminate\Support\Str::headline($key);

    $label = preg_replace_callback(
        '/\b\w+\b/',
        fn (array $match): string => $acronyms[strtolower($match[0])] ?? $match[0],
        $label,
    );
@endphp
<span class="text-muted text-nowrap">{{ $label }}:</span>
@include('partials.audit-metadata-value', ['value' => $value, 'names' => $names])
