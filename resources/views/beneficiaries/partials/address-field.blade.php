@php
    /**
     * Address combobox backed by the BARMM PSGC reference data
     * (GET /locations/search). Selecting a suggestion fills the hidden
     * location_code and shows the auto City/Municipality + Province.
     *
     * @var \App\Models\Beneficiary|null $beneficiary
     */
    $currentAddress = old('address', $beneficiary?->address ?? $beneficiary?->card_address);
    $currentCode = old('location_code', $beneficiary?->location_code);
    $currentCity = old('city_display', $beneficiary?->city_municipality);
    $currentProvince = old('province_display', $beneficiary?->province);
    $isLegacyAddress = $beneficiary !== null && $currentCode === null && $currentAddress;
@endphp

<div class="col-md-8 position-relative" data-address-widget data-endpoint="{{ route('locations.search') }}">
    <label class="form-label" for="address">Address <span class="text-danger">*</span></label>
    <input type="text" class="form-control @error('address') is-invalid @enderror" id="address" name="address"
           value="{{ $currentAddress }}" autocomplete="off" maxlength="255"
           placeholder="Type a barangay, municipality, or province&hellip;"
           data-address-input>
    <input type="hidden" id="location_code" name="location_code" value="{{ $currentCode }}" data-address-code>
    <div class="list-group position-absolute shadow-sm d-none" style="z-index: 1080; max-height: 16rem; overflow-y: auto;"
         data-address-results></div>
    @error('address')
        <div class="invalid-feedback d-block">{{ $message }}</div>
    @enderror
    @if ($isLegacyAddress)
        <div class="alert alert-warning py-1 px-2 small mt-2 mb-0">
            This record uses an address captured before the BARMM location list existed. To change it, pick a barangay from the list.
        </div>
    @endif
</div>

<div class="col-md-2">
    <label class="form-label" for="city_display">City / Municipality</label>
    <input type="text" class="form-control" id="city_display" name="city_display" value="{{ $currentCity }}"
           readonly tabindex="-1" data-address-city>
</div>
<div class="col-md-2">
    <label class="form-label" for="province_display">Province</label>
    <input type="text" class="form-control" id="province_display" name="province_display" value="{{ $currentProvince }}"
           readonly tabindex="-1" data-address-province>
</div>

@once
@push('scripts')
<script src="{{ asset('assets/js/beneficiary-address.js') }}"></script>
@endpush
@endonce
