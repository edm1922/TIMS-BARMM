@php
    /**
     * Shared pre-registration duplicate check modal + helper script.
     *
     * @var bool $autoOpen
     */
    $autoOpen = $autoOpen ?? false;
@endphp
<div class="modal fade" id="duplicateCheckModal" tabindex="-1" aria-labelledby="duplicateCheckTitle" aria-hidden="true"
     data-auto-open="{{ $autoOpen ? '1' : '0' }}">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="duplicateCheckTitle">Verify beneficiary</h5>
            </div>
            <div class="modal-body">
                <p class="small text-muted">
                    Enter the name and birthdate, then press <strong>Verify</strong>. If no matching record exists you
                    will be taken straight to the registration form. If a matching record is found, registration is
                    blocked until the record is reviewed.
                </p>
                <form id="duplicateCheckForm" data-endpoint="{{ route('beneficiaries.duplicate-check') }}">
                    @csrf
                    <div class="row g-2">
                        <div class="col-md-6">
                            <label class="form-label small mb-1" for="dup_first_name">First name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-sm" id="dup_first_name"
                                   name="first_name" maxlength="100" required data-uppercase>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small mb-1" for="dup_middle_name">Middle name</label>
                            <input type="text" class="form-control form-control-sm" id="dup_middle_name"
                                   name="middle_name" maxlength="100" data-uppercase>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small mb-1" for="dup_last_name">Last name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-sm" id="dup_last_name"
                                   name="last_name" maxlength="100" required data-uppercase>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small mb-1" for="dup_birthdate">Birthdate <span class="text-danger">*</span></label>
                            <input type="date" class="form-control form-control-sm" id="dup_birthdate"
                                   name="birthdate" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small mb-1" for="dup_extension_name">Extension</label>
                            <select class="form-select form-select-sm" id="dup_extension_name" name="extension_name">
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
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" form="duplicateCheckForm" class="btn btn-primary" id="duplicateCheckVerify">Verify</button>
            </div>
        </div>
    </div>
</div>
