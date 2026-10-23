<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ID card{{ $beneficiaries->count() > 1 ? 's ('.$beneficiaries->count().')' : '' }} — {{ config('app.name') }}</title>
    <style>
        /* Print setup per docs/id-card-layout.md §7:
           A4 portrait, no page margins, one card face per page,
           front = page 1, back = page 2, repeat (front, back) for batches. */
        @page {
            size: A4 portrait;
            margin: 0;
        }

        * {
            box-sizing: border-box;
        }

        html, body {
            margin: 0;
            padding: 0;
            background: #e9ecef;
            font-family: Arial, Helvetica, sans-serif;
            color: #000;
        }

        .toolbar {
            position: sticky;
            top: 0;
            z-index: 10;
            display: flex;
            align-items: center;
            gap: .75rem;
            flex-wrap: wrap;
            padding: .75rem 1rem;
            background: #fff;
            border-bottom: 1px solid #dee2e6;
        }

        .toolbar .hint {
            color: #6c757d;
            font-size: .8rem;
        }

        .toolbar a {
            color: #0d6efd;
            font-size: .85rem;
        }

        .toolbar button {
            padding: .35rem .85rem;
            border: 1px solid #0d6efd;
            border-radius: .25rem;
            background: #0d6efd;
            color: #fff;
            font-size: .85rem;
            font-family: inherit;
            cursor: pointer;
        }

        .toolbar button:hover {
            background: #0b5ed7;
        }

        .pages {
            padding: 10mm;
        }

        .card-page {
            padding: 10mm 0;
            text-align: center;
        }

        .card-page:first-child {
            padding-top: 10mm;
        }

        /* ---------- card geometry (50% of FRONT/BACK.pdf frames) ---------- */
        .tupad-card {
            position: relative;
            display: inline-block;
            width: 99.4mm;
            height: 130.2mm;
            background: #fff;
            border: 0.27mm solid #000;
            overflow: hidden;
            text-align: left;
            print-color-adjust: exact;
            -webkit-print-color-adjust: exact;
        }

        .tupad-card > div,
        .tupad-card > img {
            position: absolute;
            margin: 0;
            white-space: nowrap;
            line-height: 1.15;
            print-color-adjust: exact;
            -webkit-print-color-adjust: exact;
        }

        /* ---------------- front face (docs §2, print-scale pt) ---------------- */
        .tupad-card .band {
            left: 0;
            top: 0;
            width: 100%;
            height: auto;
        }

        .tupad-card .photo-placeholder {
            left: 121pt;
            top: 130pt;
            width: 72pt;
            height: 72pt;
            border: 0.5pt solid #c7c7c7;
            background: #fafafa;
            color: #999;
            font-size: 6pt;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .tupad-card .idno {
            left: 32.8pt;
            top: 224.5pt;
            font-size: 7pt;
        }

        .tupad-card .idno strong {
            font-size: 8.5pt;
            margin-left: 8pt;
            letter-spacing: .2pt;
        }

        .tupad-card .card-name {
            left: 0;
            top: 267pt;
            width: 100%;
            text-align: center;
            font-size: 8.5pt;
            font-weight: bold;
            text-transform: uppercase;
            padding: 0 8mm;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .tupad-card .name-caption {
            left: 0;
            top: 279.2pt;
            width: 100%;
            text-align: center;
            font-size: 5pt;
            letter-spacing: .4pt;
        }

        .tupad-card .signature-line {
            left: 100pt;
            top: 318pt;
            width: 120pt;
            height: 0;
            border-top: 0.5pt solid #000;
        }

        .tupad-card .signature-caption {
            left: 0;
            top: 325.9pt;
            width: 100%;
            text-align: center;
            font-size: 5pt;
            letter-spacing: .4pt;
        }

        .tupad-card .issued {
            left: 41.9pt;
            top: 345.5pt;
            font-size: 7pt;
        }

        .tupad-card .expires {
            left: 41.8pt;
            top: 354pt;
            font-size: 7pt;
        }

        /* ---------------- back face (docs §3) ---------------- */
        .tupad-card .lbl-birth { left: 10.3pt; top: 22.4pt; font-size: 8pt; }
        .tupad-card .val-birth { left: 58.7pt; top: 22.4pt; font-size: 8pt; }
        .tupad-card .lbl-addr  { left: 10.0pt; top: 34.9pt; font-size: 8pt; }
        .tupad-card .val-addr  {
            left: 57.5pt;
            top: 34.9pt;
            font-size: 8pt;
            max-width: 210pt;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .tupad-card .emg-head { left: 37.0pt; top: 90.7pt; font-size: 9pt; }

        .tupad-card .lbl-emg1 { left: 10.0pt; top: 106.4pt; font-size: 8pt; }
        .tupad-card .line-emg1 { left: 49.5pt; top: 117.5pt; width: 172.5pt; height: 0; border-top: 0.5pt solid #000; }
        .tupad-card .lbl-emg2 { left: 10.2pt; top: 131.3pt; font-size: 8pt; }
        .tupad-card .line-emg2 { left: 54.4pt; top: 142.4pt; width: 175.7pt; height: 0; border-top: 0.5pt solid #000; }
        .tupad-card .lbl-emg3 { left: 10.1pt; top: 155.8pt; font-size: 8pt; }
        .tupad-card .line-emg3 { left: 76.7pt; top: 166.0pt; width: 142.8pt; height: 0; border-top: 0.5pt solid #000; }

        .tupad-card .rem-title { left: 103.7pt; top: 212.0pt; font-size: 8pt; font-weight: bold; }
        .tupad-card .rem1 { left: 42.5pt; top: 225.0pt; font-size: 7pt; }
        .tupad-card .rem2 { left: 32.8pt; top: 233.4pt; font-size: 7pt; }
        .tupad-card .rem3 { left: 121.4pt; top: 241.9pt; font-size: 7pt; }

        .tupad-card .sig1-name  { left: 73.6pt; top: 276.8pt; font-size: 7pt; }
        .tupad-card .sig1-title { left: 108.1pt; top: 287.9pt; font-size: 6.5pt; }
        .tupad-card .sig2-name  { left: 55.7pt; top: 328.3pt; font-size: 7pt; }
        .tupad-card .sig2-title { left: 116.5pt; top: 338.9pt; font-size: 6.5pt; }

        /* ---------------- print rendering ---------------- */
        @media print {
            html, body {
                background: #fff;
            }

            .no-print {
                display: none !important;
            }

            .pages {
                padding: 0;
            }

            .card-page {
                padding: 0;
                margin: 0;
                text-align: left;
                break-after: page;
                page-break-after: always;
                break-inside: avoid;
                page-break-inside: avoid;
            }

            .card-page.no-break {
                break-after: auto;
                page-break-after: auto;
            }

            /* placeholder only in preview; blank on final print (docs §7) */
            .photo-placeholder {
                display: none !important;
            }
        }
    </style>
</head>
<body>
<div class="toolbar no-print">
    <button class="btn btn-primary btn-sm" type="button" onclick="window.print()">Print…</button>
    @if ($mode === 'single')
        <a href="{{ route('beneficiaries.show', $beneficiaries->first()) }}">&larr; Back to record</a>
    @else
        <a href="{{ route('id-cards.index') }}">&larr; Back to selection</a>
    @endif
    <span class="hint">
        {{ $beneficiaries->count() }} card{{ $beneficiaries->count() > 1 ? 's' : '' }}
        — printer settings: A4, <strong>actual size / 100%</strong>, margins <strong>none</strong>,
        headers and footers <strong>off</strong>, background graphics <strong>on</strong>.
        Duplex: flip on long edge (front = page 1, back = page 2).
    </span>
</div>

<div class="pages">
    @foreach ($beneficiaries as $beneficiary)
        <div class="card-page">
            @include('id-cards.front')
        </div>
        <div class="card-page {{ $loop->last ? 'no-break' : '' }}">
            @include('id-cards.back')
        </div>
    @endforeach
</div>
</body>
</html>
