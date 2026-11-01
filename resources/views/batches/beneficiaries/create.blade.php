@extends('layouts.app')

@section('title', 'Add beneficiary to '.$batch->name)

@section('content')
<div class="sync-console reg-page">
    @include('partials.sync-console-styles')

    <style>
        .sync-console.reg-page .reg-breadcrumb { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; font-size: 12.5px; color: var(--sync-muted); margin-bottom: 14px; }
        .sync-console.reg-page .reg-breadcrumb a { color: var(--sync-muted); text-decoration: none; }
        .sync-console.reg-page .reg-breadcrumb a:hover { color: var(--sync-primary); }
        .sync-console.reg-page .reg-breadcrumb span { color: #cbd5e1; }
        .sync-console.reg-page .reg-batch-pill { display: inline-flex; align-items: center; gap: 6px; margin-top: 8px; padding: 3px 10px; border-radius: 999px; background: #eff6ff; color: #16357C; border: 1px solid #bfdbfe; font-size: 12px; font-weight: 600; }

        .sync-console.reg-page .reg-info-banner { display: flex; gap: 10px; align-items: flex-start; margin-bottom: 18px; padding: 12px 14px; border: 1px solid #bae6fd; border-radius: 10px; background: #f0f9ff; color: #0c4a6e; font-size: 12.5px; line-height: 1.5; }
        .sync-console.reg-page .reg-info-banner svg { flex: 0 0 auto; margin-top: 1px; }

        .sync-console.reg-page .reg-layout { display: grid; grid-template-columns: minmax(0, 220px) minmax(0, 1fr); gap: 20px; align-items: start; }
        @media (max-width: 991.98px) { .sync-console.reg-page .reg-layout { grid-template-columns: 1fr; } }

        .sync-console.reg-page .reg-nav { position: sticky; top: 16px; padding: 16px; }
        @media (max-width: 991.98px) { .sync-console.reg-page .reg-nav { position: static; } }
        .sync-console.reg-page .reg-nav-title { font-size: 12.5px; font-weight: 600; color: var(--sync-muted); margin: 0 0 10px; }
        .sync-console.reg-page .reg-nav-list { list-style: none; margin: 0; padding: 0; display: grid; gap: 4px; }
        .sync-console.reg-page .reg-nav-link { display: flex; align-items: center; gap: 8px; padding: 7px 9px; border-radius: 7px; color: #334155; text-decoration: none; font-size: 12.5px; line-height: 1.3; }
        .sync-console.reg-page .reg-nav-link:hover { background: #f1f5f9; color: var(--sync-text); }
        .sync-console.reg-page .reg-nav-link .reg-nav-dot { width: 7px; height: 7px; border-radius: 50%; background: #cbd5e1; flex: 0 0 auto; }
        .sync-console.reg-page .reg-nav-link.is-complete .reg-nav-dot { background: var(--sync-success); }
        .sync-console.reg-page .reg-nav-link.is-active { background: #eff6ff; color: #16357C; font-weight: 600; }
        .sync-console.reg-page .reg-progress { margin-top: 14px; padding-top: 12px; border-top: 1px solid var(--sync-border); font-size: 11.5px; color: var(--sync-muted); line-height: 1.45; }
        .sync-console.reg-page .reg-progress strong { color: var(--sync-text); }

        .sync-console.reg-page .reg-sections { display: grid; gap: 16px; }
        .sync-console.reg-page .reg-section { background: #fff; border: 1px solid var(--sync-border); border-radius: 12px; box-shadow: var(--sync-shadow); overflow: hidden; scroll-margin-top: 16px; }
        .sync-console.reg-page .reg-section-head { display: flex; align-items: flex-start; gap: 12px; padding: 16px 20px; border-bottom: 1px solid var(--sync-border); background: #fff; }
        .sync-console.reg-page .reg-section-icon { display: inline-flex; align-items: center; justify-content: center; width: 34px; height: 34px; border-radius: 9px; background: #eff6ff; color: var(--sync-primary); flex: 0 0 auto; }
        .sync-console.reg-page .reg-section-title { margin: 0; font-size: 16px; font-weight: 600; letter-spacing: -.01em; }
        .sync-console.reg-page .reg-section-subtitle { margin: 2px 0 0; font-size: 12.5px; color: var(--sync-muted); }
        .sync-console.reg-page .reg-section > .row { padding: 18px 20px; }

        .sync-console.reg-page .form-label { margin-bottom: 5px; font-size: 12.5px; font-weight: 600; color: #334155; }
        .sync-console.reg-page .required { color: var(--sync-danger); }
        .sync-console.reg-page .form-control,
        .sync-console.reg-page .form-select {
            min-height: 42px;
            padding: 9px 11px;
            font-size: 13.5px;
            color: var(--sync-text);
            background-color: #fff;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            transition: border-color .15s ease, box-shadow .15s ease;
        }
        .sync-console.reg-page .form-control:focus,
        .sync-console.reg-page .form-select:focus {
            border-color: var(--sync-primary);
            box-shadow: 0 0 0 3px rgba(30, 66, 155, .18);
        }
        .sync-console.reg-page .form-control.is-invalid,
        .sync-console.reg-page .form-select.is-invalid { border-color: var(--sync-danger); }
        .sync-console.reg-page .form-control.is-invalid:focus,
        .sync-console.reg-page .form-select.is-invalid:focus { box-shadow: 0 0 0 3px rgba(220, 38, 38, .15); }
        .sync-console.reg-page .form-text { margin-top: 4px; font-size: 11.5px; color: var(--sync-muted); line-height: 1.45; }
        .sync-console.reg-page .invalid-feedback { display: none; margin-top: 4px; font-size: 12px; color: var(--sync-danger); }
        .sync-console.reg-page .is-invalid ~ .invalid-feedback,
        .sync-console.reg-page .is-invalid + .invalid-feedback { display: block; }
        .sync-console.reg-page .reg-computed { background: #f6f7fb; color: #334155; font-weight: 600; }

        .sync-console.reg-page .reg-actions-bar {
            position: sticky;
            bottom: 0;
            z-index: 5;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 16px;
            padding: 12px 18px;
            background: rgba(255, 255, 255, .97);
            border: 1px solid var(--sync-border);
            border-radius: 12px;
            box-shadow: 0 -6px 18px -12px rgba(15, 23, 42, .35);
        }
        .sync-console.reg-page .reg-actions-note { font-size: 12px; color: var(--sync-muted); }
        .sync-console.reg-page .reg-actions-buttons { display: flex; gap: 8px; flex-wrap: wrap; margin-left: auto; }
        @media (max-width: 575.98px) {
            .sync-console.reg-page .reg-actions-bar { align-items: stretch; }
            .sync-console.reg-page .reg-actions-buttons { width: 100%; }
            .sync-console.reg-page .reg-actions-buttons .sync-btn { flex: 1 1 100%; }
            .sync-console.reg-page .reg-section > .row { padding: 16px; }
            .sync-console.reg-page .reg-section-head { padding: 14px 16px; }
        }
    </style>

    <nav class="reg-breadcrumb" aria-label="Breadcrumb">
        <a href="{{ route('beneficiary-batches.index') }}">Batches</a>
        <span>/</span>
        <a href="{{ route('beneficiary-batches.show', $batch) }}">{{ $batch->name }}</a>
        <span>/</span>
        <span aria-current="page">Add beneficiary</span>
    </nav>

    <header class="sync-page-head">
        <div>
            <h1 class="sync-page-title">Add Beneficiary</h1>
            <p class="sync-page-subtitle">Register a new beneficiary under the selected batch.</p>
            <div class="reg-batch-pill">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 7h18"/><path d="M3 12h18"/><path d="M3 17h18"/></svg>
                {{ $batch->name }}
            </div>
        </div>
        <div class="sync-page-actions">
            <button type="button" class="sync-btn sync-btn--primary" id="duplicateCheckOpen">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                Verify beneficiary
            </button>
            <a class="sync-btn sync-btn--secondary" href="{{ route('beneficiary-batches.show', $batch) }}">Back to batch</a>
        </div>
    </header>

    <div class="reg-info-banner" role="note">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>
        </svg>
        <div>
            The beneficiary ID <strong>(FIRSTNAME-MIDDLE-LASTNAME-0001)</strong> is generated automatically and cannot be
            changed. You must <strong>Verify</strong> the name and birthdate before you can register a beneficiary.
        </div>
    </div>

    <div class="reg-layout">
        <nav class="sync-card reg-nav" aria-label="Form sections">
            <p class="reg-nav-title">Registration sections</p>
            <ul class="reg-nav-list">
                <li><a class="reg-nav-link" href="#reg-section-identity" data-reg-nav="identity"><span class="reg-nav-dot"></span>Identity</a></li>
                <li><a class="reg-nav-link" href="#reg-section-address" data-reg-nav="address"><span class="reg-nav-dot"></span>Address</a></li>
                <li><a class="reg-nav-link" href="#reg-section-identification" data-reg-nav="identification"><span class="reg-nav-dot"></span>Identification &amp; contact</a></li>
                <li><a class="reg-nav-link" href="#reg-section-employment" data-reg-nav="employment"><span class="reg-nav-dot"></span>Employment &amp; assistance</a></li>
                <li><a class="reg-nav-link" href="#reg-section-additional" data-reg-nav="additional"><span class="reg-nav-dot"></span>Additional information</a></li>
            </ul>
            <div class="reg-progress" aria-live="polite">
                <strong data-reg-progress-count>0 of 5</strong> sections complete<br>
                <span data-reg-progress-missing>Fill in the required fields in each section.</span>
            </div>
        </nav>

        <form method="POST" action="{{ route('beneficiary-batches.beneficiaries.store', $batch) }}" id="beneficiaryForm"
              data-draft-user="{{ auth()->id() ?? 'anonymous' }}"
              @if ($errors->any()) data-has-errors="1" @endif>
            @csrf
            <input type="hidden" name="duplicate_check_done" id="duplicateCheckDone" value="">

            <div class="reg-sections">
                @include('beneficiaries._form')
            </div>

            <div class="reg-actions-bar">
                <span class="reg-actions-note">
                    Fields marked <span class="required">*</span> are required. The beneficiary ID is generated on save.
                </span>
                <div class="reg-actions-buttons">
                    <a class="sync-btn sync-btn--secondary" href="{{ route('beneficiary-batches.show', $batch) }}">Cancel</a>
                    <button class="sync-btn sync-btn--primary" type="submit" id="beneficiarySubmit" disabled>
                        Create beneficiary
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

@include('beneficiaries.partials.duplicate-modal', ['autoOpen' => true])

@push('scripts')
<script>
(function () {
    var form = document.getElementById('beneficiaryForm');
    if (!form) {
        return;
    }

    var sections = Array.prototype.slice.call(form.querySelectorAll('[data-reg-section]'));
    var navLinks = Array.prototype.slice.call(document.querySelectorAll('[data-reg-nav]'));
    var countLabel = document.querySelector('[data-reg-progress-count]');
    var missingLabel = document.querySelector('[data-reg-progress-missing]');

    function isVisible(el) {
        return !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length);
    }

    function sectionComplete(section) {
        var required = Array.prototype.slice.call(section.querySelectorAll('[required]'));
        if (required.length === 0) {
            return true;
        }
        return required.every(function (field) {
            if (!isVisible(field)) {
                return true;
            }
            return field.value.trim() !== '' && field.checkValidity();
        });
    }

    function refreshProgress() {
        var completed = 0;
        var pending = [];

        sections.forEach(function (section) {
            var done = sectionComplete(section);
            var key = section.getAttribute('data-reg-section');
            if (done) {
                completed++;
            } else {
                pending.push(key);
            }

            navLinks.forEach(function (link) {
                if (link.getAttribute('data-reg-nav') === key) {
                    link.classList.toggle('is-complete', done);
                }
            });
        });

        if (countLabel) {
            countLabel.textContent = completed + ' of ' + sections.length;
        }
        if (missingLabel) {
            var labels = {
                identity: 'Identity',
                address: 'Address',
                identification: 'Identification & contact',
                employment: 'Employment & assistance',
                additional: 'Additional information'
            };
            missingLabel.textContent = pending.length === 0
                ? 'All sections complete.'
                : 'Still needed: ' + pending.map(function (key) { return labels[key] || key; }).join(', ') + '.';
        }
    }

    form.addEventListener('input', refreshProgress);
    form.addEventListener('change', refreshProgress);
    refreshProgress();

    // Highlight the section currently in view in the side navigation.
    if ('IntersectionObserver' in window) {
        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting) {
                    return;
                }
                var key = entry.target.getAttribute('data-reg-section');
                navLinks.forEach(function (link) {
                    link.classList.toggle('is-active', link.getAttribute('data-reg-nav') === key);
                });
            });
        }, { rootMargin: '-20% 0px -65% 0px' });

        sections.forEach(function (section) { observer.observe(section); });
    }
})();
</script>
<script src="{{ asset('assets/js/beneficiary-dup-modal.js') }}?v={{ filemtime(public_path('assets/js/beneficiary-dup-modal.js')) }}"></script>
@endpush
@endsection
