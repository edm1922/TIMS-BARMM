    <style>
        .sync-console {
            --sync-primary: #1E429B;
            --sync-primary-strong: #16357C;
            --sync-text: #0f172a;
            --sync-muted: #64748b;
            --sync-border: #e5e7eb;
            --sync-surface: #ffffff;
            --sync-canvas: #f6f7fb;
            --sync-success: #15803d;
            --sync-success-soft: #dcfce7;
            --sync-warning: #92400e;
            --sync-warning-soft: #fef3c7;
            --sync-danger: #b91c1c;
            --sync-danger-soft: #fee2e2;
            --sync-info: #0369a1;
            --sync-info-soft: #e0f2fe;
            --sync-radius: 12px;
            --sync-shadow: 0 1px 2px rgba(15, 23, 42, .04), 0 1px 3px rgba(15, 23, 42, .06);
            color: var(--sync-text);
            font-size: 14px;
        }

        .sync-console .sync-page-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 20px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }

        .sync-console .sync-page-title {
            margin: 0 0 4px;
            font-size: 30px;
            font-weight: 700;
            letter-spacing: -.02em;
            line-height: 1.15;
        }

        .sync-console .sync-page-subtitle {
            margin: 0;
            color: var(--sync-muted);
            font-size: 14px;
            max-width: 62ch;
        }

        .sync-console .sync-meta {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 8px 14px;
            margin-top: 10px;
            font-size: 13px;
            color: var(--sync-muted);
        }

        .sync-console .sync-meta strong { color: var(--sync-text); font-weight: 600; }

        .sync-console .sync-page-actions {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 8px;
        }

        .sync-console .sync-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-height: 42px;
            padding: 0 16px;
            border-radius: 8px;
            border: 1px solid transparent;
            font-size: 14px;
            font-weight: 600;
            line-height: 1;
            text-decoration: none;
            transition: background-color .15s ease, border-color .15s ease, color .15s ease;
        }

        .sync-console .sync-btn--primary { background: var(--sync-primary); color: #fff; }
        .sync-console .sync-btn--primary:hover { background: var(--sync-primary-strong); color: #fff; }
        .sync-console .sync-btn--secondary {
            background: var(--sync-surface);
            border-color: var(--sync-border);
            color: var(--sync-text);
        }
        .sync-console .sync-btn--secondary:hover { border-color: #cbd5e1; background: #f6f7fb; }
        .sync-console .sync-btn--warning {
            background: #fff7ed;
            border-color: #fed7aa;
            color: var(--sync-warning);
        }
        .sync-console .sync-btn--warning:hover { background: #ffedd5; }
        .sync-console .sync-btn--small { min-height: 34px; padding: 0 12px; font-size: 13px; }
        .sync-console .sync-btn:disabled { opacity: .6; cursor: not-allowed; }
        .sync-console .sync-btn:focus-visible,
        .sync-console .sync-copy:focus-visible,
        .sync-console summary:focus-visible {
            outline: 2px solid var(--sync-primary);
            outline-offset: 2px;
        }

        .sync-console .sync-alerts { display: grid; gap: 10px; margin-bottom: 18px; }
        .sync-console .sync-alerts .alert { margin: 0; border-radius: 10px; font-size: 13.5px; }
        .sync-console .sync-alerts .alert > .fw-semibold { font-size: 14px; }

        .sync-console .sync-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(225px, 1fr));
            gap: 16px;
            margin-bottom: 20px;
        }

        .sync-console .sync-card {
            background: var(--sync-surface);
            border: 1px solid var(--sync-border);
            border-radius: var(--sync-radius);
            box-shadow: var(--sync-shadow);
        }

        .sync-console .sync-stat { padding: 20px; display: flex; flex-direction: column; gap: 6px; }
        .sync-console .sync-stat-label { font-size: 12.5px; font-weight: 600; color: var(--sync-muted); }
        .sync-console .sync-stat-value {
            font-size: 28px;
            font-weight: 700;
            line-height: 1.1;
            letter-spacing: -.02em;
            font-variant-numeric: tabular-nums;
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .sync-console .sync-stat-note { font-size: 12.5px; color: var(--sync-muted); }
        .sync-console .sync-stat--ok { border-left: 3px solid var(--sync-success); }
        .sync-console .sync-stat--warning { border-left: 3px solid #d97706; }
        .sync-console .sync-stat--danger { border-left: 3px solid var(--sync-danger); }

        .sync-console .sync-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            min-height: 26px;
            padding: 0 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 600;
            white-space: nowrap;
        }
        .sync-console .sync-pill--ok { background: var(--sync-success-soft); color: var(--sync-success); }
        .sync-console .sync-pill--warning { background: var(--sync-warning-soft); color: var(--sync-warning); }
        .sync-console .sync-pill--danger { background: var(--sync-danger-soft); color: var(--sync-danger); }
        .sync-console .sync-pill--info { background: var(--sync-info-soft); color: var(--sync-info); }
        .sync-console .sync-pill--neutral { background: #f1f5f9; color: #475569; }
        .sync-console .sync-pill__dot { width: 7px; height: 7px; border-radius: 50%; background: currentColor; }

        .sync-console .sync-grid { display: grid; gap: 16px; margin-bottom: 20px; }
        .sync-console .sync-grid--main { grid-template-columns: minmax(0, 7fr) minmax(0, 5fr); }
        @media (max-width: 1199.98px) { .sync-console .sync-grid--main { grid-template-columns: 1fr; } }

        .sync-console .sync-section-head {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 16px;
            flex-wrap: wrap;
            padding: 18px 20px;
            border-bottom: 1px solid var(--sync-border);
        }
        .sync-console .sync-section-title { margin: 0; font-size: 17px; font-weight: 600; letter-spacing: -.01em; }
        .sync-console .sync-section-subtitle { margin: 2px 0 0; font-size: 12.5px; color: var(--sync-muted); }
        .sync-console .sync-body { padding: 20px; }
        .sync-console .sync-body--flush { padding: 0; }
        .sync-console .sync-body--divided { padding: 18px 20px; border-top: 1px solid var(--sync-border); }

        .sync-console .sync-kv { display: grid; gap: 0; margin: 0; }
        .sync-console .sync-kv-row {
            display: grid;
            grid-template-columns: minmax(130px, 40%) minmax(0, 1fr);
            align-items: baseline;
            gap: 12px;
            padding: 9px 0;
            border-bottom: 1px solid #f1f5f9;
            font-size: 13.5px;
        }
        .sync-console .sync-kv-row:last-child { border-bottom: 0; }
        .sync-console .sync-kv-label { color: var(--sync-muted); font-weight: 500; }
        .sync-console .sync-kv-value { min-width: 0; overflow-wrap: anywhere; }
        .sync-console .sync-kv-value code {
            font-size: 12.5px;
            color: #1e293b;
            background: #f6f7fb;
            border: 1px solid #eef2f7;
            border-radius: 5px;
            padding: 1px 5px;
        }

        .sync-console .sync-subhead { font-size: 15px; font-weight: 600; margin: 0 0 4px; }
        .sync-console .sync-help { font-size: 12.5px; color: var(--sync-muted); margin: 0 0 12px; max-width: 78ch; }

        .sync-console .sync-form-row { display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end; }
        .sync-console .sync-field { flex: 1 1 240px; min-width: 0; }
        .sync-console .sync-field label { display: block; font-size: 12.5px; font-weight: 600; margin-bottom: 5px; }
        .sync-console .sync-input {
            width: 100%;
            min-height: 42px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            padding: 8px 12px;
            font-size: 14px;
            background: #fff;
            color: var(--sync-text);
        }
        .sync-console .sync-input:focus { outline: 2px solid rgba(30, 66, 155, .25); border-color: var(--sync-primary); }
        .sync-console .sync-input.is-invalid { border-color: var(--sync-danger); }
        .sync-console .sync-field .invalid-feedback { font-size: 12px; }

        .sync-console details.sync-recovery { border-top: 1px solid var(--sync-border); }
        .sync-console details.sync-recovery > summary {
            cursor: pointer;
            list-style: none;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 14px 20px;
            font-size: 13.5px;
            font-weight: 600;
        }
        .sync-console details.sync-recovery > summary::-webkit-details-marker { display: none; }
        .sync-console details.sync-recovery > summary::after {
            content: '+';
            color: var(--sync-muted);
            font-size: 16px;
            line-height: 1;
        }
        .sync-console details.sync-recovery[open] > summary::after { content: '-'; }
        .sync-console details.sync-recovery > .sync-recovery-body { padding: 0 20px 18px; }
        .sync-console details.sync-recovery--attention > summary { color: var(--sync-warning); }

        .sync-console .sync-diag { display: grid; }
        .sync-console .sync-diag-row {
            display: grid;
            grid-template-columns: minmax(140px, 1.1fr) minmax(0, 1.6fr) auto;
            gap: 12px;
            align-items: center;
            padding: 10px 20px;
            border-bottom: 1px solid #f1f5f9;
            font-size: 13px;
        }
        .sync-console .sync-diag-row:last-child { border-bottom: 0; }
        .sync-console .sync-diag-label { font-weight: 600; }
        .sync-console .sync-diag-detail { color: var(--sync-muted); min-width: 0; overflow-wrap: anywhere; }

        .sync-console .sync-audit { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; }
        .sync-console .sync-audit-value { font-size: 20px; font-weight: 700; line-height: 1.2; font-variant-numeric: tabular-nums; }
        .sync-console .sync-audit-label { font-size: 12px; color: var(--sync-muted); }
        @media (max-width: 575.98px) { .sync-console .sync-audit { grid-template-columns: 1fr; } }

        .sync-console .sync-network-grid {
            display: grid;
            grid-template-columns: minmax(0, 7fr) minmax(220px, 5fr);
            gap: 24px;
            align-items: start;
        }
        @media (max-width: 991.98px) { .sync-console .sync-network-grid { grid-template-columns: 1fr; } }
        .sync-console .sync-url-group { display: flex; gap: 8px; flex-wrap: wrap; }
        .sync-console .sync-url-group .sync-input { flex: 1 1 260px; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 13px; }
        .sync-console .sync-qr { text-align: center; }
        .sync-console .sync-qr canvas { width: 160px; height: 160px; border: 1px solid var(--sync-border); border-radius: 10px; padding: 4px; background: #fff; }
        .sync-console .sync-qr-url {
            margin-top: 8px;
            font-size: 12px;
            color: var(--sync-muted);
            overflow-wrap: anywhere;
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
        }
        .sync-console .sync-callout {
            display: flex;
            gap: 10px;
            padding: 12px 14px;
            border-radius: 10px;
            font-size: 12.5px;
            background: var(--sync-warning-soft);
            color: #78350f;
            border: 1px solid #fde68a;
        }

        .sync-console .sync-ops-table { width: 100%; margin: 0; border-collapse: collapse; font-size: 13px; }
        .sync-console .sync-ops-table thead th {
            position: sticky;
            top: 0;
            z-index: 1;
            background: #f6f7fb;
            color: var(--sync-muted);
            font-size: 12px;
            font-weight: 600;
            text-align: left;
            padding: 11px 16px;
            border-bottom: 1px solid var(--sync-border);
            white-space: nowrap;
        }
        .sync-console .sync-ops-table tbody td {
            padding: 12px 16px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: top;
        }
        .sync-console .sync-ops-table tbody tr:last-child td { border-bottom: 0; }
        .sync-console .sync-ops-table tbody tr:hover { background: #f6f7fb; }
        .sync-console .sync-ops-when { white-space: nowrap; color: var(--sync-muted); }
        .sync-console .sync-ops-entity { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; }
        .sync-console .sync-ops-kind { color: var(--sync-muted); font-size: 12.5px; }
        .sync-console .sync-ops-uuid {
            display: inline-block;
            max-width: 15ch;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            vertical-align: bottom;
            font-size: 12px;
            color: #334155;
            background: #f6f7fb;
            border: 1px solid #eef2f7;
            border-radius: 5px;
            padding: 2px 5px;
        }
        .sync-console .sync-copy {
            border: 0;
            background: transparent;
            color: var(--sync-primary);
            font-size: 11.5px;
            font-weight: 600;
            padding: 2px 4px;
            border-radius: 4px;
            cursor: pointer;
        }
        .sync-console .sync-copy:hover { background: #eff6ff; }
        .sync-console .sync-ops-num { font-variant-numeric: tabular-nums; color: var(--sync-muted); }
        .sync-console .sync-ops-error { color: var(--sync-danger); font-size: 12.5px; max-width: 46ch; overflow-wrap: anywhere; }
        .sync-console .sync-ops-error span { color: var(--sync-muted); }
        .sync-console .sync-empty { padding: 48px 20px; text-align: center; }
        .sync-console .sync-empty-title { font-size: 14px; font-weight: 600; margin-bottom: 4px; }
        .sync-console .sync-empty-note { font-size: 12.5px; color: var(--sync-muted); }

        .sync-console .sync-progress {
            height: 8px;
            border-radius: 999px;
            overflow: hidden;
            background: #f1f5f9;
        }

        @media (max-width: 767.98px) {
            .sync-console .sync-page-title { font-size: 24px; }
            .sync-console .sync-page-actions { width: 100%; }
            .sync-console .sync-page-actions form { display: flex; width: 100%; }
            .sync-console .sync-page-actions .sync-btn { flex: 1 1 100%; width: 100%; }
            .sync-console .sync-kv-row { grid-template-columns: 1fr; gap: 2px; }
            .sync-console .sync-diag-row { grid-template-columns: 1fr auto; padding: 12px 16px; }
            .sync-console .sync-diag-detail { grid-column: 1 / -1; }
            .sync-console .sync-section-head { padding: 16px; }
            .sync-console .sync-body { padding: 16px; }
            .sync-console .sync-body--divided { padding: 16px; }
            .sync-console details.sync-recovery > summary { padding: 14px 16px; }
            .sync-console details.sync-recovery > .sync-recovery-body { padding: 0 16px 16px; }
            .sync-console .sync-ops-table thead th,
            .sync-console .sync-ops-table tbody td { padding: 10px 12px; }
            .sync-console .sync-ops-uuid { max-width: 12ch; }
        }

        @media (prefers-reduced-motion: reduce) {
            .sync-console * { transition: none !important; }
        }
    </style>
