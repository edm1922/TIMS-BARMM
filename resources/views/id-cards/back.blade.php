<div class="tupad-card" role="img" aria-label="ID card back">
    <div class="lbl-birth"><strong>Birthdate:</strong></div>
    <div class="val-birth">{{ $beneficiary->birthdate?->format('Y-m-d') }}</div>

    <div class="lbl-addr"><strong>Address:</strong></div>
    <div class="val-addr">{{ $beneficiary->card_address }}</div>

    <div class="emg-head">In case of emergency, please notify:</div>

    {{-- Emergency values are always blank on printed cards (docs §4) --}}
    <div class="lbl-emg1"><strong>Name:</strong></div>
    <div class="line-emg1"></div>
    <div class="lbl-emg2"><strong>Address:</strong></div>
    <div class="line-emg2"></div>
    <div class="lbl-emg3"><strong>Contact no.:</strong></div>
    <div class="line-emg3"></div>

    <div class="rem-title">REMINDERS</div>
    <div class="rem1">This card is non-transferrable. In case of loss, please</div>
    <div class="rem2">notify the Department of Labor and Employment Regional</div>
    <div class="rem3">Office.</div>

    <div class="sig1-name">IMELDA GATINAO</div>
    <div class="sig1-title">Regional Director</div>
    <div class="sig2-name">FRANCIS S. TOLENTINO</div>
    <div class="sig2-title">Secretary</div>
</div>
