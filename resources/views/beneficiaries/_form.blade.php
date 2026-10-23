@php
    $beneficiary = $beneficiary ?? null;
    $old = old();
    $value = fn (string $field, $default = null) => $old[$field] ?? ($beneficiary?->{$field} ?? $default);
    $isLegacy = fn (string $field) => \App\Support\BeneficiaryOptions::isLegacy($field, $beneficiary?->{$field});
@endphp

<div class="row g-3">
    <div class="col-12">
        <h2 class="h6 text-uppercase text-muted">Identity</h2>
    </div>
    <div class="col-md-3">
        <label class="form-label" for="first_name">First name <span class="text-danger">*</span></label>
        <input type="text" class="form-control @error('first_name') is-invalid @enderror" id="first_name" name="first_name"
               value="{{ $value('first_name') }}" required maxlength="100" data-uppercase>
        @error('first_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-3">
        <label class="form-label" for="middle_name">Middle name</label>
        <input type="text" class="form-control" id="middle_name" name="middle_name"
               value="{{ $value('middle_name') }}" maxlength="100" data-uppercase>
    </div>
    <div class="col-md-3">
        <label class="form-label" for="last_name">Last name <span class="text-danger">*</span></label>
        <input type="text" class="form-control @error('last_name') is-invalid @enderror" id="last_name" name="last_name"
               value="{{ $value('last_name') }}" required maxlength="100" data-uppercase>
        @error('last_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-3">
        <label class="form-label" for="extension_name">Extension</label>
        <select class="form-select @error('extension_name') is-invalid @enderror" id="extension_name" name="extension_name">
            <option value="">NONE</option>
            @foreach (\App\Support\BeneficiaryOptions::extensions() as $extValue => $extLabel)
                @if ($extValue !== \App\Support\BeneficiaryOptions::EXTENSION_NONE)
                    <option value="{{ $extValue }}" @selected($value('extension_name') === $extValue)>{{ $extLabel }}</option>
                @endif
            @endforeach
            @if ($value('extension_name') && $isLegacy('extension_name'))
                <option value="{{ $value('extension_name') }}" selected>{{ $value('extension_name') }} (legacy)</option>
            @endif
        </select>
        @error('extension_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-md-3">
        <label class="form-label" for="birthdate">Birthdate <span class="text-danger">*</span></label>
        <input type="date" class="form-control @error('birthdate') is-invalid @enderror" id="birthdate" name="birthdate" data-birthdate
               value="{{ $value('birthdate') ? \Illuminate\Support\Carbon::parse($value('birthdate'))->toDateString() : '' }}" required>
        <div class="form-text">Age status (Adult / Underage / Unknown) is computed from this field.</div>
        @error('birthdate')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-2">
        <label class="form-label" for="age_display">Age (computed)</label>
        <input type="text" class="form-control" id="age_display" value="{{ $beneficiary?->computedAge() }}" readonly tabindex="-1"
               data-age-display>
    </div>
    <div class="col-md-2">
        <label class="form-label" for="sex">Sex <span class="text-danger">*</span></label>
        <select class="form-select @error('sex') is-invalid @enderror" id="sex" name="sex" required>
            <option value="" @selected(! $value('sex'))>--</option>
            <option value="MALE" @selected($value('sex') === 'MALE')>MALE</option>
            <option value="FEMALE" @selected($value('sex') === 'FEMALE')>FEMALE</option>
        </select>
        @error('sex')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-2">
        <label class="form-label" for="civil_status">Civil status <span class="text-danger">*</span></label>
        <select class="form-select @error('civil_status') is-invalid @enderror" id="civil_status" name="civil_status" required>
            <option value="">--</option>
            @foreach (['SINGLE', 'MARRIED', 'WIDOW', 'WIDOWED', 'SEPARATED', 'DIVORCED'] as $statusOption)
                <option value="{{ $statusOption }}" @selected($value('civil_status') === $statusOption)>{{ $statusOption }}</option>
            @endforeach
            @if ($value('civil_status') && ! in_array($value('civil_status'), ['SINGLE', 'MARRIED', 'WIDOW', 'WIDOWED', 'SEPARATED', 'DIVORCED'], true))
                <option value="{{ $value('civil_status') }}" selected>{{ $value('civil_status') }} (legacy)</option>
            @endif
        </select>
        @error('civil_status')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-12">
        <h2 class="h6 text-uppercase text-muted mt-2">Address</h2>
    </div>
    @include('beneficiaries.partials.address-field', ['beneficiary' => $beneficiary])

    <div class="col-12">
        <h2 class="h6 text-uppercase text-muted mt-2">Identification &amp; contact</h2>
    </div>
    @include('beneficiaries.partials.options-select', [
        'name' => 'id_type',
        'label' => 'ID type',
        'options' => \App\Support\BeneficiaryOptions::idTypes(),
        'selected' => $value('id_type'),
        'required' => true,
        'legacy' => $isLegacy('id_type'),
        'col' => 'col-md-3',
    ])
    <div class="col-md-3 @if (strcasecmp((string) $value('id_type'), \App\Support\BeneficiaryOptions::ID_TYPE_OTHER) !== 0) d-none @endif" data-other-for="id_type">
        <label class="form-label" for="id_type_other">ID type (specify) <span class="text-danger">*</span></label>
        <input type="text" class="form-control @error('id_type_other') is-invalid @enderror" id="id_type_other"
               name="id_type_other" value="{{ $value('id_type_other') }}" maxlength="100" data-uppercase>
        @error('id_type_other')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-3">
        <label class="form-label" for="source_id_no">Government ID No. <span class="text-danger">*</span></label>
        <input type="text" class="form-control @error('source_id_no') is-invalid @enderror" id="source_id_no" name="source_id_no"
               value="{{ $value('source_id_no') }}" maxlength="64" required>
        @error('source_id_no')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-3">
        <label class="form-label" for="cos_no">COS No.</label>
        <input type="text" class="form-control @error('cos_no') is-invalid @enderror" id="cos_no" name="cos_no"
               value="{{ $value('cos_no') }}" maxlength="100" data-uppercase placeholder="e.g. COS-2026-001">
        @error('cos_no')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-3">
        <label class="form-label" for="contact_no">Contact number <span class="text-danger">*</span></label>
        <input type="text" class="form-control @error('contact_no') is-invalid @enderror" id="contact_no" name="contact_no"
               value="{{ $value('contact_no') }}" maxlength="20" required>
        @error('contact_no')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-12">
        <h2 class="h6 text-uppercase text-muted mt-2">Employment &amp; assistance</h2>
    </div>
    @include('beneficiaries.partials.options-select', [
        'name' => 'beneficiary_type',
        'label' => 'Beneficiary type',
        'options' => \App\Support\BeneficiaryOptions::beneficiaryTypes(),
        'selected' => $value('beneficiary_type'),
        'required' => true,
        'legacy' => $isLegacy('beneficiary_type'),
        'col' => 'col-md-6',
    ])
    @include('beneficiaries.partials.options-select', [
        'name' => 'beneficiary_signify',
        'label' => 'Beneficiary signify',
        'options' => \App\Support\BeneficiaryOptions::benefifySignify(),
        'selected' => $value('beneficiary_signify'),
        'required' => true,
        'legacy' => $isLegacy('beneficiary_signify'),
        'col' => 'col-md-6',
    ])
    @include('beneficiaries.partials.options-select', [
        'name' => 'occupation',
        'label' => 'Occupation',
        'options' => \App\Support\BeneficiaryOptions::occupations(),
        'selected' => $value('occupation'),
        'required' => true,
        'legacy' => $isLegacy('occupation'),
        'col' => 'col-md-4',
    ])
    <div class="col-md-4 @if (strcasecmp((string) $value('occupation'), \App\Support\BeneficiaryOptions::OCCUPATION_OTHER) !== 0) d-none @endif" data-other-for="occupation">
        <label class="form-label" for="occupation_other">Occupation (specify) <span class="text-danger">*</span></label>
        <input type="text" class="form-control @error('occupation_other') is-invalid @enderror" id="occupation_other"
               name="occupation_other" value="{{ $value('occupation_other') }}" maxlength="150" data-uppercase>
        @error('occupation_other')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    @include('beneficiaries.partials.options-select', [
        'name' => 'mode_of_payment',
        'label' => 'Mode of payment',
        'options' => \App\Support\BeneficiaryOptions::modesOfPayment(),
        'selected' => $value('mode_of_payment'),
        'required' => true,
        'legacy' => $isLegacy('mode_of_payment'),
        'col' => 'col-md-4',
    ])
    <div class="col-md-4">
        <label class="form-label" for="avg_monthly_income">Average monthly income (₱) <span class="text-danger">*</span></label>
        <input type="number" class="form-control @error('avg_monthly_income') is-invalid @enderror" id="avg_monthly_income" name="avg_monthly_income"
               value="{{ $value('avg_monthly_income') }}" min="0" step="0.01" required>
        @error('avg_monthly_income')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-4">
        <label class="form-label" for="dependent_name">Dependent name(s) <span class="text-danger">*</span></label>
        <input type="text" class="form-control @error('dependent_name') is-invalid @enderror" id="dependent_name" name="dependent_name"
               value="{{ $value('dependent_name') }}" maxlength="150" data-uppercase required>
        @error('dependent_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-4">
        <label class="form-label" for="dependent_contact_no">Dependent's contact number <span class="text-danger">*</span></label>
        <input type="text" class="form-control @error('dependent_contact_no') is-invalid @enderror" id="dependent_contact_no" name="dependent_contact_no"
               value="{{ $value('dependent_contact_no') }}" maxlength="20" required>
        @error('dependent_contact_no')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-4">
        <label class="form-label" for="skills_training_needed">Skills / training needed</label>
        <input type="text" class="form-control @error('skills_training_needed') is-invalid @enderror" id="skills_training_needed" name="skills_training_needed"
               value="{{ $value('skills_training_needed') }}" maxlength="150" data-uppercase>
        @error('skills_training_needed')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    @if ($beneficiary && $beneficiary->age_source !== null)
        <div class="col-md-4">
            <label class="form-label" for="age_source_display">Age (source workbook)</label>
            <input type="text" class="form-control" id="age_source_display" value="{{ $beneficiary->age_source }}" readonly tabindex="-1">
            <div class="form-text">Recorded from the source file; never used for status.</div>
        </div>
    @endif
</div>

@push('scripts')
<script src="{{ asset('assets/js/beneficiary-form.js') }}"></script>
@endpush
