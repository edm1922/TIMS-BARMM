{{--
    Dark theme layer on top of Bootstrap 5.3 colour modes and the TIMS-BARMM
    design system. The `data-bs-theme` attribute on <html> is set before paint
    (inline script in the layout) and toggled by the sidebar control.
--}}
<style>
    html[data-bs-theme="dark"] {
        color-scheme: dark;
    }

    html[data-bs-theme="dark"] body {
        background-color: #0b1220;
        color: #e2e8f0;
    }

    html[data-bs-theme="dark"] .app-main,
    html[data-bs-theme="dark"] .app-content {
        background-color: #0b1220;
    }

    /* Custom design-system tokens ---------------------------------------- */
    html[data-bs-theme="dark"] .sync-console {
        --sync-primary: #60a5fa;
        --sync-primary-strong: #93c5fd;
        --sync-text: #e2e8f0;
        --sync-muted: #94a3b8;
        --sync-border: #253247;
        --sync-surface: #111c2e;
        --sync-canvas: #0b1220;
        --sync-success: #4ade80;
        --sync-success-soft: #14351f;
        --sync-warning: #fbbf24;
        --sync-warning-soft: #3a2a0b;
        --sync-danger: #f87171;
        --sync-danger-soft: #3b1418;
        --sync-info: #38bdf8;
        --sync-info-soft: #0d2b3e;
        --sync-shadow: 0 1px 2px rgba(0, 0, 0, .35), 0 1px 3px rgba(0, 0, 0, .45);
        color: var(--sync-text);
    }

    html[data-bs-theme="dark"] .sync-console .sync-card,
    html[data-bs-theme="dark"] .sync-card,
    html[data-bs-theme="dark"] .card {
        background-color: #111c2e !important;
        border-color: #253247 !important;
        color: #e2e8f0;
    }

    html[data-bs-theme="dark"] .card-header,
    html[data-bs-theme="dark"] .card-footer,
    html[data-bs-theme="dark"] .bg-white {
        background-color: #111c2e !important;
        border-color: #253247 !important;
        color: #e2e8f0;
    }

    html[data-bs-theme="dark"] .card-body {
        color: #e2e8f0;
    }

    html[data-bs-theme="dark"] .border,
    html[data-bs-theme="dark"] .border-top,
    html[data-bs-theme="dark"] .border-bottom,
    html[data-bs-theme="dark"] .border-start,
    html[data-bs-theme="dark"] .border-end {
        border-color: #253247 !important;
    }

    html[data-bs-theme="dark"] .text-muted,
    html[data-bs-theme="dark"] .form-text,
    html[data-bs-theme="dark"] .sync-help,
    html[data-bs-theme="dark"] .sync-section-subtitle,
    html[data-bs-theme="dark"] .sync-stat-note {
        color: #94a3b8 !important;
    }

    /* Forms --------------------------------------------------------------- */
    html[data-bs-theme="dark"] .form-control,
    html[data-bs-theme="dark"] .form-select,
    html[data-bs-theme="dark"] .sync-input,
    html[data-bs-theme="dark"] .form-check-input {
        background-color: #0f1a2b !important;
        border-color: #334155 !important;
        color: #e2e8f0 !important;
    }

    html[data-bs-theme="dark"] .form-control::placeholder,
    html[data-bs-theme="dark"] .sync-input::placeholder {
        color: #64748b !important;
    }

    html[data-bs-theme="dark"] .form-control:focus,
    html[data-bs-theme="dark"] .form-select:focus,
    html[data-bs-theme="dark"] .sync-input:focus {
        border-color: #60a5fa !important;
        box-shadow: 0 0 0 3px rgba(96, 165, 250, .22) !important;
    }

    html[data-bs-theme="dark"] .form-control:disabled,
    html[data-bs-theme="dark"] .form-select:disabled,
    html[data-bs-theme="dark"] .form-control[readonly],
    html[data-bs-theme="dark"] .reg-computed {
        background-color: #1b2740 !important;
        color: #94a3b8 !important;
    }

    html[data-bs-theme="dark"] .input-group-text {
        background-color: #1b2740;
        border-color: #334155;
        color: #cbd5e1;
    }

    /* Tables -------------------------------------------------------------- */
    html[data-bs-theme="dark"] .table,
    html[data-bs-theme="dark"] .sync-ops-table {
        --bs-table-bg: #111c2e;
        --bs-table-color: #e2e8f0;
        --bs-table-border-color: #253247;
        --bs-table-hover-bg: #182640;
        --bs-table-hover-color: #fff;
        color: #e2e8f0;
    }

    html[data-bs-theme="dark"] .table > :not(caption) > * > * {
        background-color: var(--bs-table-bg);
        border-color: #253247;
        color: #e2e8f0;
    }

    html[data-bs-theme="dark"] .table thead,
    html[data-bs-theme="dark"] .table-light,
    html[data-bs-theme="dark"] .sync-ops-table thead th {
        background-color: #16233a !important;
        color: #94a3b8 !important;
        border-color: #253247 !important;
    }

    html[data-bs-theme="dark"] .table-hover > tbody > tr:hover > * {
        background-color: #182640 !important;
        color: #fff !important;
    }

    html[data-bs-theme="dark"] .sync-ops-table tbody td,
    html[data-bs-theme="dark"] .sync-ops-table tbody tr:last-child td {
        border-color: #253247;
    }

    html[data-bs-theme="dark"] .table-warning > * {
        background-color: #3a2a0b !important;
        color: #fde68a !important;
    }

    /* Pills, chips and badges --------------------------------------------- */
    html[data-bs-theme="dark"] .sync-pill--neutral,
    html[data-bs-theme="dark"] .report-chip,
    html[data-bs-theme="dark"] .audit-chip,
    html[data-bs-theme="dark"] .user-filter-chip {
        background-color: #1b2740;
        border-color: #334155;
        color: #cbd5e1;
    }

    html[data-bs-theme="dark"] .badge.text-bg-light,
    html[data-bs-theme="dark"] .badge.text-bg-secondary,
    html[data-bs-theme="dark"] .badge.text-bg-dark {
        background-color: #1b2740 !important;
        color: #e2e8f0 !important;
    }

    html[data-bs-theme="dark"] .text-bg-primary {
        background-color: #1d4ed8 !important;
    }

    html[data-bs-theme="dark"] .text-bg-info {
        background-color: #0369a1 !important;
    }

    html[data-bs-theme="dark"] .text-bg-success {
        background-color: #15803d !important;
    }

    html[data-bs-theme="dark"] .bg-success-subtle,
    html[data-bs-theme="dark"] .badge.bg-success-subtle {
        background-color: #14351f !important;
        color: #86efac !important;
    }

    html[data-bs-theme="dark"] .bg-danger-subtle,
    html[data-bs-theme="dark"] .badge.bg-danger-subtle {
        background-color: #3b1418 !important;
        color: #fca5a5 !important;
    }

    /* Notes, alerts and callouts ----------------------------------------- */
    html[data-bs-theme="dark"] .alert {
        background-color: #16233a;
        border-color: #334155;
        color: #e2e8f0;
    }

    html[data-bs-theme="dark"] .alert-info,
    html[data-bs-theme="dark"] .tims-note,
    html[data-bs-theme="dark"] .reg-info-banner {
        background-color: #0d2b3e !important;
        border-color: #155e75 !important;
        color: #bae6fd !important;
    }

    html[data-bs-theme="dark"] .alert-warning,
    html[data-bs-theme="dark"] .tims-note--warning {
        background-color: #3a2a0b !important;
        border-color: #78350f !important;
        color: #fde68a !important;
    }

    html[data-bs-theme="dark"] .alert-danger,
    html[data-bs-theme="dark"] .tims-note--danger {
        background-color: #3b1418 !important;
        border-color: #991b1b !important;
        color: #fecaca !important;
    }

    html[data-bs-theme="dark"] .alert-success,
    html[data-bs-theme="dark"] .tims-note--success {
        background-color: #14351f !important;
        border-color: #166534 !important;
        color: #bbf7d0 !important;
    }

    /* Buttons ------------------------------------------------------------- */
    html[data-bs-theme="dark"] .sync-btn--secondary,
    html[data-bs-theme="dark"] .sync-btn--secondary:hover,
    html[data-bs-theme="dark"] .btn-outline-secondary {
        background-color: #111c2e;
        border-color: #334155;
        color: #e2e8f0;
    }

    html[data-bs-theme="dark"] .sync-btn--secondary:hover,
    html[data-bs-theme="dark"] .btn-outline-secondary:hover {
        background-color: #1b2740;
        border-color: #475569;
        color: #fff;
    }

    html[data-bs-theme="dark"] .btn-outline-primary {
        border-color: #3b82f6;
        color: #93c5fd;
    }

    html[data-bs-theme="dark"] .btn-outline-primary:hover {
        background-color: #1d4ed8;
        color: #fff;
    }

    html[data-bs-theme="dark"] .btn-outline-danger {
        border-color: #f87171;
        color: #fca5a5;
    }

    html[data-bs-theme="dark"] .btn-outline-light {
        border-color: rgba(255, 255, 255, .5);
        color: #f8fafc;
    }

    /* Modals -------------------------------------------------------------- */
    html[data-bs-theme="dark"] .modal-content {
        background-color: #111c2e !important;
        border-color: #253247 !important;
        color: #e2e8f0;
    }

    html[data-bs-theme="dark"] .modal-header,
    html[data-bs-theme="dark"] .modal-footer {
        background-color: #111c2e !important;
        border-color: #253247 !important;
    }

    html[data-bs-theme="dark"] .modal-footer {
        background-color: #0f1a2b !important;
    }

    html[data-bs-theme="dark"] .modal .form-control,
    html[data-bs-theme="dark"] .modal .form-select {
        background-color: #0f1a2b !important;
        border-color: #334155 !important;
        color: #e2e8f0 !important;
    }

    html[data-bs-theme="dark"] .modal .tims-note {
        background-color: #0d2b3e !important;
        border-color: #155e75 !important;
        color: #bae6fd !important;
    }

    html[data-bs-theme="dark"] .modal .tims-note--danger {
        background-color: #3b1418 !important;
        border-color: #991b1b !important;
        color: #fecaca !important;
    }

    html[data-bs-theme="dark"] .modal .tims-note--warning {
        background-color: #3a2a0b !important;
        border-color: #78350f !important;
        color: #fde68a !important;
    }

    html[data-bs-theme="dark"] .btn-close {
        filter: invert(1) grayscale(100%) brightness(200%);
    }

    /* Registration page and custom panels -------------------------------- */
    html[data-bs-theme="dark"] .reg-section,
    html[data-bs-theme="dark"] .reg-nav,
    html[data-bs-theme="dark"] .reg-actions-bar,
    html[data-bs-theme="dark"] .batch-settings-card {
        background-color: #111c2e !important;
        border-color: #253247 !important;
    }

    html[data-bs-theme="dark"] .reg-section-head {
        background-color: #111c2e !important;
        border-color: #253247 !important;
    }

    html[data-bs-theme="dark"] .reg-section-icon,
    html[data-bs-theme="dark"] .sync-stat-icon {
        background-color: #1b2740 !important;
        color: #93c5fd !important;
    }

    html[data-bs-theme="dark"] .reg-nav-link {
        color: #cbd5e1;
    }

    html[data-bs-theme="dark"] .reg-nav-link:hover {
        background-color: #1b2740;
        color: #fff;
    }

    html[data-bs-theme="dark"] .reg-nav-link.is-active {
        background-color: #172554;
        color: #bfdbfe;
    }

    html[data-bs-theme="dark"] .reg-actions-bar {
        background-color: rgba(17, 28, 46, .97) !important;
        box-shadow: 0 -6px 18px -12px rgba(0, 0, 0, .8);
    }

    html[data-bs-theme="dark"] .sync-console .sync-ops-table thead th {
        background-color: #16233a !important;
    }

    html[data-bs-theme="dark"] .sync-console .sync-ops-table tbody tr:hover {
        background-color: #182640;
    }

    html[data-bs-theme="dark"] .sync-console .sync-ops-uuid,
    html[data-bs-theme="dark"] .sync-console .report-id,
    html[data-bs-theme="dark"] .batch-id,
    html[data-bs-theme="dark"] .user-username {
        background-color: #0f1a2b;
        border-color: #334155;
        color: #cbd5e1;
    }

    html[data-bs-theme="dark"] .sync-console .batch-capacity-bar,
    html[data-bs-theme="dark"] .sync-console .sync-progress {
        background-color: #1b2740;
    }

    html[data-bs-theme="dark"] .sync-console .sync-diag-row,
    html[data-bs-theme="dark"] .sync-console .sync-kv-row {
        border-color: #253247;
    }

    html[data-bs-theme="dark"] .sync-console .sync-audit,
    html[data-bs-theme="dark"] .sync-console .sync-section-head {
        border-color: #253247;
    }

    html[data-bs-theme="dark"] .sync-console .sync-body--divided {
        border-color: #253247;
    }

    html[data-bs-theme="dark"] .sync-console .sync-url-group .sync-input {
        background-color: #0f1a2b;
    }

    html[data-bs-theme="dark"] .sync-console .sync-qr canvas {
        background: #fff;
    }

    /* Dropdowns, lists and tooltips --------------------------------------- */
    html[data-bs-theme="dark"] .dropdown-menu,
    html[data-bs-theme="dark"] .list-group-item {
        background-color: #111c2e;
        border-color: #253247;
        color: #e2e8f0;
    }

    html[data-bs-theme="dark"] .dropdown-item:hover,
    html[data-bs-theme="dark"] .list-group-item:hover {
        background-color: #1b2740;
        color: #fff;
    }

    html[data-bs-theme="dark"] .dropdown-item-text {
        color: #94a3b8;
    }

    html[data-bs-theme="dark"] .breadcrumb-item + .breadcrumb-item::before {
        color: #64748b;
    }

    html[data-bs-theme="dark"] .page-link {
        background-color: #111c2e;
        border-color: #334155;
        color: #93c5fd;
    }

    html[data-bs-theme="dark"] .page-link:hover {
        background-color: #1b2740;
        color: #fff;
    }

    html[data-bs-theme="dark"] .page-item.active .page-link {
        background-color: #2563eb;
        border-color: #2563eb;
        color: #fff;
    }

    html[data-bs-theme="dark"] .page-item.disabled .page-link {
        background-color: #0f1a2b;
        border-color: #253247;
        color: #64748b;
    }

    /* Theme toggle -------------------------------------------------------- */
    .theme-toggle {
        display: flex;
        align-items: center;
        gap: 8px;
        width: 100%;
        margin-bottom: 6px;
        padding: 6px 10px;
        border: 1px solid rgba(255, 255, 255, .16);
        border-radius: 7px;
        background: transparent;
        color: #e2e8f0;
        font-size: 12.5px;
        font-weight: 500;
        line-height: 1.2;
        cursor: pointer;
        transition: background-color .15s ease, border-color .15s ease;
    }

    .theme-toggle:hover {
        background: rgba(255, 255, 255, .08);
        border-color: rgba(255, 255, 255, .28);
    }

    .theme-toggle:focus-visible {
        outline: 2px solid #93c5fd;
        outline-offset: 2px;
    }

    .theme-toggle svg {
        flex: 0 0 auto;
    }

    .theme-toggle .theme-icon-moon {
        display: none;
    }

    html[data-bs-theme="dark"] .theme-toggle .theme-icon-sun {
        display: none;
    }

    html[data-bs-theme="dark"] .theme-toggle .theme-icon-moon {
        display: inline-flex;
    }

    .theme-toggle--compact {
        width: auto;
        margin: 0 0 0 6px;
        padding: 5px 8px;
        border-color: rgba(100, 116, 139, .45);
        color: #334155;
    }

    .theme-toggle--compact:hover {
        background: rgba(100, 116, 139, .12);
        border-color: rgba(100, 116, 139, .7);
    }

    html[data-bs-theme="dark"] .theme-toggle--compact {
        color: #e2e8f0;
        border-color: rgba(148, 163, 184, .45);
    }
</style>
