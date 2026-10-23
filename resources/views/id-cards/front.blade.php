<div class="tupad-card" role="img" aria-label="ID card front">
    {{-- Header band cropped from FRONT.pdf at 600 DPI (docs/id-card-layout.md §6) --}}
    <img class="band" src="{{ asset('images/id-cards/header-band.png') }}" alt="TUPAD — Department of Labor and Employment">

    <div class="photo-placeholder"><span>1x1 Picture</span></div>

    <div class="idno">ID NO.<strong>{{ $beneficiary->beneficiary_code }}</strong></div>

    <div class="card-name">{{ $beneficiary->full_name }}</div>
    <div class="name-caption">FIRST, MIDDLE, LAST NAME</div>

    <div class="signature-line"></div>
    <div class="signature-caption">SIGNATURE</div>

    <div class="issued">Issued: {{ $beneficiary->created_at->format('m/d/Y') }}</div>
    <div class="expires">Expires: 3 months after implementation</div>
</div>
