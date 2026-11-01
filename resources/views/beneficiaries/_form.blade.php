@php
    $beneficiary = $beneficiary ?? null;
    $old = old();
    $value = fn (string $field, $default = null) => $old[$field] ?? ($beneficiary?->{$field} ?? $default);
    $isLegacy = fn (string $field) => \App\Support\BeneficiaryOptions::isLegacy($field, $beneficiary?->{$field});
@endphp

{{-- Identity ------------------------------------------------------------ --}}
<section class="reg-section" id="reg-section-identity" data-reg-section="identity" aria-labelledby="reg-identity-title">
    <div class="reg-section-head">
        <span class="reg-section-icon" aria-hidden="true">
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                 stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21v-1a8 8 0 0 1 16 0v1"/></svg>
        </span>
        <div>
            <h2 class="reg-section-title" id="reg-identity-title">Identity information</h2>
            <p class="reg-section-subtitle">The beneficiary's legal name, birthdate and demographic details.</p>
        </div>
    </div>
    <div class="row g-3">
        <div class="col-md-3">
            <label class="form-label" for="first_name">First name <span class="required">*</span></label>
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
            <label class="form-label" for="last_name">Last name <span class="required">*</span></label>
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

        <div class="col-md-4">
            <label class="form-label" for="birthdate">Birthdate <span class="required">*</span></label>
            <input type="date" class="form-control @error('birthdate') is-invalid @enderror" id="birthdate" name="birthdate" data-birthdate
                   value="{{ $value('birthdate') ? \Illuminate\Support\Carbon::parse($value('birthdate'))->toDateString() : '' }}" required>
            <div class="form-text">Drives the computed age and the Adult / Underage / Unknown classification.</div>
            @error('birthdate')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-2">
            <label class="form-label" for="age_display">Age (computed)</label>
            <input type="text" class="form-control reg-computed" id="age_display" value="{{ $beneficiary?->computedAge() }}"
                   readonly tabindex="-1" data-age-display aria-readonly="true">
            <div class="form-text">Updates automatically.</div>
        </div>
        <div class="col-md-3">
            <label class="form-label" for="sex">Sex <span class="required">*</span></label>
            <select class="form-select @error('sex') is-invalid @enderror" id="sex" name="sex" required>
                <option value="" @selected(! $value('sex'))>--</option>
                <option value="MALE" @selected($value('sex') === 'MALE')>MALE</option>
                <option value="FEMALE" @selected($value('sex') === 'FEMALE')>FEMALE</option>
            </select>
            @error('sex')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-3">
            <label class="form-label" for="civil_status">Civil status <span class="required">*</span></label>
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
    </div>
</section>

{{-- Address ------------------------------------------------------------- --}}
<section class="reg-section" id="reg-section-address" data-reg-section="address" aria-labelledby="reg-address-title">
    <div class="reg-section-head">
        <span class="reg-section-icon" aria-hidden="true">
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                 stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 6-8 12-8 12S4 16 4 10a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
        </span>
        <div>
            <h2 class="reg-section-title" id="reg-address-title">Address information</h2>
            <p class="reg-section-subtitle">Choose a BARMM barangay; city / municipality and province fill in automatically.</p>
        </div>
    </div>
    <div class="row g-3">
        @include('beneficiaries.partials.address-field', ['beneficiary' => $beneficiary])
    </div>
</section>

{{-- Identification & contact -------------------------------------------- --}}
<section class="reg-section" id="reg-section-identification" data-reg-section="identification" aria-labelledby="reg-identification-title">
    <div class="reg-section-head">
        <span class="reg-section-icon" aria-hidden="true">
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                 stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M7 10h4"/><path d="M7 14h10"/></svg>
        </span>
        <div>
            <h2 class="reg-section-title" id="reg-identification-title">Identification &amp; contact</h2>
            <p class="reg-section-subtitle">Government-issued identity, COS reference and reachable contact number.</p>
        </div>
    </div>
    <div class="row g-3">
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
            <label class="form-label" for="id_type_other">ID type (specify) <span class="required">*</span></label>
            <input type="text" class="form-control @error('id_type_other') is-invalid @enderror" id="id_type_other"
                   name="id_type_other" value="{{ $value('id_type_other') }}" maxlength="100" data-uppercase>
            @error('id_type_other')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-3">
            <label class="form-label" for="source_id_no">Government ID No. <span class="required">*</span></label>
            <input type="text" class="form-control @error('source_id_no') is-invalid @enderror" id="source_id_no" name="source_id_no"
                   value="{{ $value('source_id_no') }}" maxlength="64" required>
            <div class="form-text">Format is applied automatically based on the selected ID type.</div>
            @error('source_id_no')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-3">
            <label class="form-label" for="cos_no">COS No.</label>
            <input type="text" class="form-control @error('cos_no') is-invalid @enderror" id="cos_no" name="cos_no"
                   value="{{ $value('cos_no') }}" maxlength="100" data-uppercase placeholder="e.g. COS-2026-001">
            @error('cos_no')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-3">
            <label class="form-label" for="contact_no">Contact number <span class="required">*</span></label>
            <input type="text" class="form-control @error('contact_no') is-invalid @enderror" id="contact_no" name="contact_no"
                   value="{{ $value('contact_no') }}" maxlength="20" inputmode="tel" autocomplete="tel"
                   placeholder="e.g. 0917 123 4567" data-ph-phone required>
            <div class="form-text" data-phone-hint aria-live="polite">{{ $errors->first('contact_no') ?: 'Format: 0917 123 4567, +63 917 123 4567, or (02) 8888 8888.' }}</div>
        </div>
    </div>
</section>

{{-- Employment & assistance --------------------------------------------- --}}
<section class="reg-section" id="reg-section-employment" data-reg-section="employment" aria-labelledby="reg-employment-title">
    <div class="reg-section-head">
        <span class="reg-section-icon" aria-hidden="true">
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                 stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/></svg>
        </span>
        <div>
            <h2 class="reg-section-title" id="reg-employment-title">Employment &amp; assistance</h2>
            <p class="reg-section-subtitle">TUPAD classification, occupation, payment details and dependents.</p>
        </div>
    </div>
    <div class="row g-3">
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
            <label class="form-label" for="occupation_other">Occupation (specify) <span class="required">*</span></label>
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
            <label class="form-label" for="avg_monthly_income">Average monthly income (₱) <span class="required">*</span></label>
            <input type="number" class="form-control @error('avg_monthly_income') is-invalid @enderror" id="avg_monthly_income" name="avg_monthly_income"
                   value="{{ $value('avg_monthly_income') }}" min="0" step="0.01" required>
            <div class="form-text">Amount in Philippine pesos.</div>
            @error('avg_monthly_income')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-4">
            <label class="form-label" for="dependent_name">Dependent name(s) <span class="required">*</span></label>
            <input type="text" class="form-control @error('dependent_name') is-invalid @enderror" id="dependent_name" name="dependent_name"
                   value="{{ $value('dependent_name') }}" maxlength="150" data-uppercase required>
            @error('dependent_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-4">
            <label class="form-label" for="dependent_contact_no">Dependent's contact number <span class="required">*</span></label>
            <input type="text" class="form-control @error('dependent_contact_no') is-invalid @enderror" id="dependent_contact_no" name="dependent_contact_no"
                   value="{{ $value('dependent_contact_no') }}" maxlength="20" inputmode="tel" autocomplete="tel"
                   placeholder="e.g. 0917 123 4567" data-ph-phone required>
            <div class="form-text" data-phone-hint aria-live="polite">{{ $errors->first('dependent_contact_no') ?: 'Format: 0917 123 4567, +63 917 123 4567, or (02) 8888 8888.' }}</div>
        </div>
    </div>
</section>

{{-- Additional information ---------------------------------------------- --}}
<section class="reg-section" id="reg-section-additional" data-reg-section="additional" aria-labelledby="reg-additional-title">
    <div class="reg-section-head">
        <span class="reg-section-icon" aria-hidden="true">
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                 stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
        </span>
        <div>
            <h2 class="reg-section-title" id="reg-additional-title">Additional information</h2>
            <p class="reg-section-subtitle">Optional employment interest and training notes used for planning.</p>
        </div>
    </div>
    <div class="row g-3">
        <div class="col-md-4">
            <label class="form-label" for="employment_interest">Employment interest</label>
            @php($employmentInterest = strtoupper((string) $value('employment_interest')))
            <select class="form-select @error('employment_interest') is-invalid @enderror" id="employment_interest" name="employment_interest">
                <option value="">— Select —</option>
                <option value="YES" @selected($employmentInterest === 'YES')>YES</option>
                <option value="NO" @selected($employmentInterest === 'NO')>NO</option>
            </select>
            @error('employment_interest')<div class="invalid-feedback">{{ $message }}</div>@enderror
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
</section>

@push('scripts')
<script>window.TIMS_ID_MASKS = @json(\App\Support\BeneficiaryOptions::idNumberMasks());</script>
<script src="{{ asset('assets/js/beneficiary-form.js') }}?v={{ filemtime(public_path('assets/js/beneficiary-form.js')) }}"></script>
@endpush
