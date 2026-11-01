{{--
    GIPS design language applied globally to TIMS-BARMM.
    Extracted from C:\xampp\htdocs\GIPS (read-only reference):
    primary #1E429B, sidebar #2c3340/#242a33, topbar graphite gradient,
    background #f6f7fb, card borders #e5e7eb, 10-12px radii, Inter-first type.
--}}
<style>
    :root {
        --company-primary: #1E429B;
        --company-primary-hover: #16357C;
        --company-primary-light: rgba(30, 66, 155, .1);
        --company-primary-border: rgba(30, 66, 155, .2);
        --gips-sidebar: #2c3340;
        --gips-sidebar-dark: #242a33;
        --gips-topbar-start: #191e23;
        --gips-topbar-end: #232a31;
        --gips-canvas: #f6f7fb;
        --gips-card-border: #e5e7eb;
        --gips-text: #1f2937;
        --gips-muted: #6b7280;

        --bs-primary: #1E429B;
        --bs-primary-rgb: 30, 66, 155;
        --bs-link-color: #1E429B;
        --bs-link-color-rgb: 30, 66, 155;
        --bs-link-hover-color: #16357C;
        --bs-link-hover-color-rgb: 22, 53, 124;
        --bs-body-bg: #f6f7fb;
        --bs-body-color: #1f2937;
        --bs-border-color: #e5e7eb;
    }

    body {
        font-family: 'Inter', 'Segoe UI', system-ui, -apple-system, 'Helvetica Neue', Arial, sans-serif;
        background-color: var(--gips-canvas);
        color: var(--gips-text);
    }

    h1, h2, h3, h4, h5, h6 { color: var(--gips-text); }

    .app-main { background: var(--gips-canvas); }

    /* ── Buttons ─────────────────────────────────────────────────────── */
    .btn-primary,
    .sync-console .sync-btn--primary,
    .login-submit {
        background-color: var(--company-primary);
        border-color: var(--company-primary);
        color: #fff;
    }

    .btn-primary:hover,
    .btn-primary:focus,
    .sync-console .sync-btn--primary:hover,
    .sync-console .sync-btn--primary:focus {
        background-color: var(--company-primary-hover);
        border-color: var(--company-primary-hover);
        color: #fff;
    }

    .btn-outline-primary,
    .sync-console .btn-outline-primary {
        color: var(--company-primary);
        border-color: var(--company-primary);
    }

    .btn-outline-primary:hover,
    .sync-console .btn-outline-primary:hover {
        background-color: var(--company-primary);
        border-color: var(--company-primary);
        color: #fff;
    }

    .form-control:focus,
    .form-select:focus,
    .sync-console .sync-input:focus,
    .modal .form-control:focus,
    .modal .form-select:focus {
        border-color: var(--company-primary);
        box-shadow: 0 0 0 3px var(--company-primary-light);
    }

    /* ── Cards / surfaces ────────────────────────────────────────────── */
    .sync-console {
        --sync-primary: #1E429B;
        --sync-primary-strong: #16357C;
        --sync-canvas: #f6f7fb;
        --sync-border: #e5e7eb;
        --sync-radius: 10px;
        --sync-shadow: 0 1px 6px rgba(0, 0, 0, .08);
    }

    .card,
    .panel-card,
    .sync-card,
    .sync-console .sync-card,
    .graph-container {
        border-color: var(--gips-card-border);
        border-radius: 10px;
        box-shadow: 0 1px 6px rgba(0, 0, 0, .08);
    }

    .card-header,
    .panel-card-header {
        border-bottom-color: var(--gips-card-border);
    }

    /* ── Tables ──────────────────────────────────────────────────────── */
    .table thead th,
    .sync-console .sync-ops-table thead th {
        background: #f9fafb;
        color: var(--gips-muted);
        font-size: .75rem;
        font-weight: 700;
        letter-spacing: .03em;
        text-transform: uppercase;
        border-color: var(--gips-card-border);
    }

    .table tbody td,
    .sync-console .sync-ops-table tbody td {
        border-color: #f1f2f4;
        color: var(--gips-text);
    }

    .table-hover tbody tr:hover > *,
    .sync-console .sync-ops-table tbody tr:hover {
        background-color: #f9fafb;
    }

    /* ── Sidebar (GIPS graphite palette) ─────────────────────────────── */
    .app-sidebar-wrapper,
    .app-sidebar,
    .app-sidebar-inner {
        background-color: var(--gips-sidebar) !important;
    }

    .app-sidebar {
        --bs-offcanvas-bg: #2c3340;
        --bs-offcanvas-color: #f6f7fb;
        --bs-offcanvas-border-color: rgba(255, 255, 255, .06);
    }

    .app-sidebar-inner {
        background-color: var(--gips-sidebar);
    }

    .app-sidebar-brand {
        background: var(--gips-sidebar-dark);
        border-bottom: 1px solid rgba(255, 255, 255, .06);
    }

    .app-nav .nav-link {
        color: #a0aab5;
        border-left: 3px solid transparent;
        border-radius: 0;
        min-height: 38px;
        padding: .5rem .75rem;
        font-size: .88rem;
    }

    .app-nav .nav-link:hover {
        background: rgba(255, 255, 255, .08);
        color: #fff;
        border-left-color: rgba(30, 66, 155, .4);
    }

    .app-nav .nav-link.active {
        background: rgba(30, 66, 155, .25);
        color: #fff;
        border-left-color: var(--company-primary);
        box-shadow: none;
        font-weight: 500;
    }

    .app-nav .nav-link.active:hover,
    .app-nav .nav-link.active:focus {
        background: rgba(30, 66, 155, .32);
        color: #fff;
    }

    .app-nav .nav-link.active .app-nav-icon { color: #fff; }
    .app-nav-icon { color: #a0aab5; }

    .app-sidebar-footer {
        background: var(--gips-sidebar-dark);
        border-top-color: rgba(255, 255, 255, .06);
    }

    .app-nav-section {
        padding: .75rem .75rem .3rem;
        list-style: none;
        font-size: .66rem;
        font-weight: 700;
        letter-spacing: .1em;
        text-transform: uppercase;
        color: #7f8a99;
        opacity: .9;
    }

    /* ── GIPS sidebar profile ────────────────────────────────────────── */
    .app-sidebar-profile {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 20px 16px;
        margin-bottom: 8px;
        background: var(--gips-sidebar-dark);
        border-bottom: 1px solid rgba(255, 255, 255, .06);
    }

    .app-sidebar-profile-avatar {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 42px;
        height: 42px;
        flex: 0 0 auto;
        border-radius: 50%;
        background: linear-gradient(135deg, var(--company-primary) 0%, #4B6FC4 100%);
        color: #fff;
        font-size: 1rem;
        font-weight: 700;
    }

    .app-sidebar-profile-copy { min-width: 0; }

    .app-sidebar-profile-name {
        color: #f8fafc;
        font-size: .875rem;
        font-weight: 600;
        line-height: 1.25;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .app-sidebar-profile-role {
        display: inline-block;
        margin-top: 4px;
        padding: 2px 8px;
        border-radius: 999px;
        background: rgba(30, 66, 155, .55);
        color: #dbeafe;
        font-size: .6rem;
        font-weight: 700;
        letter-spacing: .08em;
        text-transform: uppercase;
    }

    .app-sidebar { --bs-offcanvas-width: 280px; }

    /* ── GIPS topbar ─────────────────────────────────────────────────── */
    .gips-topbar {
        position: sticky;
        top: 0;
        z-index: 1035;
        min-height: 58px;
        background: linear-gradient(120deg, var(--gips-topbar-start) 0%, var(--gips-topbar-end) 100%) !important;
        border-bottom: 2px solid var(--company-primary);
        box-shadow: 0 8px 26px rgba(0, 0, 0, .25);
        color: #f1f5f9;
    }

    .gips-topbar-inner {
        display: flex;
        align-items: center;
        gap: 10px;
        min-height: 58px;
        padding: .35rem 1rem;
    }

    .gips-topbar-brand {
        display: inline-flex;
        align-items: center;
        gap: 10px;
        color: #f1f5f9 !important;
        font-size: 1rem;
        font-weight: 700;
        letter-spacing: .03em;
        text-decoration: none;
    }

    .gips-topbar-brand img {
        width: 32px;
        height: 32px;
        object-fit: contain;
        border-radius: 8px;
        background: #fff;
        padding: 2px;
    }

    .gips-topbar-brand-name {
        font-size: .78rem;
        font-weight: 700;
        letter-spacing: .045em;
        line-height: 1.25;
        white-space: nowrap;
    }

    .gips-topbar-brand-short {
        font-size: .95rem;
        font-weight: 700;
    }

    .gips-topbar .gips-icon-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 40px;
        height: 40px;
        padding: 0;
        border-radius: 12px;
        border: 1px solid rgba(255, 255, 255, .1);
        background: rgba(255, 255, 255, .04);
        color: #d5dde6;
    }

    .gips-topbar .gips-icon-btn:hover,
    .gips-topbar .gips-icon-btn:focus-visible {
        background: rgba(255, 255, 255, .12);
        color: #fff;
    }

    .gips-topbar .gips-user-trigger {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 4px 8px 4px 4px;
        border: 1px solid rgba(255, 255, 255, .1);
        border-radius: 12px;
        background: rgba(255, 255, 255, .04);
        color: #e5e7eb;
        font-size: .82rem;
    }

    .gips-topbar .gips-user-trigger:hover,
    .gips-topbar .gips-user-trigger.show {
        background: rgba(255, 255, 255, .12);
        color: #fff;
    }

    .gips-topbar .gips-avatar {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 30px;
        height: 30px;
        border-radius: 50%;
        background: linear-gradient(135deg, var(--company-primary) 0%, #4B6FC4 100%);
        color: #fff;
        font-size: .78rem;
        font-weight: 700;
    }

    .gips-topbar .dropdown-menu {
        border-color: var(--gips-card-border);
        border-radius: 12px;
        box-shadow: 0 12px 30px rgba(15, 23, 42, .18);
        min-width: 220px;
    }

    .gips-topbar .dropdown-menu .gips-menu-header {
        padding: .55rem .9rem .35rem;
    }

    .gips-topbar .dropdown-menu .gips-menu-name {
        font-size: .9rem;
        font-weight: 600;
        color: var(--gips-text);
    }

    .gips-topbar .dropdown-menu .gips-menu-role {
        font-size: .68rem;
        text-transform: uppercase;
        letter-spacing: .08em;
        color: var(--gips-muted);
    }

    .gips-topbar .dropdown-item { font-size: .875rem; padding: .5rem .9rem; }
    .gips-topbar .dropdown-item:active { background: #f3f4f6; color: #111827; }
    .gips-topbar .gips-menu-logout { color: #b91c1c; }
    .gips-topbar .gips-menu-logout:hover { background: #fef2f2; color: #991b1b; }

    @media (min-width: 992px) {
        .app-shell { min-height: calc(100vh - 58px); }

        .app-sidebar-wrapper {
            top: 58px;
            height: calc(100vh - 58px);
            min-height: calc(100vh - 58px);
            flex: 0 0 260px;
            width: 260px;
            max-width: 260px;
            overflow: hidden;
            transition: flex-basis .2s ease, width .2s ease, max-width .2s ease;
        }

        .app-sidebar-inner {
            width: 260px;
            min-width: 260px;
        }

        /* GIPS hamburger hides the whole navigation column. */
        html[data-sidebar="hidden"] .app-sidebar-wrapper {
            flex-basis: 0;
            width: 0;
            max-width: 0;
        }
    }

    @media (max-width: 991.98px) {
        .gips-topbar { position: sticky; }
        .gips-topbar-inner { padding: .35rem .75rem; }
    }

    /* Dashboard surfaces need the same dark-mode parity as the shared cards. */
    html[data-bs-theme="dark"] .dashboard-header-title { color: #e2e8f0; }
    html[data-bs-theme="dark"] .dashboard-header-subtitle,
    html[data-bs-theme="dark"] .dashboard-header-description { color: #94a3b8; }

    html[data-bs-theme="dark"] .kpi-hero {
        background: #111c2e;
        border-color: #253247;
        border-left-color: var(--company-primary);
    }

    html[data-bs-theme="dark"] .kpi-hero-label,
    html[data-bs-theme="dark"] .kpi-hero-value { color: #e2e8f0; }
    html[data-bs-theme="dark"] .kpi-hero-meta,
    html[data-bs-theme="dark"] .kpi-hero-caption { color: #94a3b8; }
    html[data-bs-theme="dark"] .kpi-hero-link { color: #93c5fd; }
    html[data-bs-theme="dark"] .kpi-hero-link.subtle { color: #cbd5e1; }

    html[data-bs-theme="dark"] .panel-card,
    html[data-bs-theme="dark"] .cohort-card {
        background: #111c2e;
        border-color: #253247;
    }

    html[data-bs-theme="dark"] .panel-card-header {
        background: #111c2e;
        border-bottom-color: #253247;
    }

    html[data-bs-theme="dark"] .panel-card-title,
    html[data-bs-theme="dark"] .cohort-label { color: #e2e8f0; }
    html[data-bs-theme="dark"] .panel-card-subtitle,
    html[data-bs-theme="dark"] .cohort-meta,
    html[data-bs-theme="dark"] .cohort-link { color: #94a3b8; }
    html[data-bs-theme="dark"] .cohort-count { color: #e2e8f0; }
    html[data-bs-theme="dark"] .dashboard-year-toolbar label { color: #94a3b8; }

    html[data-bs-theme="dark"] .year-chart-footer,
    html[data-bs-theme="dark"] .trend-summary-label { color: #94a3b8; }
    html[data-bs-theme="dark"] .trend-summary-value { color: #e2e8f0; }
</style>
