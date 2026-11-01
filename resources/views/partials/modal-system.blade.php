{{--
    Global modal & dialog design system.

    One stylesheet for every Bootstrap modal in TIMS-BARMM: standard header,
    body and footer structure, form controls, alert variants, buttons and
    responsive behaviour. Markup keeps the existing Bootstrap modal API so all
    data-bs-* behaviour (focus trap, ESC, backdrop, focus restore) is retained.
--}}
<style>
    .modal {
        --tims-modal-primary: #2563eb;
        --tims-modal-primary-hover: #1d4ed8;
        --tims-modal-text: #0f172a;
        --tims-modal-muted: #64748b;
        --tims-modal-border: #e2e8f0;
        --tims-modal-surface: #ffffff;
        --tims-modal-canvas: #f8fafc;
        --tims-modal-success: #15803d;
        --tims-modal-warning: #d97706;
        --tims-modal-danger: #dc2626;
        --tims-modal-danger-hover: #b91c1c;
        --tims-modal-radius: 12px;
    }

    .modal-backdrop {
        background-color: #0f172a;
    }

    .modal-backdrop.show {
        opacity: .5;
    }

    .modal-dialog {
        margin: 1.75rem auto;
    }

    .modal-dialog-centered {
        min-height: calc(100% - 3.5rem);
    }

    .modal-content {
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        box-shadow: 0 24px 48px -12px rgba(15, 23, 42, .25), 0 4px 12px rgba(15, 23, 42, .08);
        overflow: hidden;
        color: #0f172a;
    }

    .modal-header {
        display: flex;
        align-items: flex-start;
        gap: 12px;
        padding: 16px 20px;
        background: #fff;
        border-bottom: 1px solid #e2e8f0;
    }

    .modal-header .modal-title {
        margin: 0;
        font-size: 18px;
        font-weight: 600;
        line-height: 1.3;
        letter-spacing: -.01em;
    }

    .modal-header .tims-modal-subtitle {
        margin: 3px 0 0;
        font-size: 12.5px;
        font-weight: 400;
        color: #64748b;
    }

    .modal-header .btn-close {
        margin-left: auto;
        flex: 0 0 auto;
        padding: .55rem;
        border-radius: 6px;
    }

    .modal-header .btn-close:focus-visible {
        outline: 2px solid #2563eb;
        outline-offset: 2px;
        box-shadow: none;
    }

    .tims-modal-icon {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 34px;
        height: 34px;
        flex: 0 0 auto;
        border-radius: 9px;
        background: #eff6ff;
        color: #2563eb;
    }

    .tims-modal-icon--warning {
        background: #fef3c7;
        color: #b45309;
    }

    .tims-modal-icon--danger {
        background: #fee2e2;
        color: #b91c1c;
    }

    .modal-body {
        padding: 20px;
        font-size: 13.5px;
        line-height: 1.55;
    }

    .modal-dialog-scrollable .modal-body {
        max-height: calc(90vh - 150px);
    }

    .modal-body > *:last-child {
        margin-bottom: 0;
    }

    .modal-footer {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: flex-end;
        gap: 8px;
        padding: 12px 20px;
        background: #f8fafc;
        border-top: 1px solid #e2e8f0;
    }

    .modal-footer .btn {
        min-height: 38px;
        padding: 7px 16px;
        font-size: 13.5px;
        font-weight: 600;
        border-radius: 8px;
    }

    /* Form controls inside any modal ------------------------------------- */
    .modal .form-label {
        margin-bottom: 5px;
        font-size: 12.5px;
        font-weight: 600;
        color: #334155;
    }

    .modal .form-label .required,
    .modal .form-label .text-danger {
        color: #dc2626;
    }

    .modal .form-control,
    .modal .form-select {
        min-height: 40px;
        padding: 8px 11px;
        font-size: 13.5px;
        color: #0f172a;
        background-color: #fff;
        border: 1px solid #cbd5e1;
        border-radius: 8px;
        transition: border-color .15s ease, box-shadow .15s ease;
    }

    .modal .form-control-sm,
    .modal .form-select-sm {
        min-height: 38px;
        padding: 7px 10px;
        font-size: 13px;
        border-radius: 8px;
    }

    .modal .form-control:hover,
    .modal .form-select:hover {
        border-color: #94a3b8;
    }

    .modal .form-control:focus,
    .modal .form-select:focus {
        border-color: #2563eb;
        box-shadow: 0 0 0 3px rgba(37, 99, 235, .18);
    }

    .modal .form-control::placeholder {
        color: #94a3b8;
    }

    .modal .form-control:disabled,
    .modal .form-select:disabled,
    .modal .form-control[readonly] {
        background: #f1f5f9;
        color: #64748b;
    }

    .modal .form-control.is-invalid,
    .modal .form-select.is-invalid {
        border-color: #dc2626;
    }

    .modal .invalid-feedback {
        display: none;
        margin-top: 4px;
        font-size: 12px;
        color: #dc2626;
    }

    .modal .is-invalid ~ .invalid-feedback,
    .modal .form-control.is-invalid + .invalid-feedback {
        display: block;
    }

    .modal .form-text,
    .modal .tims-help {
        margin-top: 4px;
        font-size: 12px;
        color: #64748b;
        line-height: 1.45;
    }

    .modal .tims-field {
        margin-bottom: 14px;
    }

    .modal .tims-field:last-child {
        margin-bottom: 0;
    }

    .modal .tims-field-error {
        margin-top: 4px;
        font-size: 12px;
        color: #dc2626;
    }

    .modal .form-check-input {
        border-color: #94a3b8;
    }

    .modal .form-check-input:checked {
        background-color: #2563eb;
        border-color: #2563eb;
    }

    .modal .form-check-input:focus {
        border-color: #2563eb;
        box-shadow: 0 0 0 3px rgba(37, 99, 235, .18);
    }

    /* Shared note / alert blocks ----------------------------------------- */
    .modal .tims-note {
        display: flex;
        gap: 10px;
        align-items: flex-start;
        margin: 16px 0 0;
        padding: 11px 13px;
        border: 1px solid #bae6fd;
        border-radius: 9px;
        background: #f0f9ff;
        color: #0c4a6e;
        font-size: 12.5px;
        line-height: 1.5;
    }

    .modal .tims-note strong {
        font-weight: 600;
    }

    .modal .tims-note--warning {
        border-color: #fde68a;
        background: #fffbeb;
        color: #78350f;
    }

    .modal .tims-note--danger {
        border-color: #fecaca;
        background: #fef2f2;
        color: #7f1d1d;
    }

    .modal .tims-note--success {
        border-color: #bbf7d0;
        background: #f0fdf4;
        color: #14532d;
    }

    .modal .tims-note svg {
        flex: 0 0 auto;
        margin-top: 1px;
    }

    /* Buttons: primary / secondary / danger ----------------------------- */
    .modal .btn {
        font-size: 13.5px;
        font-weight: 600;
        border-radius: 8px;
    }

    .modal .btn-primary {
        background-color: #2563eb;
        border-color: #2563eb;
        color: #fff;
    }

    .modal .btn-primary:hover,
    .modal .btn-primary:focus {
        background-color: #1d4ed8;
        border-color: #1d4ed8;
        color: #fff;
    }

    .modal .btn-danger {
        background-color: #dc2626;
        border-color: #dc2626;
        color: #fff;
    }

    .modal .btn-danger:hover,
    .modal .btn-danger:focus {
        background-color: #b91c1c;
        border-color: #b91c1c;
        color: #fff;
    }

    .modal .btn-outline-secondary {
        background: #fff;
        border-color: #cbd5e1;
        color: #334155;
    }

    .modal .btn-outline-secondary:hover {
        background: #f1f5f9;
        border-color: #94a3b8;
        color: #0f172a;
    }

    .modal .btn:focus-visible {
        outline: 2px solid #2563eb;
        outline-offset: 2px;
        box-shadow: none;
    }

    .modal .btn:disabled {
        opacity: .65;
        cursor: not-allowed;
    }

    .modal .btn.is-loading {
        position: relative;
        color: transparent !important;
    }

    .modal .btn.is-loading::after {
        content: "";
        position: absolute;
        top: 50%;
        left: 50%;
        width: 15px;
        height: 15px;
        margin: -8px 0 0 -8px;
        border: 2px solid rgba(255, 255, 255, .5);
        border-top-color: #fff;
        border-radius: 50%;
        animation: tims-modal-spin .7s linear infinite;
    }

    .modal .btn-outline-secondary.is-loading::after,
    .modal .btn-secondary.is-loading::after {
        border-color: rgba(15, 23, 42, .25);
        border-top-color: #0f172a;
    }

    @keyframes tims-modal-spin {
        to { transform: rotate(360deg); }
    }

    /* Modal size variants ------------------------------------------------ */
    .modal-dialog.tims-modal--sm {
        max-width: 440px;
    }

    .modal-dialog.tims-modal--md {
        max-width: 540px;
    }

    .modal-dialog.tims-modal--lg {
        max-width: 760px;
    }

    .modal-dialog.tims-modal--xl {
        max-width: 1040px;
    }

    /* Responsive ---------------------------------------------------------- */
    @media (max-width: 767.98px) {
        .modal-dialog {
            margin: .75rem;
        }

        .modal-dialog,
        .modal-dialog.tims-modal--sm,
        .modal-dialog.tims-modal--md,
        .modal-dialog.tims-modal--lg,
        .modal-dialog.tims-modal--xl {
            max-width: calc(100% - 1.5rem);
        }

        .modal-header {
            padding: 14px 16px;
        }

        .modal-body {
            padding: 16px;
        }

        .modal-footer {
            padding: 12px 16px;
        }

        .modal-footer .btn {
            flex: 1 1 auto;
        }

        .modal-dialog-scrollable .modal-body {
            max-height: calc(100vh - 160px);
        }
    }

    @media (prefers-reduced-motion: reduce) {
        .modal .btn.is-loading::after {
            animation-duration: 2s;
        }
    }
</style>
