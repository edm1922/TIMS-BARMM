@php
    /**
     * Controlled dropdown that keeps a stored legacy value selectable.
     *
     * @var string $name
     * @var string $label
     * @var array<string,string> $options
     * @var string|null $selected
     * @var bool $required
     * @var bool $legacy
     */
    $selected = $selected ?? null;
    $required = $required ?? false;
    $legacy = $legacy ?? false;
    $id = $id ?? $name;
@endphp
<div class="{{ $col ?? 'col-md-4' }}">
    <label class="form-label" for="{{ $id }}">{{ $label }} @if ($required)<span class="text-danger">*</span>@endif</label>
    <select class="form-select @error($name) is-invalid @enderror" id="{{ $id }}" name="{{ $name }}"
            @if ($required) required @endif>
        <option value="">-- select --</option>
        @if ($legacy && $selected !== null && ! array_key_exists($selected, $options))
            <option value="{{ $selected }}" selected>{{ $selected }} (legacy)</option>
        @endif
        @foreach ($options as $value => $text)
            <option value="{{ $value }}" @selected($selected === $value)>{{ $text }}</option>
        @endforeach
    </select>
    @error($name)
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>
