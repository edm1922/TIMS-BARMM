@php
    /**
     * Shared pre-registration duplicate check modal + helper script.
     *
     * @var bool $autoOpen
     */
    $autoOpen = $autoOpen ?? false;
@endphp
<div class="modal fade" id="duplicateCheckModal" tabindex="-1" aria-labelledby="duplicateCheckTitle" aria-hidden="true"
     data-bs-backdrop="static" data-bs-keyboard="false"
     data-auto-open="{{ $autoOpen ? '1' : '0' }}">
    <div class="modal-dialog tims-modal--lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <span class="tims-modal-icon" aria-hidden="true">
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>
                    </svg>
                </span>
                <div>
                    <h2 class="modal-title" id="duplicateCheckTitle">Verify beneficiary</h2>
                    <p class="tims-modal-subtitle">Confirm the identity before opening the registration form.</p>
                </div>
            </div>
            <div class="modal-body">
                <div class="tims-note mb-3">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>
                    </svg>
                    <div>
                        Enter the name and birthdate, then press <strong>Verify</strong>. With no matching record you
                        will go straight to the registration form; with a match, registration is blocked until the
                        record is reviewed.
                    </div>
                </div>
                <form id="duplicateCheckForm" data-endpoint="{{ route('beneficiaries.duplicate-check') }}">
                    @csrf
                    <div class="row g-2">
                        <div class="col-md-6">
                            <label class="form-label" for="dup_first_name">First name <span class="required">*</span></label>
                            <input type="text" class="form-control" id="dup_first_name"
                                   name="first_name" maxlength="100" required data-uppercase>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="dup_middle_name">Middle name</label>
                            <input type="text" class="form-control" id="dup_middle_name"
                                   name="middle_name" maxlength="100" data-uppercase>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="dup_last_name">Last name <span class="required">*</span></label>
                            <input type="text" class="form-control" id="dup_last_name"
                                   name="last_name" maxlength="100" required data-uppercase>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="dup_birthdate">Birthdate <span class="required">*</span></label>
                            <input type="date" class="form-control" id="dup_birthdate"
                                   name="birthdate" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="dup_extension_name">Extension</label>
                            <select class="form-select" id="dup_extension_name" name="extension_name">
                                <option value="">NONE</option>
                                @foreach (\App\Support\BeneficiaryOptions::extensions() as $value => $label)
                                    @if ($value !== \App\Support\BeneficiaryOptions::EXTENSION_NONE)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endif
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div id="duplicateCheckErrors" class="alert alert-danger mt-3 mb-0 py-2 small d-none"></div>
                </form>
                <div id="duplicateCheckAlert" class="alert mt-3 mb-0 py-2 small d-none"></div>
                <div id="duplicateCheckResults" class="mt-2"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" id="duplicateCheckCancel">Cancel</button>
                <button type="submit" form="duplicateCheckForm" class="btn btn-primary" id="duplicateCheckVerify">Verify</button>
            </div>
        </div>
    </div>
</div>
