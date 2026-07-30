{{--
    User Performance Dashboard — Phases 0-6.

    Phase 6: $fragmentMode (bool) toggles between the full page layout
    (layouts.admin) and the plain layout (layouts.plain, no chrome). The
    AJAX fragment endpoint sets fragmentMode=true so the response
    contains only the #perf-dashboard container + CSS + scripts. The
    caller parses the HTML and swaps just the dashboard into the live
    page without a full reload.
--}}
@extends(($fragmentMode ?? false) ? 'layouts.plain' : 'layouts.admin')

@push('css')
<style>
    /* ============================================================
       User Performance Dashboard — Phase 1 visual system
       (scoped under #perf-dashboard to avoid bleeding into Bootstrap)
       ============================================================ */

    /* Color palette — modern gradient-driven, no boring solid backgrounds */
    #perf-dashboard {
        --perf-bg:           #f8fafc;
        --perf-card:         #ffffff;
        --perf-text:         #0f172a;
        --perf-muted:        #64748b;
        --perf-border:       #e2e8f0;
        --perf-primary:      #4f46e5;     /* indigo-600 */
        --perf-primary-2:    #7c3aed;     /* violet-600 */
        --perf-success:      #10b981;     /* emerald-500 */
        --perf-success-2:    #059669;     /* emerald-600 */
        --perf-warning:      #f59e0b;     /* amber-500 */
        --perf-danger:       #ef4444;     /* red-500 */
        --perf-info:         #0ea5e9;     /* sky-500 */
        --perf-pink:         #ec4899;     /* pink-500 */
        --perf-shadow-sm:    0 1px 2px rgba(15, 23, 42, 0.05);
        --perf-shadow:       0 10px 30px -10px rgba(15, 23, 42, 0.18);
        --perf-shadow-lg:    0 20px 50px -20px rgba(15, 23, 42, 0.25);
    }

    #perf-dashboard .perf-hero {
        background: linear-gradient(120deg, #0f172a 0%, #1e3a8a 45%, #4f46e5 100%);
        color: #fff;
        border-radius: 1.25rem;
        padding: 1.5rem 1.75rem;
        position: relative;
        overflow: hidden;
        box-shadow: var(--perf-shadow);
    }
    #perf-dashboard .perf-hero::before {
        content: '';
        position: absolute;
        top: -60px; right: -60px;
        width: 240px; height: 240px;
        background: radial-gradient(circle, rgba(255,255,255,0.18) 0%, rgba(255,255,255,0) 70%);
        border-radius: 50%;
        pointer-events: none;
    }
    #perf-dashboard .perf-hero::after {
        content: '';
        position: absolute;
        bottom: -80px; left: 30%;
        width: 200px; height: 200px;
        background: radial-gradient(circle, rgba(124,58,237,0.35) 0%, rgba(124,58,237,0) 70%);
        border-radius: 50%;
        pointer-events: none;
    }
    #perf-dashboard .perf-hero h2 {
        font-weight: 800;
        letter-spacing: -0.02em;
        position: relative;
        z-index: 2;
    }
    #perf-dashboard .perf-hero .sub {
        opacity: 0.85;
        font-size: 0.88rem;
        margin-top: 0.35rem;
        position: relative;
        z-index: 2;
    }
    #perf-dashboard .perf-hero .pill {
        background: rgba(255,255,255,0.15);
        border: 1px solid rgba(255,255,255,0.25);
        color: #fff;
        padding: 0.25rem 0.75rem;
        border-radius: 999px;
        font-size: 0.78rem;
        font-weight: 500;
        backdrop-filter: blur(6px);
    }
    #perf-dashboard .perf-hero select.form-select {
        background-color: rgba(255, 255, 255, 0.97);
        border: 0;
        font-weight: 600;
        min-width: 280px;
        box-shadow: 0 4px 20px -4px rgba(0,0,0,0.3);
    }

    /* Period switcher — pill bar */
    #perf-dashboard .perf-period-bar {
        background: #fff;
        border: 1px solid var(--perf-border);
        border-radius: 0.75rem;
        padding: 0.5rem 0.85rem;
        box-shadow: var(--perf-shadow-sm);
    }
    #perf-dashboard .perf-period-bar .btn-period {
        font-size: 0.8rem;
        padding: 0.35rem 0.95rem;
        border-radius: 999px;
        color: #475569;
        text-decoration: none;
        font-weight: 600;
        transition: all 0.2s;
        display: inline-block;
    }
    #perf-dashboard .perf-period-bar .btn-period:hover {
        background: #f1f5f9;
        color: #0f172a;
        transform: translateY(-1px);
    }
    #perf-dashboard .perf-period-bar .btn-period.active {
        background: linear-gradient(135deg, var(--perf-primary), var(--perf-primary-2));
        color: #fff;
        box-shadow: 0 4px 12px -2px rgba(79, 70, 229, 0.4);
    }

    /* KPI cards — the headline visual. Each has a gradient strip + sparkline. */
    #perf-dashboard .kpi-card {
        position: relative;
        background: var(--perf-card);
        border: 1px solid var(--perf-border);
        border-radius: 0.9rem;
        padding: 1.1rem 1.25rem;
        overflow: hidden;
        height: 100%;
        transition: transform 0.25s ease, box-shadow 0.25s ease;
    }
    #perf-dashboard .kpi-card:hover {
        transform: translateY(-3px);
        box-shadow: var(--perf-shadow);
    }
    #perf-dashboard .kpi-card::before {
        content: '';
        position: absolute;
        top: 0; left: 0; right: 0;
        height: 4px;
        background: var(--accent, var(--perf-primary));
    }
    #perf-dashboard .kpi-card .kpi-icon {
        width: 38px; height: 38px;
        border-radius: 0.65rem;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1rem;
        color: #fff;
        background: var(--accent, var(--perf-primary));
        box-shadow: 0 4px 10px -2px var(--accent, var(--perf-primary));
        margin-bottom: 0.65rem;
    }
    #perf-dashboard .kpi-card .kpi-label {
        color: var(--perf-muted);
        font-size: 0.78rem;
        font-weight: 500;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        margin-bottom: 0.15rem;
    }
    #perf-dashboard .kpi-card .kpi-value {
        font-size: 1.6rem;
        font-weight: 800;
        color: var(--perf-text);
        letter-spacing: -0.02em;
        line-height: 1.15;
    }
    #perf-dashboard .kpi-card .kpi-sub {
        font-size: 0.78rem;
        color: var(--perf-muted);
        margin-top: 0.2rem;
    }
    #perf-dashboard .kpi-card .kpi-delta {
        display: inline-flex;
        align-items: center;
        gap: 0.2rem;
        font-size: 0.78rem;
        font-weight: 700;
        padding: 0.15rem 0.5rem;
        border-radius: 999px;
        margin-top: 0.4rem;
    }
    #perf-dashboard .kpi-delta.up   { background: #d1fae5; color: #065f46; }
    #perf-dashboard .kpi-delta.down { background: #fee2e2; color: #991b1b; }
    #perf-dashboard .kpi-delta.flat { background: #f1f5f9; color: #475569; }

    #perf-dashboard .kpi-card .spark {
        position: absolute;
        bottom: 0; right: 0; left: 0;
        height: 38px;
        opacity: 0.9;
    }

    /* Section heading */
    #perf-dashboard .section-h {
        font-size: 0.95rem;
        font-weight: 700;
        color: var(--perf-text);
        margin: 0 0 0.65rem 0;
        display: flex;
        align-items: center;
        gap: 0.55rem;
    }
    #perf-dashboard .section-h .bar {
        width: 4px;
        height: 18px;
        background: linear-gradient(180deg, var(--perf-primary), var(--perf-primary-2));
        border-radius: 2px;
    }

    /* Chart cards */
    #perf-dashboard .chart-card {
        background: var(--perf-card);
        border: 1px solid var(--perf-border);
        border-radius: 0.9rem;
        padding: 1.1rem 1.25rem 1rem;
        height: 100%;
        box-shadow: var(--perf-shadow-sm);
    }
    #perf-dashboard .chart-card .chart-title {
        font-size: 0.85rem;
        font-weight: 700;
        color: var(--perf-text);
        margin: 0 0 0.15rem 0;
        display: flex;
        align-items: center;
        gap: 0.4rem;
    }
    #perf-dashboard .chart-card .chart-sub {
        font-size: 0.74rem;
        color: var(--perf-muted);
        margin-bottom: 0.75rem;
    }
    #perf-dashboard .chart-card .chart-wrap {
        position: relative;
    }

    /* Product group horizontal bars */
    #perf-dashboard .pg-row {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        margin-bottom: 0.7rem;
    }
    #perf-dashboard .pg-row:last-child { margin-bottom: 0; }
    #perf-dashboard .pg-row .pg-name {
        width: 38%;
        font-size: 0.82rem;
        font-weight: 600;
        color: var(--perf-text);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    #perf-dashboard .pg-row .pg-track {
        flex: 1;
        height: 26px;
        background: #f1f5f9;
        border-radius: 6px;
        overflow: hidden;
        position: relative;
    }
    #perf-dashboard .pg-row .pg-fill {
        height: 100%;
        border-radius: 6px;
        background: linear-gradient(90deg, var(--perf-primary), var(--perf-primary-2));
        position: relative;
        animation: pg-grow 0.9s cubic-bezier(0.16, 1, 0.3, 1) both;
        display: flex;
        align-items: center;
        justify-content: flex-end;
        padding-right: 0.5rem;
        color: #fff;
        font-size: 0.72rem;
        font-weight: 700;
    }
    @keyframes pg-grow {
        from { width: 0 !important; }
    }
    #perf-dashboard .pg-row .pg-share {
        font-size: 0.78rem;
        color: var(--perf-muted);
        font-weight: 600;
        width: 50px;
        text-align: right;
    }

    /* Customer leaderboard */
    #perf-dashboard .cust-row {
        display: flex;
        align-items: center;
        gap: 0.85rem;
        padding: 0.6rem 0.4rem;
        border-bottom: 1px dashed #e2e8f0;
    }
    #perf-dashboard .cust-row:last-child { border-bottom: 0; }
    #perf-dashboard .cust-rank {
        width: 30px; height: 30px;
        border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
        font-weight: 800; font-size: 0.78rem;
        background: #f1f5f9;
        color: #475569;
        flex-shrink: 0;
    }
    #perf-dashboard .cust-rank.r1 { background: linear-gradient(135deg, #fbbf24, #f59e0b); color: #fff; box-shadow: 0 4px 12px -3px #f59e0b; }
    #perf-dashboard .cust-rank.r2 { background: linear-gradient(135deg, #cbd5e1, #94a3b8); color: #fff; }
    #perf-dashboard .cust-rank.r3 { background: linear-gradient(135deg, #fdba74, #fb923c); color: #fff; }
    #perf-dashboard .cust-info { flex: 1; min-width: 0; }
    #perf-dashboard .cust-name {
        font-weight: 700; color: var(--perf-text);
        font-size: 0.88rem;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    #perf-dashboard .cust-meta {
        font-size: 0.74rem;
        color: var(--perf-muted);
        margin-top: 0.1rem;
    }
    #perf-dashboard .cust-progress {
        height: 6px;
        background: #f1f5f9;
        border-radius: 3px;
        overflow: hidden;
        margin-top: 0.35rem;
    }
    #perf-dashboard .cust-progress > div {
        height: 100%;
        background: linear-gradient(90deg, var(--perf-success), var(--perf-info));
        border-radius: 3px;
        animation: pg-grow 0.9s cubic-bezier(0.16, 1, 0.3, 1) both;
    }
    #perf-dashboard .cust-revenue {
        font-weight: 800;
        color: var(--perf-text);
        font-size: 0.92rem;
        text-align: right;
        white-space: nowrap;
    }
    #perf-dashboard .cust-due {
        font-size: 0.7rem;
        color: var(--perf-danger);
        font-weight: 600;
        text-align: right;
        margin-top: 0.1rem;
    }

    /* Acquisition donut + legend */
    #perf-dashboard .acq-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 0.65rem;
    }
    #perf-dashboard .acq-tile {
        border-radius: 0.65rem;
        padding: 0.85rem 0.95rem;
        color: #fff;
        position: relative;
        overflow: hidden;
    }
    #perf-dashboard .acq-tile.new   { background: linear-gradient(135deg, #10b981, #059669); }
    #perf-dashboard .acq-tile.repeat{ background: linear-gradient(135deg, #0ea5e9, #2563eb); }
    #perf-dashboard .acq-tile .lbl  { font-size: 0.74rem; opacity: 0.92; font-weight: 500; }
    #perf-dashboard .acq-tile .val  { font-size: 1.5rem; font-weight: 800; line-height: 1.1; }
    #perf-dashboard .acq-tile .pct  { font-size: 0.72rem; opacity: 0.88; margin-top: 0.1rem; }

    /* Peak day callout */
    #perf-dashboard .peak-card {
        background: linear-gradient(135deg, #fef3c7, #fde68a);
        border: 1px solid #fbbf24;
        border-radius: 0.85rem;
        padding: 1rem 1.15rem;
        display: flex;
        align-items: center;
        gap: 1rem;
        height: 100%;
    }
    #perf-dashboard .peak-card .peak-icon {
        width: 48px; height: 48px;
        border-radius: 12px;
        background: linear-gradient(135deg, #f59e0b, #d97706);
        color: #fff;
        display: flex; align-items: center; justify-content: center;
        font-size: 1.3rem;
        box-shadow: 0 8px 20px -4px rgba(245, 158, 11, 0.5);
        flex-shrink: 0;
    }
    #perf-dashboard .peak-card .peak-label {
        font-size: 0.72rem;
        color: #92400e;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }
    #perf-dashboard .peak-card .peak-value {
        font-size: 1.4rem;
        font-weight: 800;
        color: #78350f;
        line-height: 1.15;
    }
    #perf-dashboard .peak-card .peak-date {
        font-size: 0.78rem;
        color: #92400e;
        font-weight: 600;
    }

    /* Empty state */
    #perf-dashboard .empty-card {
        background: #fff;
        border: 1px dashed #cbd5e1;
        border-radius: 0.85rem;
        padding: 2rem 1.5rem;
        text-align: center;
        color: var(--perf-muted);
    }
    #perf-dashboard .empty-card i { font-size: 2rem; color: #cbd5e1; margin-bottom: 0.5rem; }

    /* Phase-tagged scaffold placeholder (Phase 2-4 placeholders remain) */
    #perf-dashboard .perf-scaffold-card {
        border: 1px dashed #cbd5e1;
        background: repeating-linear-gradient(45deg, #f8fafc, #f8fafc 10px, #f1f5f9 10px, #f1f5f9 20px);
        border-radius: 0.75rem;
        padding: 1.5rem;
        text-align: center;
        color: #64748b;
        min-height: 160px;
        display: flex;
        flex-direction: column;
        justify-content: center;
        align-items: center;
        gap: 0.4rem;
    }
    #perf-dashboard .perf-scaffold-card i { font-size: 1.4rem; color: #94a3b8; }
    #perf-dashboard .perf-scaffold-card .title { font-weight: 600; color: #334155; font-size: 0.92rem; }
    #perf-dashboard .perf-scaffold-card .phase-tag {
        display: inline-block;
        font-size: 0.7rem;
        background: #e0e7ff;
        color: #4338ca;
        padding: 0.15rem 0.5rem;
        border-radius: 999px;
        margin-top: 0.25rem;
        font-weight: 600;
    }

    #perf-dashboard .perf-error {
        background: #fef2f2;
        border: 1px solid #fecaca;
        color: #991b1b;
        border-radius: 0.5rem;
        padding: 1rem;
    }

    /* Number-format helper visuals */
    #perf-dashboard .mono { font-variant-numeric: tabular-nums; }

    /* ============================================================
       PHASE 2 — Collections & Returns visual system
       ============================================================ */

    /* Gauge chart — semicircular collection-rate gauge */
    #perf-dashboard .gauge-card {
        background: var(--perf-card);
        border: 1px solid var(--perf-border);
        border-radius: 0.9rem;
        padding: 1.1rem 1.25rem;
        height: 100%;
        position: relative;
        overflow: hidden;
    }
    #perf-dashboard .gauge-card::before {
        content: '';
        position: absolute;
        top: 0; left: 0; right: 0;
        height: 4px;
        background: linear-gradient(90deg, #0ea5e9, #2563eb);
    }
    #perf-dashboard .gauge-wrap {
        position: relative;
        margin: 0.4rem auto 0.25rem;
        width: 100%;
        max-width: 200px;
        aspect-ratio: 2 / 1.1;
    }
    #perf-dashboard .gauge-wrap canvas {
        width: 100% !important;
        height: 100% !important;
    }
    #perf-dashboard .gauge-readout {
        position: absolute;
        left: 0; right: 0; bottom: 0;
        text-align: center;
        pointer-events: none;
    }
    #perf-dashboard .gauge-readout .gauge-pct {
        font-size: 1.85rem;
        font-weight: 800;
        color: var(--perf-text);
        letter-spacing: -0.02em;
        line-height: 1;
    }
    #perf-dashboard .gauge-readout .gauge-cap {
        font-size: 0.7rem;
        color: var(--perf-muted);
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        margin-top: 0.15rem;
    }
    #perf-dashboard .gauge-target {
        font-size: 0.72rem;
        color: var(--perf-muted);
        text-align: center;
        margin-top: 0.15rem;
    }
    #perf-dashboard .gauge-target .tgt-mark {
        display: inline-block;
        padding: 0.1rem 0.45rem;
        border-radius: 999px;
        font-weight: 700;
        margin-left: 0.2rem;
    }
    #perf-dashboard .gauge-target .tgt-mark.good { background: #d1fae5; color: #065f46; }
    #perf-dashboard .gauge-target .tgt-mark.mid  { background: #fef3c7; color: #92400e; }
    #perf-dashboard .gauge-target .tgt-mark.low  { background: #fee2e2; color: #991b1b; }

    /* Stat tile — colored gradient block (Discount / Return Value / etc.) */
    #perf-dashboard .stat-tile {
        border-radius: 0.9rem;
        padding: 1.1rem 1.25rem;
        color: #fff;
        position: relative;
        overflow: hidden;
        height: 100%;
        transition: transform 0.25s ease, box-shadow 0.25s ease;
    }
    #perf-dashboard .stat-tile:hover {
        transform: translateY(-3px);
        box-shadow: var(--perf-shadow);
    }
    #perf-dashboard .stat-tile::after {
        content: '';
        position: absolute;
        top: -30px; right: -30px;
        width: 120px; height: 120px;
        background: radial-gradient(circle, rgba(255,255,255,0.18) 0%, rgba(255,255,255,0) 70%);
        border-radius: 50%;
        pointer-events: none;
    }
    #perf-dashboard .stat-tile.green   { background: linear-gradient(135deg, #10b981, #047857); }
    #perf-dashboard .stat-tile.amber   { background: linear-gradient(135deg, #f59e0b, #d97706); }
    #perf-dashboard .stat-tile.red     { background: linear-gradient(135deg, #ef4444, #b91c1c); }
    #perf-dashboard .stat-tile.rose    { background: linear-gradient(135deg, #f43f5e, #be123c); }
    #perf-dashboard .stat-tile.indigo  { background: linear-gradient(135deg, #6366f1, #4338ca); }
    #perf-dashboard .stat-tile.sky     { background: linear-gradient(135deg, #0ea5e9, #1d4ed8); }
    #perf-dashboard .stat-tile.violet  { background: linear-gradient(135deg, #8b5cf6, #6d28d9); }
    #perf-dashboard .stat-tile .stat-icon {
        width: 36px; height: 36px;
        border-radius: 0.55rem;
        background: rgba(255,255,255,0.22);
        display: flex; align-items: center; justify-content: center;
        font-size: 0.95rem;
        margin-bottom: 0.5rem;
        backdrop-filter: blur(4px);
    }
    #perf-dashboard .stat-tile .stat-lbl {
        font-size: 0.74rem;
        opacity: 0.92;
        font-weight: 500;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }
    #perf-dashboard .stat-tile .stat-val {
        font-size: 1.6rem;
        font-weight: 800;
        letter-spacing: -0.02em;
        line-height: 1.1;
        margin-top: 0.1rem;
    }
    #perf-dashboard .stat-tile .stat-sub {
        font-size: 0.76rem;
        opacity: 0.88;
        margin-top: 0.2rem;
    }
    #perf-dashboard .stat-tile .stat-delta {
        display: inline-flex;
        align-items: center;
        gap: 0.2rem;
        background: rgba(255,255,255,0.22);
        padding: 0.12rem 0.45rem;
        border-radius: 999px;
        font-size: 0.72rem;
        font-weight: 700;
        margin-top: 0.35rem;
        backdrop-filter: blur(4px);
    }

    /* Aging breakdown — 5 horizontal stacked bars */
    #perf-dashboard .aging-row {
        display: flex;
        align-items: center;
        gap: 0.7rem;
        margin-bottom: 0.55rem;
    }
    #perf-dashboard .aging-row:last-child { margin-bottom: 0; }
    #perf-dashboard .aging-row .aging-lbl {
        width: 90px;
        font-size: 0.78rem;
        font-weight: 600;
        color: var(--perf-text);
        white-space: nowrap;
    }
    #perf-dashboard .aging-row .aging-lbl .dot {
        display: inline-block;
        width: 8px; height: 8px;
        border-radius: 50%;
        margin-right: 0.35rem;
        vertical-align: middle;
    }
    #perf-dashboard .aging-row .aging-track {
        flex: 1;
        height: 22px;
        background: #f1f5f9;
        border-radius: 5px;
        overflow: hidden;
        position: relative;
    }
    #perf-dashboard .aging-row .aging-fill {
        height: 100%;
        border-radius: 5px;
        animation: pg-grow 0.9s cubic-bezier(0.16, 1, 0.3, 1) both;
        display: flex;
        align-items: center;
        justify-content: flex-end;
        padding-right: 0.4rem;
        color: #fff;
        font-size: 0.7rem;
        font-weight: 700;
        min-width: 30px;
    }
    #perf-dashboard .aging-row .aging-val {
        width: 90px;
        font-size: 0.82rem;
        font-weight: 700;
        color: var(--perf-text);
        text-align: right;
        font-variant-numeric: tabular-nums;
        white-space: nowrap;
    }

    /* Return-reasons bar list */
    #perf-dashboard .reason-row {
        display: flex;
        align-items: center;
        gap: 0.7rem;
        margin-bottom: 0.55rem;
    }
    #perf-dashboard .reason-row:last-child { margin-bottom: 0; }
    #perf-dashboard .reason-row .reason-lbl {
        flex: 1;
        font-size: 0.82rem;
        font-weight: 600;
        color: var(--perf-text);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    #perf-dashboard .reason-row .reason-lbl .num {
        display: inline-block;
        width: 22px; height: 22px;
        border-radius: 50%;
        background: #fee2e2;
        color: #991b1b;
        text-align: center;
        line-height: 22px;
        font-size: 0.7rem;
        font-weight: 800;
        margin-right: 0.4rem;
    }
    #perf-dashboard .reason-row .reason-track {
        width: 38%;
        height: 22px;
        background: #f1f5f9;
        border-radius: 5px;
        overflow: hidden;
    }
    #perf-dashboard .reason-row .reason-fill {
        height: 100%;
        border-radius: 5px;
        animation: pg-grow 0.9s cubic-bezier(0.16, 1, 0.3, 1) both;
    }
    #perf-dashboard .reason-row .reason-meta {
        font-size: 0.74rem;
        color: var(--perf-muted);
        font-weight: 600;
        white-space: nowrap;
        width: 80px;
        text-align: right;
    }

    /* Payment-mode mix donut + legend */
    #perf-dashboard .pmix-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1rem;
        align-items: center;
    }
    #perf-dashboard .pmix-donut {
        position: relative;
        width: 100%;
        max-width: 160px;
        margin: 0 auto;
        aspect-ratio: 1;
    }
    #perf-dashboard .pmix-center {
        position: absolute;
        top: 50%; left: 50%;
        transform: translate(-50%, -50%);
        text-align: center;
        pointer-events: none;
    }
    #perf-dashboard .pmix-center .pmix-total {
        font-size: 1.35rem;
        font-weight: 800;
        color: var(--perf-text);
        line-height: 1;
    }
    #perf-dashboard .pmix-center .pmix-cap {
        font-size: 0.66rem;
        color: var(--perf-muted);
        text-transform: uppercase;
        letter-spacing: 0.06em;
        margin-top: 0.15rem;
    }
    #perf-dashboard .pmix-legend {
        display: flex;
        flex-direction: column;
        gap: 0.4rem;
    }
    #perf-dashboard .pmix-legend .pmix-item {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        font-size: 0.78rem;
    }
    #perf-dashboard .pmix-legend .pmix-item .sw {
        width: 10px; height: 10px;
        border-radius: 3px;
        flex-shrink: 0;
    }
    #perf-dashboard .pmix-legend .pmix-item .nm {
        flex: 1;
        color: var(--perf-text);
        font-weight: 600;
    }
    #perf-dashboard .pmix-legend .pmix-item .pc {
        font-weight: 700;
        color: var(--perf-muted);
        font-variant-numeric: tabular-nums;
    }

    /* Compact KPI card variant for the collections row */
    #perf-dashboard .kpi-card.compact { padding: 0.9rem 1.05rem; }
    #perf-dashboard .kpi-card.compact .kpi-value { font-size: 1.35rem; }
    #perf-dashboard .kpi-card.compact .kpi-icon { width: 32px; height: 32px; font-size: 0.9rem; }

    /* ============================================================
       PHASE 3 — How You Work visual system
       ============================================================ */

    /* Velocity tile — gradient stat with a mini progress arc */
    #perf-dashboard .vel-tile {
        background: var(--perf-card);
        border: 1px solid var(--perf-border);
        border-radius: 0.9rem;
        padding: 1.1rem 1.25rem;
        height: 100%;
        position: relative;
        overflow: hidden;
        transition: transform 0.25s ease, box-shadow 0.25s ease;
    }
    #perf-dashboard .vel-tile:hover {
        transform: translateY(-3px);
        box-shadow: var(--perf-shadow);
    }
    #perf-dashboard .vel-tile::before {
        content: '';
        position: absolute;
        top: 0; left: 0; bottom: 0;
        width: 4px;
        background: var(--vaccent, linear-gradient(180deg, #4f46e5, #7c3aed));
    }
    #perf-dashboard .vel-tile .vel-icon {
        width: 36px; height: 36px;
        border-radius: 0.55rem;
        background: var(--vaccent, linear-gradient(135deg, #4f46e5, #7c3aed));
        color: #fff;
        display: flex; align-items: center; justify-content: center;
        font-size: 0.95rem;
        margin-bottom: 0.55rem;
        box-shadow: 0 4px 12px -3px rgba(15, 23, 42, 0.18);
    }
    #perf-dashboard .vel-tile .vel-label {
        font-size: 0.74rem;
        color: var(--perf-muted);
        font-weight: 500;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }
    #perf-dashboard .vel-tile .vel-value {
        font-size: 1.55rem;
        font-weight: 800;
        color: var(--perf-text);
        letter-spacing: -0.02em;
        line-height: 1.1;
        margin-top: 0.1rem;
    }
    #perf-dashboard .vel-tile .vel-value .unit {
        font-size: 0.85rem;
        font-weight: 600;
        color: var(--perf-muted);
        margin-left: 0.15rem;
    }
    #perf-dashboard .vel-tile .vel-sub {
        font-size: 0.76rem;
        color: var(--perf-muted);
        margin-top: 0.2rem;
    }
    #perf-dashboard .vel-tile .vel-bar {
        margin-top: 0.55rem;
        height: 5px;
        background: #f1f5f9;
        border-radius: 3px;
        overflow: hidden;
    }
    #perf-dashboard .vel-tile .vel-bar > div {
        height: 100%;
        background: var(--vaccent, linear-gradient(90deg, #4f46e5, #7c3aed));
        border-radius: 3px;
        animation: pg-grow 0.9s cubic-bezier(0.16, 1, 0.3, 1) both;
    }

    /* Work-pattern histogram card */
    #perf-dashboard .hist-card {
        background: var(--perf-card);
        border: 1px solid var(--perf-border);
        border-radius: 0.9rem;
        padding: 1.1rem 1.25rem 1rem;
        height: 100%;
        box-shadow: var(--perf-shadow-sm);
    }
    #perf-dashboard .hist-card .hist-head {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 0.5rem;
    }
    #perf-dashboard .hist-card .hist-title {
        font-size: 0.85rem;
        font-weight: 700;
        color: var(--perf-text);
        display: flex; align-items: center; gap: 0.4rem;
    }
    #perf-dashboard .hist-card .hist-sub {
        font-size: 0.74rem;
        color: var(--perf-muted);
        margin-bottom: 0.5rem;
    }
    #perf-dashboard .hist-card .peak-badge {
        background: linear-gradient(135deg, #fbbf24, #f59e0b);
        color: #fff;
        font-size: 0.7rem;
        font-weight: 700;
        padding: 0.2rem 0.55rem;
        border-radius: 999px;
        white-space: nowrap;
        box-shadow: 0 4px 10px -3px rgba(245, 158, 11, 0.45);
    }
    #perf-dashboard .hist-card .hist-wrap {
        position: relative;
        height: 220px;
    }

    /* Pipeline snapshot list */
    #perf-dashboard .pipe-item {
        display: flex;
        align-items: center;
        gap: 0.85rem;
        padding: 0.7rem 0.5rem;
        border-bottom: 1px dashed #e2e8f0;
    }
    #perf-dashboard .pipe-item:last-child { border-bottom: 0; }
    #perf-dashboard .pipe-item .pipe-icon {
        width: 38px; height: 38px;
        border-radius: 0.55rem;
        display: flex; align-items: center; justify-content: center;
        font-size: 0.95rem;
        color: #fff;
        flex-shrink: 0;
    }
    #perf-dashboard .pipe-item .pipe-icon.amber { background: linear-gradient(135deg, #f59e0b, #d97706); box-shadow: 0 4px 10px -3px rgba(245, 158, 11, 0.4); }
    #perf-dashboard .pipe-item .pipe-icon.blue  { background: linear-gradient(135deg, #3b82f6, #1d4ed8); box-shadow: 0 4px 10px -3px rgba(59, 130, 246, 0.4); }
    #perf-dashboard .pipe-item .pipe-icon.rose  { background: linear-gradient(135deg, #f43f5e, #be123c); box-shadow: 0 4px 10px -3px rgba(244, 63, 94, 0.4); }
    #perf-dashboard .pipe-item .pipe-icon.green { background: linear-gradient(135deg, #10b981, #047857); box-shadow: 0 4px 10px -3px rgba(16, 185, 129, 0.4); }
    #perf-dashboard .pipe-item .pipe-info { flex: 1; min-width: 0; }
    #perf-dashboard .pipe-item .pipe-name {
        font-weight: 700; color: var(--perf-text);
        font-size: 0.86rem;
    }
    #perf-dashboard .pipe-item .pipe-meta {
        font-size: 0.72rem;
        color: var(--perf-muted);
        margin-top: 0.1rem;
    }
    #perf-dashboard .pipe-item .pipe-val {
        font-weight: 800;
        color: var(--perf-text);
        font-size: 0.95rem;
        text-align: right;
        white-space: nowrap;
        font-variant-numeric: tabular-nums;
    }

    /* Notification engagement ring */
    #perf-dashboard .notif-card {
        background: var(--perf-card);
        border: 1px solid var(--perf-border);
        border-radius: 0.9rem;
        padding: 1.1rem 1.25rem;
        height: 100%;
        position: relative;
        overflow: hidden;
    }
    #perf-dashboard .notif-card::before {
        content: '';
        position: absolute;
        top: 0; left: 0; right: 0;
        height: 4px;
        background: linear-gradient(90deg, #8b5cf6, #6d28d9);
    }
    #perf-dashboard .notif-grid {
        display: grid;
        grid-template-columns: auto 1fr;
        gap: 1rem;
        align-items: center;
        margin-top: 0.3rem;
    }
    #perf-dashboard .notif-ring {
        position: relative;
        width: 92px; height: 92px;
    }
    #perf-dashboard .notif-ring canvas { width: 100% !important; height: 100% !important; }
    #perf-dashboard .notif-ring .ring-center {
        position: absolute;
        top: 50%; left: 50%;
        transform: translate(-50%, -50%);
        text-align: center;
        pointer-events: none;
    }
    #perf-dashboard .notif-ring .ring-pct {
        font-size: 1.3rem;
        font-weight: 800;
        color: var(--perf-text);
        line-height: 1;
    }
    #perf-dashboard .notif-ring .ring-cap {
        font-size: 0.62rem;
        color: var(--perf-muted);
        text-transform: uppercase;
        letter-spacing: 0.05em;
        margin-top: 0.1rem;
    }
    #perf-dashboard .notif-stats {
        display: flex;
        flex-direction: column;
        gap: 0.45rem;
    }
    #perf-dashboard .notif-stats .ns-row {
        display: flex;
        align-items: center;
        gap: 0.4rem;
        font-size: 0.78rem;
        color: var(--perf-text);
    }
    #perf-dashboard .notif-stats .ns-row .ns-dot {
        width: 8px; height: 8px;
        border-radius: 50%;
        flex-shrink: 0;
    }
    #perf-dashboard .notif-stats .ns-row .ns-num {
        font-weight: 800;
        font-variant-numeric: tabular-nums;
        margin-left: auto;
    }

    /* Activity summary tiles — small gradient chips */
    #perf-dashboard .act-chip {
        border-radius: 0.75rem;
        padding: 0.85rem 1rem;
        color: #fff;
        position: relative;
        overflow: hidden;
        height: 100%;
    }
    #perf-dashboard .act-chip::after {
        content: '';
        position: absolute;
        top: -25px; right: -25px;
        width: 90px; height: 90px;
        background: radial-gradient(circle, rgba(255,255,255,0.18) 0%, rgba(255,255,255,0) 70%);
        border-radius: 50%;
    }
    #perf-dashboard .act-chip .ac-lbl {
        font-size: 0.72rem;
        opacity: 0.92;
        font-weight: 500;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }
    #perf-dashboard .act-chip .ac-val {
        font-size: 1.4rem;
        font-weight: 800;
        line-height: 1.1;
        margin-top: 0.1rem;
    }
    #perf-dashboard .act-chip .ac-sub {
        font-size: 0.72rem;
        opacity: 0.85;
        margin-top: 0.15rem;
    }
    #perf-dashboard .act-chip.teal   { background: linear-gradient(135deg, #14b8a6, #0d9488); }
    #perf-dashboard .act-chip.fuchsia{ background: linear-gradient(135deg, #d946ef, #a21caf); }
    #perf-dashboard .act-chip.cyan   { background: linear-gradient(135deg, #06b6d4, #0e7490); }

    /* ============================================================
       PHASE 4 — Commission, Stock Discipline & Accuracy visual system
       ============================================================ */

    /* ── Commission hero card — the "money" tile with big number ── */
    #perf-dashboard .comm-hero {
        background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 60%, #c026d3 100%);
        color: #fff;
        border-radius: 0.9rem;
        padding: 1.4rem 1.5rem;
        position: relative;
        overflow: hidden;
        height: 100%;
        min-height: 175px;
    }
    #perf-dashboard .comm-hero::before {
        content: '';
        position: absolute;
        top: -40px; right: -40px;
        width: 160px; height: 160px;
        background: radial-gradient(circle, rgba(255,255,255,0.18) 0%, rgba(255,255,255,0) 70%);
        border-radius: 50%;
    }
    #perf-dashboard .comm-hero::after {
        content: '';
        position: absolute;
        bottom: -30px; left: -30px;
        width: 110px; height: 110px;
        background: radial-gradient(circle, rgba(255,255,255,0.10) 0%, rgba(255,255,255,0) 70%);
        border-radius: 50%;
    }
    #perf-dashboard .comm-hero .ch-row {
        position: relative; z-index: 2;
        display: flex; align-items: center; gap: 0.85rem;
    }
    #perf-dashboard .comm-hero .ch-icon {
        width: 46px; height: 46px;
        border-radius: 0.7rem;
        background: rgba(255,255,255,0.18);
        display: flex; align-items: center; justify-content: center;
        font-size: 1.25rem;
        backdrop-filter: blur(6px);
    }
    #perf-dashboard .comm-hero .ch-lbl {
        font-size: 0.78rem;
        opacity: 0.92;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        font-weight: 600;
    }
    #perf-dashboard .comm-hero .ch-val {
        font-size: 2rem;
        font-weight: 800;
        letter-spacing: -0.02em;
        line-height: 1.1;
        margin-top: 0.2rem;
    }
    #perf-dashboard .comm-hero .ch-sub {
        font-size: 0.78rem;
        opacity: 0.88;
        margin-top: 0.35rem;
    }
    #perf-dashboard .comm-hero .ch-period {
        position: absolute;
        top: 1.2rem; right: 1.3rem;
        background: rgba(255,255,255,0.18);
        color: #fff;
        font-size: 0.7rem;
        font-weight: 700;
        padding: 0.18rem 0.55rem;
        border-radius: 999px;
        letter-spacing: 0.04em;
        z-index: 3;
    }

    /* ── Attainment gauge (semicircular) — reused gauge-card structure ── */
    #perf-dashboard .attain-card {
        background: var(--perf-card);
        border: 1px solid var(--perf-border);
        border-radius: 0.9rem;
        padding: 1.1rem 1.25rem;
        height: 100%;
        position: relative;
        overflow: hidden;
    }
    #perf-dashboard .attain-card::before {
        content: '';
        position: absolute;
        top: 0; left: 0; right: 0;
        height: 4px;
        background: linear-gradient(90deg, #f59e0b, #d97706);
    }
    #perf-dashboard .attain-card .attain-gauge-wrap {
        position: relative;
        margin: 0.4rem auto 0.25rem;
        width: 100%;
        max-width: 220px;
        aspect-ratio: 2 / 1.15;
    }
    #perf-dashboard .attain-card .attain-gauge-wrap canvas {
        width: 100% !important;
        height: 100% !important;
    }
    #perf-dashboard .attain-card .attain-readout {
        position: absolute;
        left: 0; right: 0; bottom: 0;
        text-align: center;
        pointer-events: none;
    }
    #perf-dashboard .attain-card .attain-pct {
        font-size: 1.95rem;
        font-weight: 800;
        color: var(--perf-text);
        letter-spacing: -0.02em;
        line-height: 1;
    }
    #perf-dashboard .attain-card .attain-cap {
        font-size: 0.7rem;
        color: var(--perf-muted);
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        margin-top: 0.15rem;
    }
    #perf-dashboard .attain-card .attain-meta {
        font-size: 0.74rem;
        color: var(--perf-muted);
        text-align: center;
        margin-top: 0.2rem;
    }
    #perf-dashboard .attain-card .attain-meta strong {
        color: var(--perf-text);
        font-weight: 700;
    }

    /* ── Target progress bar with milestone ticks ── */
    #perf-dashboard .target-card {
        background: var(--perf-card);
        border: 1px solid var(--perf-border);
        border-radius: 0.9rem;
        padding: 1.1rem 1.25rem;
        height: 100%;
        position: relative;
        overflow: hidden;
    }
    #perf-dashboard .target-card::before {
        content: '';
        position: absolute;
        top: 0; left: 0; right: 0;
        height: 4px;
        background: linear-gradient(90deg, #10b981, #059669);
    }
    #perf-dashboard .target-card .tc-head {
        display: flex; justify-content: space-between; align-items: baseline;
        margin-bottom: 0.7rem;
    }
    #perf-dashboard .target-card .tc-title {
        font-size: 0.82rem;
        font-weight: 700;
        color: var(--perf-text);
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }
    #perf-dashboard .target-card .tc-rule {
        font-size: 0.72rem;
        color: var(--perf-muted);
        font-weight: 600;
        background: #f1f5f9;
        padding: 0.18rem 0.55rem;
        border-radius: 999px;
    }
    #perf-dashboard .target-card .tc-numbers {
        display: flex; justify-content: space-between; align-items: baseline;
        margin-bottom: 0.5rem;
    }
    #perf-dashboard .target-card .tc-current {
        font-size: 1.2rem;
        font-weight: 800;
        color: var(--perf-text);
    }
    #perf-dashboard .target-card .tc-target {
        font-size: 0.78rem;
        color: var(--perf-muted);
    }
    #perf-dashboard .target-track {
        position: relative;
        height: 14px;
        background: #f1f5f9;
        border-radius: 999px;
        overflow: visible;
        margin: 0.35rem 0 0.7rem;
    }
    #perf-dashboard .target-fill {
        position: absolute;
        top: 0; left: 0; bottom: 0;
        border-radius: 999px;
        background: linear-gradient(90deg, #10b981, #059669);
        transition: width 0.9s cubic-bezier(0.16, 1, 0.3, 1);
        box-shadow: 0 2px 6px -1px rgba(16, 185, 129, 0.4);
    }
    #perf-dashboard .target-fill.over {
        background: linear-gradient(90deg, #10b981, #14b8a6, #0ea5e9);
    }
    #perf-dashboard .target-tick {
        position: absolute;
        top: -3px; bottom: -3px;
        width: 2px;
        background: #94a3b8;
        border-radius: 1px;
    }
    #perf-dashboard .target-tick.t-50 { left: 50%; }
    #perf-dashboard .target-tick.t-100 { left: 100%; background: #475569; }
    #perf-dashboard .target-card .tc-ticks-lbl {
        display: flex; justify-content: space-between;
        font-size: 0.68rem;
        color: var(--perf-muted);
        margin-top: 0.15rem;
    }
    #perf-dashboard .target-card .tc-status {
        display: inline-block;
        margin-top: 0.5rem;
        font-size: 0.74rem;
        font-weight: 700;
        padding: 0.18rem 0.6rem;
        border-radius: 999px;
    }
    #perf-dashboard .target-card .tc-status.good { background: #d1fae5; color: #065f46; }
    #perf-dashboard .target-card .tc-status.mid  { background: #fef3c7; color: #92400e; }
    #perf-dashboard .target-card .tc-status.low  { background: #fee2e2; color: #991b1b; }

    /* ── Commission status breakdown donut + legend ── */
    #perf-dashboard .comm-status-card {
        background: var(--perf-card);
        border: 1px solid var(--perf-border);
        border-radius: 0.9rem;
        padding: 1.1rem 1.25rem;
        height: 100%;
    }
    #perf-dashboard .comm-status-card .cs-head {
        display: flex; justify-content: space-between; align-items: baseline;
        margin-bottom: 0.7rem;
    }
    #perf-dashboard .comm-status-card .cs-title {
        font-size: 0.82rem;
        font-weight: 700;
        color: var(--perf-text);
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }
    #perf-dashboard .comm-status-card .cs-sub {
        font-size: 0.72rem;
        color: var(--perf-muted);
    }
    #perf-dashboard .comm-status-card .cs-row {
        display: flex; align-items: center; gap: 0.85rem;
    }
    #perf-dashboard .comm-status-card .cs-canvas-wrap {
        position: relative;
        width: 130px; height: 130px;
        flex-shrink: 0;
    }
    #perf-dashboard .comm-status-card .cs-canvas-wrap canvas {
        width: 100% !important; height: 100% !important;
    }
    #perf-dashboard .comm-status-card .cs-center {
        position: absolute; inset: 0;
        display: flex; flex-direction: column;
        align-items: center; justify-content: center;
        pointer-events: none;
    }
    #perf-dashboard .comm-status-card .cs-center .cs-center-val {
        font-size: 1.05rem;
        font-weight: 800;
        color: var(--perf-text);
        line-height: 1;
    }
    #perf-dashboard .comm-status-card .cs-center .cs-center-lbl {
        font-size: 0.62rem;
        color: var(--perf-muted);
        text-transform: uppercase;
        letter-spacing: 0.05em;
        margin-top: 0.15rem;
    }
    #perf-dashboard .comm-status-card .cs-legend {
        flex: 1;
        display: flex; flex-direction: column;
        gap: 0.35rem;
    }
    #perf-dashboard .comm-status-card .cs-leg-item {
        display: flex; align-items: center; gap: 0.5rem;
        font-size: 0.78rem;
    }
    #perf-dashboard .comm-status-card .cs-leg-dot {
        width: 10px; height: 10px;
        border-radius: 3px;
        flex-shrink: 0;
    }
    #perf-dashboard .comm-status-card .cs-leg-lbl { color: var(--perf-muted); flex: 1; }
    #perf-dashboard .comm-status-card .cs-leg-val {
        font-weight: 700;
        color: var(--perf-text);
        font-variant-numeric: tabular-nums;
    }

    /* ── Stock discipline tile — 5-up row of stat-tiles ── */
    #perf-dashboard .sd-tile {
        background: var(--perf-card);
        border: 1px solid var(--perf-border);
        border-radius: 0.9rem;
        padding: 1rem 1.1rem;
        height: 100%;
        position: relative;
        overflow: hidden;
        transition: transform 0.25s ease, box-shadow 0.25s ease;
    }
    #perf-dashboard .sd-tile:hover {
        transform: translateY(-3px);
        box-shadow: var(--perf-shadow);
    }
    #perf-dashboard .sd-tile::before {
        content: '';
        position: absolute;
        top: 0; left: 0; bottom: 0;
        width: 4px;
        background: var(--sd-accent, var(--perf-primary));
    }
    #perf-dashboard .sd-tile .sd-icon {
        width: 36px; height: 36px;
        border-radius: 0.6rem;
        background: var(--sd-accent, var(--perf-primary));
        color: #fff;
        display: flex; align-items: center; justify-content: center;
        font-size: 0.95rem;
        margin-bottom: 0.55rem;
        box-shadow: 0 4px 10px -2px var(--sd-accent, var(--perf-primary));
    }
    #perf-dashboard .sd-tile .sd-lbl {
        font-size: 0.72rem;
        color: var(--perf-muted);
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.04em;
    }
    #perf-dashboard .sd-tile .sd-val {
        font-size: 1.5rem;
        font-weight: 800;
        color: var(--perf-text);
        line-height: 1.1;
        margin-top: 0.15rem;
        font-variant-numeric: tabular-nums;
    }
    #perf-dashboard .sd-tile .sd-sub {
        font-size: 0.72rem;
        color: var(--perf-muted);
        margin-top: 0.2rem;
    }
    /* Accountable damages — special "danger" treatment when > 0 */
    #perf-dashboard .sd-tile.danger {
        background: linear-gradient(135deg, #fef2f2 0%, #fff 60%);
        border-color: #fecaca;
        --sd-accent: #ef4444;
    }
    #perf-dashboard .sd-tile.danger .sd-val { color: #b91c1c; }
    #perf-dashboard .sd-tile.danger::after {
        content: '\f071';
        font-family: 'Font Awesome 6 Free';
        font-weight: 900;
        position: absolute;
        top: 0.8rem; right: 0.95rem;
        color: #ef4444;
        font-size: 0.85rem;
        opacity: 0.55;
    }

    /* ── Accuracy scorecard — gauge + breakdown bars ── */
    #perf-dashboard .acc-gauge-card {
        background: var(--perf-card);
        border: 1px solid var(--perf-border);
        border-radius: 0.9rem;
        padding: 1.1rem 1.25rem;
        height: 100%;
        position: relative;
        overflow: hidden;
    }
    #perf-dashboard .acc-gauge-card::before {
        content: '';
        position: absolute;
        top: 0; left: 0; right: 0;
        height: 4px;
        background: linear-gradient(90deg, #10b981, #f59e0b, #ef4444);
    }
    #perf-dashboard .acc-gauge-card .ag-head {
        display: flex; justify-content: space-between; align-items: baseline;
        margin-bottom: 0.5rem;
    }
    #perf-dashboard .acc-gauge-card .ag-title {
        font-size: 0.82rem;
        font-weight: 700;
        color: var(--perf-text);
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }
    #perf-dashboard .acc-gauge-card .ag-sub {
        font-size: 0.72rem;
        color: var(--perf-muted);
    }
    #perf-dashboard .acc-gauge-card .ag-wrap {
        position: relative;
        margin: 0.5rem auto 0.5rem;
        width: 100%;
        max-width: 240px;
        aspect-ratio: 2 / 1.1;
    }
    #perf-dashboard .acc-gauge-card .ag-wrap canvas {
        width: 100% !important; height: 100% !important;
    }
    #perf-dashboard .acc-gauge-card .ag-readout {
        position: absolute;
        left: 0; right: 0; bottom: 0;
        text-align: center;
        pointer-events: none;
    }
    #perf-dashboard .acc-gauge-card .ag-pct {
        font-size: 2.1rem;
        font-weight: 800;
        letter-spacing: -0.02em;
        line-height: 1;
    }
    #perf-dashboard .acc-gauge-card .ag-pct.good { color: #059669; }
    #perf-dashboard .acc-gauge-card .ag-pct.mid  { color: #d97706; }
    #perf-dashboard .acc-gauge-card .ag-pct.low  { color: #dc2626; }
    #perf-dashboard .acc-gauge-card .ag-cap {
        font-size: 0.7rem;
        color: var(--perf-muted);
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        margin-top: 0.15rem;
    }
    #perf-dashboard .acc-gauge-card .ag-footer {
        font-size: 0.74rem;
        color: var(--perf-muted);
        text-align: center;
        margin-top: 0.4rem;
    }
    #perf-dashboard .acc-gauge-card .ag-footer strong {
        color: var(--perf-text);
        font-weight: 700;
    }

    /* ── Accuracy breakdown bar chart ── */
    #perf-dashboard .acc-breakdown-card {
        background: var(--perf-card);
        border: 1px solid var(--perf-border);
        border-radius: 0.9rem;
        padding: 1.1rem 1.25rem;
        height: 100%;
    }
    #perf-dashboard .acc-breakdown-card .ab-head {
        display: flex; justify-content: space-between; align-items: baseline;
        margin-bottom: 0.7rem;
    }
    #perf-dashboard .acc-breakdown-card .ab-title {
        font-size: 0.82rem;
        font-weight: 700;
        color: var(--perf-text);
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }
    #perf-dashboard .acc-breakdown-card .ab-sub {
        font-size: 0.72rem;
        color: var(--perf-muted);
    }
    #perf-dashboard .acc-breakdown-card .ab-row {
        display: grid;
        grid-template-columns: 1.4fr 3fr 0.8fr;
        gap: 0.7rem;
        align-items: center;
        padding: 0.45rem 0;
        border-bottom: 1px dashed #e2e8f0;
    }
    #perf-dashboard .acc-breakdown-card .ab-row:last-child { border-bottom: 0; }
    #perf-dashboard .acc-breakdown-card .ab-lbl {
        font-size: 0.78rem;
        color: var(--perf-text);
        font-weight: 600;
        display: flex; align-items: center; gap: 0.4rem;
    }
    #perf-dashboard .acc-breakdown-card .ab-lbl i { color: var(--ab-icon, #94a3b8); font-size: 0.78rem; }
    #perf-dashboard .acc-breakdown-card .ab-track {
        position: relative;
        height: 10px;
        background: #f1f5f9;
        border-radius: 999px;
        overflow: hidden;
    }
    #perf-dashboard .acc-breakdown-card .ab-fill {
        position: absolute;
        top: 0; left: 0; bottom: 0;
        border-radius: 999px;
        background: var(--ab-color, #94a3b8);
        transition: width 0.9s cubic-bezier(0.16, 1, 0.3, 1);
    }
    #perf-dashboard .acc-breakdown-card .ab-num {
        font-size: 0.84rem;
        font-weight: 700;
        color: var(--perf-text);
        text-align: right;
        font-variant-numeric: tabular-nums;
    }
    #perf-dashboard .acc-breakdown-card .ab-empty {
        text-align: center;
        padding: 1.5rem 0.5rem;
        color: var(--perf-muted);
    }
    #perf-dashboard .acc-breakdown-card .ab-empty i {
        font-size: 1.8rem;
        color: #10b981;
        margin-bottom: 0.4rem;
    }

    /* Phase 4 sub-section header — visually distinct from main section-h */
    #perf-dashboard .phase-sub-h {
        font-size: 0.95rem;
        font-weight: 700;
        color: #334155;
        margin: 1.1rem 0 0.55rem;
        padding-bottom: 0.3rem;
        border-bottom: 1px solid var(--perf-border);
        display: flex; align-items: center; gap: 0.45rem;
    }
    #perf-dashboard .phase-sub-h i { font-size: 0.85rem; }

    /* ============================================================
       PHASE 5 — Approval Workload visual system
       ============================================================ */

    /* Hero tile — urgency-tiered gradient (good=green, mid=amber, low=red) */
    #perf-dashboard .aw-hero {
        border-radius: 0.9rem;
        padding: 1.4rem 1.5rem;
        position: relative;
        overflow: hidden;
        height: 100%;
        min-height: 195px;
        color: #fff;
    }
    #perf-dashboard .aw-hero::before {
        content: '';
        position: absolute;
        top: -45px; right: -45px;
        width: 170px; height: 170px;
        background: radial-gradient(circle, rgba(255,255,255,0.20) 0%, rgba(255,255,255,0) 70%);
        border-radius: 50%;
    }
    #perf-dashboard .aw-hero::after {
        content: '';
        position: absolute;
        bottom: -35px; left: -35px;
        width: 120px; height: 120px;
        background: radial-gradient(circle, rgba(255,255,255,0.12) 0%, rgba(255,255,255,0) 70%);
        border-radius: 50%;
    }
    #perf-dashboard .aw-hero--good { background: linear-gradient(135deg, #10b981 0%, #059669 60%, #047857 100%); }
    #perf-dashboard .aw-hero--mid  { background: linear-gradient(135deg, #f59e0b 0%, #d97706 60%, #b45309 100%); }
    #perf-dashboard .aw-hero--low  { background: linear-gradient(135deg, #ef4444 0%, #dc2626 60%, #b91c1c 100%); }
    #perf-dashboard .aw-hero .aw-row {
        position: relative; z-index: 2;
        display: flex; align-items: center; gap: 0.85rem;
    }
    #perf-dashboard .aw-hero .aw-icon {
        width: 48px; height: 48px;
        border-radius: 0.7rem;
        background: rgba(255,255,255,0.18);
        display: flex; align-items: center; justify-content: center;
        font-size: 1.3rem;
        backdrop-filter: blur(6px);
    }
    #perf-dashboard .aw-hero .aw-lbl {
        font-size: 0.78rem;
        opacity: 0.92;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        font-weight: 600;
    }
    #perf-dashboard .aw-hero .aw-val {
        font-size: 2.4rem;
        font-weight: 800;
        letter-spacing: -0.02em;
        line-height: 1.05;
        margin-top: 0.15rem;
    }
    #perf-dashboard .aw-hero .aw-sub {
        font-size: 0.78rem;
        opacity: 0.9;
        margin-top: 0.4rem;
        position: relative; z-index: 2;
    }
    #perf-dashboard .aw-hero .aw-urgency {
        display: inline-block;
        margin-top: 0.7rem;
        background: rgba(255,255,255,0.20);
        color: #fff;
        font-size: 0.76rem;
        font-weight: 700;
        padding: 0.25rem 0.7rem;
        border-radius: 999px;
        letter-spacing: 0.03em;
        backdrop-filter: blur(4px);
        position: relative; z-index: 2;
    }

    /* Secondary stat tiles (4-up on the right) */
    #perf-dashboard .aw-stat-tile {
        background: var(--perf-card);
        border: 1px solid var(--perf-border);
        border-radius: 0.9rem;
        padding: 1rem 1.1rem;
        height: 100%;
        position: relative;
        overflow: hidden;
        transition: transform 0.25s ease, box-shadow 0.25s ease;
    }
    #perf-dashboard .aw-stat-tile:hover {
        transform: translateY(-3px);
        box-shadow: var(--perf-shadow);
    }
    #perf-dashboard .aw-stat-tile::before {
        content: '';
        position: absolute;
        top: 0; left: 0; bottom: 0;
        width: 4px;
        background: var(--aw-accent, var(--perf-primary));
    }
    #perf-dashboard .aw-stat-tile .aw-stat-icon {
        width: 36px; height: 36px;
        border-radius: 0.6rem;
        background: var(--aw-accent, var(--perf-primary));
        color: #fff;
        display: flex; align-items: center; justify-content: center;
        font-size: 0.95rem;
        margin-bottom: 0.55rem;
        box-shadow: 0 4px 10px -2px var(--aw-accent, var(--perf-primary));
    }
    #perf-dashboard .aw-stat-tile .aw-stat-lbl {
        font-size: 0.72rem;
        color: var(--perf-muted);
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.04em;
    }
    #perf-dashboard .aw-stat-tile .aw-stat-val {
        font-size: 1.55rem;
        font-weight: 800;
        color: var(--perf-text);
        line-height: 1.1;
        margin-top: 0.15rem;
        font-variant-numeric: tabular-nums;
    }
    #perf-dashboard .aw-stat-tile .aw-stat-sub {
        font-size: 0.72rem;
        color: var(--perf-muted);
        margin-top: 0.2rem;
    }

    /* ============================================================
       SECTION TAB NAVIGATION — split the long single-page dashboard
       into 5 focused tabs so users see one section at a time.
       Tabs: Sales · Collections & Returns · Productivity ·
             Commission & Stock · Approvals
       Active tab persists across AJAX refreshes via sessionStorage
       and is shareable via URL hash (#tab-sales).
       ============================================================ */
    #perf-dashboard .perf-tabbar {
        background: #ffffff;
        border: 1px solid var(--perf-border);
        border-radius: 0.85rem;
        padding: 0.45rem;
        box-shadow: var(--perf-shadow-sm);
        display: flex;
        flex-wrap: wrap;
        gap: 0.25rem;
        margin-bottom: 1.1rem;
        position: sticky;
        top: 0;
        z-index: 100;
    }
    #perf-dashboard .perf-tabbar .perf-tab {
        flex: 1 1 auto;
        min-width: 130px;
        background: transparent;
        border: 0;
        color: #475569;
        font-size: 0.85rem;
        font-weight: 600;
        padding: 0.55rem 0.9rem;
        border-radius: 0.6rem;
        cursor: pointer;
        transition: all 0.2s ease;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.45rem;
        text-decoration: none;
        white-space: nowrap;
        position: relative;
    }
    #perf-dashboard .perf-tabbar .perf-tab i {
        font-size: 0.85rem;
        opacity: 0.75;
    }
    #perf-dashboard .perf-tabbar .perf-tab:hover {
        background: #f1f5f9;
        color: #0f172a;
        transform: translateY(-1px);
    }
    #perf-dashboard .perf-tabbar .perf-tab:hover i { opacity: 1; }
    #perf-dashboard .perf-tabbar .perf-tab.active {
        background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
        color: #ffffff;
        box-shadow: 0 6px 18px -6px rgba(79, 70, 229, 0.55);
        transform: translateY(-1px);
    }
    #perf-dashboard .perf-tabbar .perf-tab.active i { opacity: 1; }
    #perf-dashboard .perf-tabbar .perf-tab .perf-tab-count {
        background: rgba(255,255,255,0.25);
        color: #fff;
        font-size: 0.68rem;
        padding: 0.05rem 0.4rem;
        border-radius: 999px;
        font-weight: 700;
        margin-left: 0.2rem;
    }
    #perf-dashboard .perf-tabbar .perf-tab:not(.active) .perf-tab-count {
        background: #e2e8f0;
        color: #475569;
    }

    /* Tab panes — only one visible at a time. We use display:none
       (not visibility:hidden) for inactive panes to keep the page
       short and snappy. Chart.js canvases inside hidden panes are
       re-sized when their pane becomes visible (see switchTab() in
       the scripts block). */
    #perf-dashboard .perf-tab-pane {
        display: none;
        animation: perf-tab-in 280ms ease-out;
    }
    #perf-dashboard .perf-tab-pane.active {
        display: block;
    }
    @keyframes perf-tab-in {
        from { opacity: 0; transform: translateY(6px); }
        to   { opacity: 1; transform: translateY(0); }
    }

    /* On small screens, allow horizontal scroll for the tab bar so
       all 5 tabs stay tappable instead of wrapping to 3 rows. */
    @media (max-width: 767.98px) {
        #perf-dashboard .perf-tabbar {
            flex-wrap: nowrap;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: thin;
        }
        #perf-dashboard .perf-tabbar .perf-tab {
            flex: 0 0 auto;
            min-width: auto;
            font-size: 0.78rem;
            padding: 0.5rem 0.7rem;
        }
    }

    /* ============================================================
       PHASE 6 — SKELETON OVERLAY + AJAX REFRESH POLISH
       ============================================================
       The skeleton overlay appears during AJAX fragment refreshes. It
       covers just the #perf-dashboard region with a translucent veil
       + a centered card containing a gradient spinner and "Refreshing
       dashboard…" text. The veil fades in (opacity 0 → 0.98) over
       180ms and fades out over 220ms — fast enough to feel instant
       but slow enough to communicate "something is happening".

       The spinner is a conic-gradient ring that rotates around its
       center; it uses the same indigo→violet palette as the hero
       header so the loading state feels native to the page.
       ──────────────────────────────────────────────────────────── */
    #perf-dashboard { position: relative; }

    #perf-dashboard .perf-skeleton-overlay {
        position: absolute;
        inset: 0;
        background: rgba(248, 250, 252, 0.6);
        backdrop-filter: blur(3px);
        -webkit-backdrop-filter: blur(3px);
        z-index: 9999;
        display: flex;
        align-items: center;
        justify-content: center;
        opacity: 0;
        transition: opacity 180ms ease-out;
        pointer-events: none;
        border-radius: 1rem;
    }
    #perf-dashboard .perf-skeleton-overlay.visible {
        opacity: 1;
        pointer-events: auto;
    }
    #perf-dashboard .perf-skeleton-card {
        background: #ffffff;
        border: 1px solid var(--perf-border);
        border-radius: 1rem;
        padding: 1.5rem 2rem;
        box-shadow: var(--perf-shadow-lg);
        display: flex;
        align-items: center;
        gap: 1rem;
        min-width: 240px;
    }
    #perf-dashboard .perf-skeleton-spinner {
        width: 36px;
        height: 36px;
        border-radius: 50%;
        background: conic-gradient(from 0deg, #4f46e5, #7c3aed, #ec4899, #4f46e5);
        -webkit-mask: radial-gradient(circle at center, transparent 38%, #000 40%);
        mask: radial-gradient(circle at center, transparent 38%, #000 40%);
        animation: perf-spin 800ms linear infinite;
        flex-shrink: 0;
    }
    @keyframes perf-spin {
        to { transform: rotate(360deg); }
    }
    #perf-dashboard .perf-skeleton-text {
        font-size: 0.92rem;
        font-weight: 600;
        color: var(--perf-text);
        letter-spacing: -0.005em;
    }

    /* Visual feedback on period pills during AJAX refresh — the active
       pill gets a "loading" pulse so the user knows which period they
       picked even before the new data lands. */
    #perf-dashboard.perf-refreshing .btn-period.active {
        animation: perf-pill-pulse 1.2s ease-in-out infinite;
    }
    @keyframes perf-pill-pulse {
        0%, 100% { box-shadow: 0 0 0 0 rgba(79, 70, 229, 0.4); }
        50%      { box-shadow: 0 0 0 6px rgba(79, 70, 229, 0); }
    }

    /* Smooth fade-in for swapped content — the new #perf-dashboard
       innerHTML gets .perf-fresh class for 400ms after a swap. */
    #perf-dashboard.perf-fresh > *:not(.perf-skeleton-overlay) {
        animation: perf-fade-in 400ms ease-out;
    }
    @keyframes perf-fade-in {
        from { opacity: 0; transform: translateY(4px); }
        to   { opacity: 1; transform: translateY(0); }
    }

    /* Phase 6 — Cache health badge. Renders in the hero header to show
       the user (and admin) that caching + telemetry are active. Hidden
       on small screens to preserve hero layout. */
    #perf-dashboard .perf-phase6-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        background: linear-gradient(135deg, rgba(16, 185, 129, 0.18), rgba(14, 165, 233, 0.18));
        border: 1px solid rgba(16, 185, 129, 0.35);
        color: #d1fae5;
        padding: 0.2rem 0.65rem;
        border-radius: 999px;
        font-size: 0.7rem;
        font-weight: 600;
        letter-spacing: 0.02em;
        text-transform: uppercase;
    }
    @media (max-width: 575.98px) {
        #perf-dashboard .perf-phase6-badge { display: none; }
    }
</style>
@endpush

@section('content')
<div id="perf-dashboard" class="py-3">

    {{-- ============================================================
         HERO HEADER — title + (super-admin only) employee selector
         ============================================================ --}}
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3 perf-hero">
        <div>
            <h2 class="h4 mb-1">
                <i class="fas fa-bolt me-2"></i>{{ $isSuperadmin && isset($targetEmployee) && $targetEmployee ? 'Performance Dashboard' : 'My Performance' }}
            </h2>
            <p class="mb-2 sub">
                @if (isset($targetEmployee) && $targetEmployee)
                    <span class="pill me-1"><i class="fas fa-user me-1"></i>{{ $targetEmployee->name }}</span>
                    @if ($targetEmployee->employee_code)<span class="pill me-1">{{ $targetEmployee->employee_code }}</span>@endif
                    <span class="pill me-1">{{ ucfirst($targetEmployee->role) }}</span>
                    @if ($targetEmployee->branch)<span class="pill"><i class="fas fa-map-marker-alt me-1"></i>{{ $targetEmployee->branch->branch_name }}</span>@endif
                    @if (isset($roleSections) && $roleSections)
                        @php
                            $visibleSections = array_keys(array_filter($roleSections));
                            $sectionCount = count($visibleSections);
                        @endphp
                        <span class="pill" title="Visible sections: {{ implode(', ', $visibleSections) }}">
                            <i class="fas fa-eye me-1"></i>{{ $sectionCount }} section{{ $sectionCount !== 1 ? 's' : '' }} visible
                        </span>
                    @endif
                    {{-- Phase 6 — Live + Cached badge. Indicates the dashboard
                         is using Cache::remember() (60s TTL) + slow-query
                         telemetry (>200ms → storage/logs/perf.log) + AJAX
                         fragment refresh. Clicking it is a no-op (the title
                         attribute explains what it means). --}}
                    <span class="perf-phase6-badge ms-1" title="Phase 6: 60s cache + slow-query telemetry + AJAX fragment refresh active">
                        <i class="fas fa-bolt"></i>Live · Cached
                    </span>
                @endif
            </p>
            <p class="mb-0 sub">
                <i class="far fa-calendar me-1"></i>{{ $periodLabel }}
                @if (isset($range) && $range) · {{ $range['start'] }} → {{ $range['end'] }} @endif
            </p>
        </div>

        <div class="d-flex flex-wrap align-items-center gap-2">
            @if ($isSuperadmin && isset($employeeOptions) && $employeeOptions->isNotEmpty())
            <form method="GET" action="{{ route('dashboard') }}" id="employeeSwitchForm" class="d-flex align-items-center gap-2">
                <input type="hidden" name="period" value="{{ $period }}">
                @if ($period === 'custom')
                    <input type="hidden" name="from" value="{{ $range['start'] }}">
                    <input type="hidden" name="to" value="{{ $range['end'] }}">
                @endif
                <label class="small text-white-50 mb-0 me-1">
                    <i class="fas fa-users me-1"></i>Employee:
                </label>
                <select name="employee_id" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">— Myself ({{ Auth::user()->username }}) —</option>
                    @foreach ($employeeOptions as $emp)
                        <option value="{{ $emp->id }}"
                            @if (isset($targetEmployee) && $targetEmployee && $targetEmployee->id === $emp->id) selected @endif>
                            {{ $emp->name }} ({{ $emp->employee_code }}) — {{ ucfirst($emp->role) }}@if ($emp->branch) · {{ $emp->branch->branch_name }}@endif
                        </option>
                    @endforeach
                </select>
            </form>
            @endif
        </div>
    </div>

    {{-- Error state (user not linked to an employee) --}}
    @if (isset($errorMessage))
    <div class="perf-error mb-3">
        <i class="fas fa-exclamation-triangle me-2"></i>{{ $errorMessage }}
    </div>
    @endif

    {{-- ============================================================
         PERIOD SWITCHER
         ============================================================ --}}
    @if (isset($targetEmployee) && $targetEmployee)
    <div class="d-flex flex-wrap align-items-center gap-2 mb-3 perf-period-bar">
        <span class="text-muted small me-2"><i class="far fa-calendar me-1"></i>Period:</span>
        @php
            // YTD (Year to Date) removed per request — it scans ~365 days
            // of partitioned data and was the slowest period option. The
            // remaining 4 options + custom range cover every realistic use.
            $periods = [
                'today'  => 'Today',
                'mtd'    => 'MTD',
                'qtd'    => 'QTD',
                'last30' => 'Last 30D',
            ];
            $baseQuery = [];
            if ($isSuperadmin && isset($targetEmployee) && $targetEmployee && $targetEmployee->id !== Auth::user()->employee?->id) {
                $baseQuery['employee_id'] = $targetEmployee->id;
            }
        @endphp
        @foreach ($periods as $key => $label)
            @php $q = array_merge($baseQuery, ['period' => $key]); @endphp
            <a href="{{ route('dashboard', $q) }}"
               class="btn-period @if ($period === $key) active @endif">{{ $label }}</a>
        @endforeach

        <form method="GET" action="{{ route('dashboard') }}" class="d-flex align-items-center gap-1 ms-2" id="customPeriodForm">
            @foreach ($baseQuery as $k => $v)
                <input type="hidden" name="{{ $k }}" value="{{ $v }}">
            @endforeach
            <input type="hidden" name="period" value="custom">
            <input type="date" name="from" class="form-control form-control-sm" style="width:auto" value="{{ $range['start'] ?? '' }}" required>
            <span class="text-muted small">→</span>
            <input type="date" name="to" class="form-control form-control-sm" style="width:auto" value="{{ $range['end'] ?? '' }}" required>
            <button type="submit" class="btn btn-sm btn-outline-primary"><i class="fas fa-arrow-right"></i></button>
        </form>
    </div>
    @endif

    {{-- ============================================================
         SECTION TAB BAR — 5 focused tabs replace the single long scroll.
         Each tab is gated by the same roleSections flag as the pane it
         switches to, so users only see tabs for sections they can access.
         The active tab is preserved across AJAX refreshes (see JS below).
         ============================================================ --}}
    @php
        $visibleTabs = [];
        if (isset($targetEmployee) && $targetEmployee && !$scaffoldingOnly) {
            if ($roleSections['sales'] ?? false) {
                $visibleTabs['sales'] = ['icon' => 'fa-chart-line', 'label' => 'Sales'];
            }
            if (($roleSections['collections'] ?? false) || ($roleSections['returns'] ?? false)) {
                $visibleTabs['collections'] = ['icon' => 'fa-hand-holding-usd', 'label' => 'Collections & Returns'];
            }
            if ($roleSections['operational'] ?? false) {
                $visibleTabs['productivity'] = ['icon' => 'fa-user-clock', 'label' => 'Productivity'];
            }
            if (($roleSections['commission'] ?? false) || ($roleSections['stock_discipline'] ?? false) || ($roleSections['accuracy'] ?? false)) {
                $visibleTabs['commission'] = ['icon' => 'fa-bullseye', 'label' => 'Commission & Stock'];
            }
            if ($roleSections['approval_workload'] ?? false) {
                $visibleTabs['approvals'] = ['icon' => 'fa-check-double', 'label' => 'Approvals'];
            }
        }
    @endphp
    @if (!empty($visibleTabs))
    <nav class="perf-tabbar" role="tablist" aria-label="Dashboard sections">
        @foreach ($visibleTabs as $tabId => $tab)
            <button type="button"
                    class="perf-tab @if ($loop->first) active @endif"
                    data-tab="{{ $tabId }}"
                    role="tab"
                    aria-selected="{{ $loop->first ? 'true' : 'false' }}"
                    id="perf-tab-btn-{{ $tabId }}">
                <i class="fas {{ $tab['icon'] }}"></i>{{ $tab['label'] }}
            </button>
        @endforeach
    </nav>
    @endif

    {{-- ============================================================
         PHASE 1 — SALES PERFORMANCE CORE
         (Phase 5: role-aware — hidden for warehouse_manager, dispatcher,
         accountant; visible for everyone else)
         Wrapped in a tab pane (#tab-sales) so only one section shows at a time.
         ============================================================ --}}
    @if (isset($targetEmployee) && $targetEmployee && !$scaffoldingOnly && ($roleSections['sales'] ?? true))
    <div class="perf-tab-pane @if ($visibleTabs && array_key_first($visibleTabs) === 'sales') active @endif" id="tab-sales" role="tabpanel" aria-labelledby="perf-tab-btn-sales">

    {{-- ===== KPI ROW — 5 gradient-topped cards with sparklines ===== --}}
    <h3 class="section-h"><span class="bar"></span><i class="fas fa-chart-line text-primary"></i> Sales Performance</h3>

    <div class="row g-3 mb-3">
        @php
            $kpis = $salesKpis ?? [
                'invoice_count' => 0, 'total_sales' => 0.0, 'aov' => 0.0,
                'growth_pct' => 0.0, 'active_days' => 0, 'peak_day_value' => 0.0,
                'peak_day_date' => null, 'prev_total_sales' => 0.0,
            ];
            $trend = $salesTrend ?? [];
            $trendValues = array_map(fn($r) => $r['total_sales'], $trend);
            $acq = $customerAcquisition ?? ['active' => 0, 'new' => 0, 'repeat' => 0, 'repeat_rate' => 0.0, 'new_rate' => 0.0];
        @endphp

        {{-- Sales Volume --}}
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="kpi-card" style="--accent: linear-gradient(135deg, #4f46e5, #7c3aed);">
                <div class="kpi-icon" style="background: linear-gradient(135deg, #4f46e5, #7c3aed);"><i class="fas fa-money-bill-wave"></i></div>
                <div class="kpi-label">Sales Volume</div>
                <div class="kpi-value mono">৳ {{ number_format($kpis['total_sales'], 0) }}</div>
                <div class="kpi-sub">{{ $kpis['invoice_count'] }} invoice{{ $kpis['invoice_count'] !== 1 ? 's' : '' }} this period</div>
                @php
                    $growth = $kpis['growth_pct'] ?? 0.0;
                    $gClass = $growth > 0.5 ? 'up' : ($growth < -0.5 ? 'down' : 'flat');
                    $gIcon  = $growth > 0.5 ? 'arrow-up' : ($growth < -0.5 ? 'arrow-down' : 'minus');
                @endphp
                <span class="kpi-delta {{ $gClass }}">
                    <i class="fas fa-{{ $gIcon }}"></i>{{ abs($growth) }}% vs prev
                </span>
                <canvas class="spark" data-values="{{ implode(',', $trendValues) }}" data-color="#4f46e5"></canvas>
            </div>
        </div>

        {{-- Avg Invoice Size --}}
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="kpi-card" style="--accent: linear-gradient(135deg, #0ea5e9, #2563eb);">
                <div class="kpi-icon" style="background: linear-gradient(135deg, #0ea5e9, #2563eb);"><i class="fas fa-calculator"></i></div>
                <div class="kpi-label">Avg Invoice Size</div>
                <div class="kpi-value mono">৳ {{ number_format($kpis['aov'], 0) }}</div>
                <div class="kpi-sub">Per-invoice average (AOV)</div>
                @php
                    $prevAov = $kpis['prev_total_sales'] > 0 && $kpis['invoice_count'] > 0
                        ? ($kpis['prev_total_sales'] / max(1, $kpis['invoice_count']))
                        : 0;
                    $aovDelta = $prevAov > 0 ? round((($kpis['aov'] - $prevAov) / $prevAov) * 100, 1) : 0;
                    $aClass = $aovDelta > 0.5 ? 'up' : ($aovDelta < -0.5 ? 'down' : 'flat');
                    $aIcon  = $aovDelta > 0.5 ? 'arrow-up' : ($aovDelta < -0.5 ? 'arrow-down' : 'minus');
                @endphp
                <span class="kpi-delta {{ $aClass }}">
                    <i class="fas fa-{{ $aIcon }}"></i>{{ abs($aovDelta) }}% vs prev
                </span>
            </div>
        </div>

        {{-- Active Selling Days --}}
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="kpi-card" style="--accent: linear-gradient(135deg, #10b981, #059669);">
                <div class="kpi-icon" style="background: linear-gradient(135deg, #10b981, #059669);"><i class="fas fa-calendar-check"></i></div>
                <div class="kpi-label">Active Selling Days</div>
                <div class="kpi-value mono">{{ $kpis['active_days'] }}</div>
                <div class="kpi-sub">Days with at least one invoice</div>
                @php
                    $periodLen = (isset($range) ? \Carbon\Carbon::parse($range['start'])->diffInDays(\Carbon\Carbon::parse($range['end'])) + 1 : 1);
                    $utilization = $periodLen > 0 ? round(($kpis['active_days'] / $periodLen) * 100, 0) : 0;
                @endphp
                <span class="kpi-delta {{ $utilization >= 70 ? 'up' : ($utilization >= 40 ? 'flat' : 'down') }}">
                    <i class="fas fa-{{ $utilization >= 70 ? 'fire' : 'clock' }}"></i>{{ $utilization }}% utilization
                </span>
            </div>
        </div>

        {{-- New Customers --}}
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="kpi-card" style="--accent: linear-gradient(135deg, #ec4899, #db2777);">
                <div class="kpi-icon" style="background: linear-gradient(135deg, #ec4899, #db2777);"><i class="fas fa-user-plus"></i></div>
                <div class="kpi-label">New Customers</div>
                <div class="kpi-value mono">{{ $acq['new'] }}</div>
                <div class="kpi-sub">First-time buyers in period</div>
                <span class="kpi-delta {{ $acq['new_rate'] >= 30 ? 'up' : 'flat' }}">
                    <i class="fas fa-percentage"></i>{{ $acq['new_rate'] }}% of {{ $acq['active'] }} active
                </span>
            </div>
        </div>
    </div>

    {{-- ===== Peak Day callout + Growth highlight ===== --}}
    <div class="row g-3 mb-3">
        <div class="col-12 col-md-6 col-xl-4">
            <div class="peak-card">
                <div class="peak-icon"><i class="fas fa-trophy"></i></div>
                <div>
                    <div class="peak-label">Peak Sales Day</div>
                    @if ($kpis['peak_day_date'])
                        <div class="peak-value mono">৳ {{ number_format($kpis['peak_day_value'], 0) }}</div>
                        <div class="peak-date">{{ \Carbon\Carbon::parse($kpis['peak_day_date'])->format('D, M j, Y') }}</div>
                    @else
                        <div class="peak-value">No sales yet</div>
                        <div class="peak-date">Pick an active day to see your peak</div>
                    @endif
                </div>
            </div>
        </div>

        {{-- Repeat customers tile --}}
        <div class="col-12 col-md-6 col-xl-4">
            <div class="acq-tile repeat" style="height:100%; display:flex; flex-direction:column; justify-content:center;">
                <div class="lbl"><i class="fas fa-redo me-1"></i>Repeat Customers</div>
                <div class="val mono">{{ $acq['repeat'] }}</div>
                <div class="pct">{{ $acq['repeat_rate'] }}% of {{ $acq['active'] }} active customers returned for a 2nd+ sale</div>
            </div>
        </div>

        {{-- Total active customers tile --}}
        <div class="col-12 col-md-6 col-xl-4">
            <div class="acq-tile" style="background: linear-gradient(135deg, #f59e0b, #d97706); height:100%; display:flex; flex-direction:column; justify-content:center;">
                <div class="lbl"><i class="fas fa-users me-1"></i>Active Customers</div>
                <div class="val mono">{{ $acq['active'] }}</div>
                <div class="pct">Unique customers billed this period</div>
            </div>
        </div>
    </div>

    {{-- ===== Charts row: Sales Trend (8) + Product Group bars (4) ===== --}}
    <div class="row g-3 mb-3">
        <div class="col-12 col-xl-8">
            <div class="chart-card">
                <div class="chart-title"><i class="fas fa-chart-area text-primary"></i> Sales Trend</div>
                <div class="chart-sub">Daily invoice value & count over the selected period</div>
                <div class="chart-wrap" style="height:300px;">
                    <canvas id="salesTrendChart"></canvas>
                </div>
            </div>
        </div>
        <div class="col-12 col-xl-4">
            <div class="chart-card">
                <div class="chart-title"><i class="fas fa-chart-bar text-info"></i> Sales by Product Group</div>
                <div class="chart-sub">Top groups by your revenue this period</div>
                <div class="chart-wrap" style="max-height:300px; overflow-y:auto;">
                    @php $pgroups = $salesByProductGroup ?? []; @endphp
                    @if (empty($pgroups))
                        <div class="empty-card">
                            <i class="fas fa-folder-open"></i>
                            <div>No product-group sales yet this period.</div>
                        </div>
                    @else
                        @php
                            $maxRev = max(array_map(fn($g) => $g['revenue'], $pgroups) + [1]) ?: 1;
                            $palette = ['#4f46e5', '#7c3aed', '#0ea5e9', '#10b981', '#f59e0b', '#ec4899', '#ef4444', '#14b8a6'];
                        @endphp
                        @foreach ($pgroups as $i => $g)
                            <div class="pg-row">
                                <div class="pg-name" title="{{ $g['group_name'] }}">{{ $g['group_name'] }}</div>
                                <div class="pg-track">
                                    <div class="pg-fill" style="width: {{ max(8, round(($g['revenue'] / $maxRev) * 100, 1)) }}%; background: linear-gradient(90deg, {{ $palette[$i % count($palette)] }}, {{ $palette[($i + 1) % count($palette)] }});">
                                        ৳ {{ number_format($g['revenue'], 0) }}
                                    </div>
                                </div>
                                <div class="pg-share">{{ $g['share'] }}%</div>
                            </div>
                        @endforeach
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- ===== Top Customers leaderboard ===== --}}
    <div class="row g-3 mb-3">
        <div class="col-12">
            <div class="chart-card">
                <div class="chart-title"><i class="fas fa-crown text-warning"></i> My Top 5 Customers</div>
                <div class="chart-sub">By your revenue this period — NOT a global top-5. Bar shows share of your total revenue.</div>
                <div class="chart-wrap">
                    @php $tcs = $topCustomers ?? []; @endphp
                    @if (empty($tcs))
                        <div class="empty-card">
                            <i class="fas fa-user-friends"></i>
                            <div>No customer sales yet this period.</div>
                        </div>
                    @else
                        @foreach ($tcs as $i => $c)
                            <div class="cust-row">
                                <div class="cust-rank r{{ min($i + 1, 3) }}">{{ $i + 1 }}</div>
                                <div class="cust-info">
                                    <div class="cust-name">{{ $c['name'] }}</div>
                                    <div class="cust-meta">{{ $c['invoice_count'] }} invoice{{ $c['invoice_count'] !== 1 ? 's' : '' }} · {{ $c['share'] }}% of your revenue</div>
                                    <div class="cust-progress"><div style="width: {{ $c['share'] }}%;"></div></div>
                                </div>
                                <div>
                                    <div class="cust-revenue mono">৳ {{ number_format($c['revenue'], 0) }}</div>
                                    @if ($c['due'] > 0)
                                        <div class="cust-due">৳ {{ number_format($c['due'], 0) }} due</div>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    @endif
                </div>
            </div>
        </div>
    </div>

    </div>
    @endif {{-- end of Phase 1 Sales block --}}

    {{-- ============================================================
         PHASE 2 — COLLECTIONS & RETURNS
         (Phase 5: role-aware — visible when roleSections.collections OR
         roleSections.returns is true. Accountant sees it; warehouse_manager
         and dispatcher do not.)
         Wrapped in a tab pane (#tab-collections).
         ============================================================ --}}
    @if (isset($targetEmployee) && $targetEmployee && !$scaffoldingOnly && (($roleSections['collections'] ?? true) || ($roleSections['returns'] ?? true)))
    <div class="perf-tab-pane @if ($visibleTabs && array_key_first($visibleTabs) === 'collections') active @endif" id="tab-collections" role="tabpanel" aria-labelledby="perf-tab-btn-collections">
    @php
        // Pull all Phase 2 datasets with safe defaults so missing data
        // doesn't break the markup.
        $ck = $collectionKpis ?? [
            'collection_count' => 0, 'collection_value' => 0.0,
            'collection_rate' => 0.0, 'outstanding' => 0.0,
            'overdue_count' => 0, 'overdue_value' => 0.0,
            'discount_allowed' => 0.0, 'prev_collection_value' => 0.0,
            'growth_pct' => 0.0,
        ];
        $aging = $receivableAging ?? [
            'Current' => 0.0, '1-30' => 0.0, '31-60' => 0.0,
            '61-90' => 0.0, '90+' => 0.0, 'total' => 0.0,
        ];
        $rk = $returnKpis ?? [
            'return_count' => 0, 'return_value' => 0.0, 'return_rate' => 0.0,
            'prev_return_value' => 0.0, 'growth_pct' => 0.0, 'top_reasons' => [],
        ];
        $pmix = $paymentModeMix ?? [];

        // Aging palette: green → yellow → orange → red → deep red (worse with age)
        $agingMeta = [
            'Current' => ['color' => '#10b981', 'gradient' => 'linear-gradient(90deg, #10b981, #059669)', 'label' => 'Current'],
            '1-30'    => ['color' => '#f59e0b', 'gradient' => 'linear-gradient(90deg, #f59e0b, #d97706)', 'label' => '1–30 days'],
            '31-60'   => ['color' => '#f97316', 'gradient' => 'linear-gradient(90deg, #f97316, #ea580c)', 'label' => '31–60 days'],
            '61-90'   => ['color' => '#ef4444', 'gradient' => 'linear-gradient(90deg, #ef4444, #dc2626)', 'label' => '61–90 days'],
            '90+'     => ['color' => '#b91c1c', 'gradient' => 'linear-gradient(90deg, #b91c1c, #7f1d1d)', 'label' => '90+ days'],
        ];
        // Defensive: array_filter() strips 0.0 values, which can leave an empty
        // array when the user has no outstanding receivables — max([]) would
        // throw. Prepend a floor of 1 so the bar-width math stays safe.
        $agingMax = max(array_values(array_filter([
            $aging['Current'], $aging['1-30'], $aging['31-60'], $aging['61-90'], $aging['90+'],
        ], fn ($v) => $v > 0)) + [1]) ?: 1;

        // Collection-rate gauge target thresholds
        $rateClass = $ck['collection_rate'] >= 80 ? 'good' : ($ck['collection_rate'] >= 50 ? 'mid' : 'low');
        $rateMsg   = $ck['collection_rate'] >= 80 ? 'On target' : ($ck['collection_rate'] >= 50 ? 'Below target' : 'Critical');

        // Return rate severity (target < 5%)
        $rRateClass = $rk['return_rate'] <= 2 ? 'good' : ($rk['return_rate'] <= 5 ? 'mid' : 'low');

        // Payment mode palette
        $pmixPalette = [
            'cash'           => '#10b981',
            'bank'           => '#4f46e5',
            'cheque'         => '#f59e0b',
            'mobile_banking' => '#ec4899',
            'adjustment'     => '#64748b',
        ];
        $pmixTotal = array_sum(array_map(fn($p) => $p['value'], $pmix));
    @endphp

    <h3 class="section-h mt-4"><span class="bar"></span><i class="fas fa-hand-holding-usd text-success"></i> Collections &amp; Returns</h3>

    {{-- ===== KPI ROW — 4 stat-tiles + gauge ===== --}}
    <div class="row g-3 mb-3">
        {{-- Collection Volume --}}
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="stat-tile green">
                <div class="stat-icon"><i class="fas fa-coins"></i></div>
                <div class="stat-lbl">Collection Volume</div>
                <div class="stat-val mono">৳ {{ number_format($ck['collection_value'], 0) }}</div>
                <div class="stat-sub">{{ $ck['collection_count'] }} payment{{ $ck['collection_count'] !== 1 ? 's' : '' }} received this period</div>
                @php
                    $cg = $ck['growth_pct'];
                    $cgIcon = $cg > 0.5 ? 'arrow-up' : ($cg < -0.5 ? 'arrow-down' : 'minus');
                @endphp
                <span class="stat-delta"><i class="fas fa-{{ $cgIcon }}"></i>{{ abs($cg) }}% vs prev</span>
            </div>
        </div>

        {{-- Collection Rate gauge --}}
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="gauge-card">
                <div class="kpi-label text-center" style="margin-top:0.2rem;">Collection Rate</div>
                <div class="gauge-wrap">
                    <canvas id="collectionGauge"></canvas>
                    <div class="gauge-readout">
                        <div class="gauge-pct mono">{{ $ck['collection_rate'] }}<span style="font-size:1rem;">%</span></div>
                        <div class="gauge-cap">collected</div>
                    </div>
                </div>
                <div class="gauge-target">
                    Target ≥ 80%
                    <span class="tgt-mark {{ $rateClass }}">{{ $rateMsg }}</span>
                </div>
            </div>
        </div>

        {{-- My Outstanding --}}
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="stat-tile amber">
                <div class="stat-icon"><i class="fas fa-wallet"></i></div>
                <div class="stat-lbl">My Outstanding</div>
                <div class="stat-val mono">৳ {{ number_format($ck['outstanding'], 0) }}</div>
                <div class="stat-sub">Total receivable on your book (all-time)</div>
                <span class="stat-delta"><i class="fas fa-info-circle"></i>Snapshot · live</span>
            </div>
        </div>

        {{-- Overdue >30 days --}}
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="stat-tile red">
                <div class="stat-icon"><i class="fas fa-exclamation-circle"></i></div>
                <div class="stat-lbl">Overdue (&gt;30 days)</div>
                <div class="stat-val mono">৳ {{ number_format($ck['overdue_value'], 0) }}</div>
                <div class="stat-sub">{{ $ck['overdue_count'] }} invoice{{ $ck['overdue_count'] !== 1 ? 's' : '' }} past assumed 30-day term</div>
                <span class="stat-delta"><i class="fas fa-clock"></i>Needs follow-up</span>
            </div>
        </div>
    </div>

    {{-- ===== Mid row: Return Rate tile + Return Value + Discount Allowed + Payment Mix donut ===== --}}
    <div class="row g-3 mb-3">
        {{-- Return Rate --}}
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="stat-tile rose">
                <div class="stat-icon"><i class="fas fa-undo"></i></div>
                <div class="stat-lbl">Return Rate</div>
                <div class="stat-val mono">{{ $rk['return_rate'] }}<span style="font-size:1rem;">%</span></div>
                <div class="stat-sub">{{ $rk['return_count'] }} return{{ $rk['return_count'] !== 1 ? 's' : '' }} this period · target &lt; 5%</div>
                @php
                    $rg = $rk['growth_pct'];
                    $rgIcon = $rg < -0.5 ? 'arrow-down' : ($rg > 0.5 ? 'arrow-up' : 'minus');
                    $rgGood = $rg < -0.5; // negative growth on returns is GOOD
                @endphp
                <span class="stat-delta"><i class="fas fa-{{ $rgIcon }}"></i>{{ abs($rg) }}% vs prev · {{ $rgGood ? 'improving' : 'rising' }}</span>
            </div>
        </div>

        {{-- Return Value --}}
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="stat-tile violet">
                <div class="stat-icon"><i class="fas fa-rotate-left"></i></div>
                <div class="stat-lbl">Return Value</div>
                <div class="stat-val mono">৳ {{ number_format($rk['return_value'], 0) }}</div>
                <div class="stat-sub">Confirmed returns — revenue reversed</div>
                <span class="stat-delta"><i class="fas fa-chart-line"></i>Excludes draft/cancelled</span>
            </div>
        </div>

        {{-- Discount Allowed --}}
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="stat-tile indigo">
                <div class="stat-icon"><i class="fas fa-tag"></i></div>
                <div class="stat-lbl">Discount Allowed</div>
                <div class="stat-val mono">৳ {{ number_format($ck['discount_allowed'], 0) }}</div>
                <div class="stat-sub">Inline discounts on your collections</div>
                @php
                    $discountPctOfColl = $ck['collection_value'] > 0
                        ? round(($ck['discount_allowed'] / $ck['collection_value']) * 100, 1)
                        : 0.0;
                @endphp
                <span class="stat-delta"><i class="fas fa-percentage"></i>{{ $discountPctOfColl }}% of collections</span>
            </div>
        </div>

        {{-- Payment Mode Mix donut --}}
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="chart-card" style="padding: 0.95rem 1.05rem;">
                <div class="chart-title"><i class="fas fa-credit-card text-info"></i> Payment Mode Mix</div>
                <div class="chart-sub">How your collections come in</div>
                @if (empty($pmix) || $pmixTotal <= 0)
                    <div class="empty-card" style="padding:1rem;">
                        <i class="fas fa-folder-open"></i>
                        <div style="font-size:0.8rem;">No collections yet this period.</div>
                    </div>
                @else
                    <div class="pmix-grid">
                        <div class="pmix-donut">
                            <canvas id="pmixDonut"></canvas>
                            <div class="pmix-center">
                                <div class="pmix-total mono">{{ count($pmix) }}</div>
                                <div class="pmix-cap">modes</div>
                            </div>
                        </div>
                        <div class="pmix-legend">
                            @foreach ($pmix as $p)
                                <div class="pmix-item">
                                    <span class="sw" style="background: {{ $pmixPalette[$p['mode']] ?? '#94a3b8' }};"></span>
                                    <span class="nm">{{ $p['label'] }}</span>
                                    <span class="pc">{{ $p['share'] }}%</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>

    {{-- ===== Charts row: Receivable Aging (8) + Top Return Reasons (4) ===== --}}
    <div class="row g-3 mb-3">
        {{-- Receivable Aging — 5 horizontal bars (color-coded by severity) --}}
        <div class="col-12 col-xl-8">
            <div class="chart-card">
                <div class="chart-title">
                    <i class="fas fa-clock text-warning"></i> Receivable Aging — My Book
                    <span class="ms-2" style="font-size:0.72rem; font-weight:600; color:var(--perf-muted); background:#f1f5f9; padding:0.1rem 0.5rem; border-radius:999px;">
                        Total: ৳ {{ number_format($aging['total'], 0) }}
                    </span>
                </div>
                <div class="chart-sub">Outstanding by invoice age — point-in-time snapshot of your book</div>
                <div class="chart-wrap" style="padding-top:0.4rem;">
                    @php $agingKeys = ['Current', '1-30', '31-60', '61-90', '90+']; @endphp
                    @foreach ($agingKeys as $key)
                        @php
                            $meta = $agingMeta[$key];
                            $val  = $aging[$key];
                            $pct  = $agingMax > 0 ? max(3, round(($val / $agingMax) * 100, 1)) : 3;
                            $share = $aging['total'] > 0 ? round(($val / $aging['total']) * 100, 1) : 0.0;
                        @endphp
                        <div class="aging-row">
                            <div class="aging-lbl">
                                <span class="dot" style="background: {{ $meta['color'] }};"></span>{{ $meta['label'] }}
                            </div>
                            <div class="aging-track">
                                <div class="aging-fill" style="width: {{ $pct }}%; background: {{ $meta['gradient'] }};">
                                    @if ($val > 0){{ $share }}%@endif
                                </div>
                            </div>
                            <div class="aging-val">৳ {{ number_format($val, 0) }}</div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- Top Return Reasons --}}
        <div class="col-12 col-xl-4">
            <div class="chart-card">
                <div class="chart-title"><i class="fas fa-comment-dots text-danger"></i> Top Return Reasons</div>
                <div class="chart-sub">Coaching signal — what's coming back</div>
                <div class="chart-wrap" style="padding-top:0.4rem;">
                    @php $reasons = $rk['top_reasons'] ?? []; @endphp
                    @if (empty($reasons))
                        <div class="empty-card" style="padding:1.2rem;">
                            <i class="fas fa-check-circle"></i>
                            <div>No returns this period — clean!</div>
                        </div>
                    @else
                        @php
                            $maxCount = max(array_map(fn($r) => $r['count'], $reasons) + [1]) ?: 1;
                            $reasonColors = ['#ef4444', '#f97316', '#f59e0b', '#fb923c', '#f87171'];
                        @endphp
                        @foreach ($reasons as $i => $r)
                            <div class="reason-row">
                                <div class="reason-lbl">
                                    <span class="num">{{ $i + 1 }}</span>{{ $r['reason'] }}
                                </div>
                                <div class="reason-track">
                                    <div class="reason-fill" style="width: {{ max(8, round(($r['count'] / $maxCount) * 100, 1)) }}%; background: linear-gradient(90deg, {{ $reasonColors[$i % count($reasonColors)] }}, {{ $reasonColors[($i + 1) % count($reasonColors)] }});"></div>
                                </div>
                                <div class="reason-meta">{{ $r['count'] }}× · ৳{{ number_format($r['value'], 0) }}</div>
                            </div>
                        @endforeach
                    @endif
                </div>
            </div>
        </div>
    </div>

    </div>
    @endif {{-- end of Phase 2 Collections & Returns block --}}

    {{-- ============================================================
         PHASE 3 — OPERATIONAL EFFICIENCY & PRODUCTIVITY (HOW YOU WORK)
         (Phase 5: role-aware — visible to all roles per the plan since
         every role creates activity; the section tells the user when/how
         intensely they work, which is universal.)
         Wrapped in a tab pane (#tab-productivity).
         ============================================================ --}}
    @if (isset($targetEmployee) && $targetEmployee && !$scaffoldingOnly && ($roleSections['operational'] ?? true))
    <div class="perf-tab-pane @if ($visibleTabs && array_key_first($visibleTabs) === 'productivity') active @endif" id="tab-productivity" role="tabpanel" aria-labelledby="perf-tab-btn-productivity">
    @php
        // Pull all Phase 3 datasets with safe defaults so missing data
        // doesn't break the markup.
        $vk = $velocityKpis ?? [
            'avg_invoice_to_godown_hrs' => null,
            'avg_godown_to_challan_hrs' => null,
            'avg_invoice_to_challan_hrs' => null,
            'same_day_dispatch_pct' => 0.0,
            'dispatched_count' => 0, 'total_invoices' => 0,
        ];
        $pipe = $pipelineSnapshot ?? [
            'stale_draft_count' => 0, 'open_pipeline_value' => 0.0,
            'parked_sales_count' => 0, 'draft_count' => 0,
            'confirmed_pending_dispatch' => 0,
        ];
        $wp = $workPattern ?? array_map(fn($h) => ['hour' => $h, 'count' => 0], range(0, 23));
        $act = $activitySummary ?? [
            'transactions_per_day' => 0.0, 'active_days_cross_table' => 0,
            'total_activity' => 0, 'peak_day' => null, 'peak_day_count' => 0,
        ];
        $ne = $notificationEngagement ?? ['read_rate' => 0.0, 'total' => 0, 'unread' => 0, 'read' => 0];

        // Format hours as "Xh Ym" if value present, else "—"
        $fmtHrs = function (?float $v): string {
            if ($v === null) return '—';
            $h = (int) floor($v);
            $m = (int) round(($v - $h) * 60);
            if ($m === 60) { $h++; $m = 0; }
            return $h . 'h ' . ($m > 0 ? $m . 'm' : '');
        };

        // Peak hour calc for work-pattern badge + histogram highlight
        $wpCounts = array_map(fn($r) => $r['count'], $wp);
        $wpMax = max($wpCounts + [0]) ?: 0;
        $wpPeakHour = $wpMax > 0 ? array_keys($wpCounts, $wpMax)[0] : null;
        $wpTotal = array_sum($wpCounts);
        $wpPeakLabel = $wpPeakHour !== null
            ? sprintf('%02d:00–%02d:00', $wpPeakHour, ($wpPeakHour + 1) % 24)
            : '—';

        // Velocity gradient palettes per tile
        $velPalette = [
            'i2g' => 'linear-gradient(135deg, #4f46e5, #7c3aed)',  // indigo→violet
            'g2c' => 'linear-gradient(135deg, #0ea5e9, #2563eb)',  // sky→blue
            'i2c' => 'linear-gradient(135deg, #10b981, #047857)',  // emerald
            'sdd' => 'linear-gradient(135deg, #f59e0b, #d97706)',  // amber
        ];

        // Same-day dispatch progress % (vs target 80%)
        $sddTarget = 80;
        $sddProgress = min(100, ($vk['same_day_dispatch_pct'] / $sddTarget) * 100);

        // Velocity progress bars: faster = better. Map hours → % (24h = 0%, 0h = 100%)
        $velBarPct = function (?float $v) {
            if ($v === null) return 0;
            return max(5, min(100, 100 - ($v / 24) * 100));
        };

        // Notification read-rate ring color
        $neColor = $ne['read_rate'] >= 70 ? '#10b981' : ($ne['read_rate'] >= 40 ? '#f59e0b' : '#ef4444');
    @endphp

    <h3 class="section-h mt-4"><span class="bar"></span><i class="fas fa-user-clock text-info"></i> How You Work</h3>

    {{-- ===== Velocity KPI row — 4 tiles ===== --}}
    <div class="row g-3 mb-3">
        {{-- Invoice → Godown --}}
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="vel-tile" style="--vaccent: {{ $velPalette['i2g'] }};">
                <div class="vel-icon"><i class="fas fa-warehouse"></i></div>
                <div class="vel-label">Invoice → Godown</div>
                <div class="vel-value mono">{{ $fmtHrs($vk['avg_invoice_to_godown_hrs']) }}</div>
                <div class="vel-sub">Avg time from booking to godown-prep ready</div>
                <div class="vel-bar"><div style="width: {{ $velBarPct($vk['avg_invoice_to_godown_hrs']) }}%;"></div></div>
            </div>
        </div>

        {{-- Godown → Challan --}}
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="vel-tile" style="--vaccent: {{ $velPalette['g2c'] }};">
                <div class="vel-icon"><i class="fas fa-truck-loading"></i></div>
                <div class="vel-label">Godown → Challan</div>
                <div class="vel-value mono">{{ $fmtHrs($vk['avg_godown_to_challan_hrs']) }}</div>
                <div class="vel-sub">Avg time from godown-prep to dispatch</div>
                <div class="vel-bar"><div style="width: {{ $velBarPct($vk['avg_godown_to_challan_hrs']) }}%;"></div></div>
            </div>
        </div>

        {{-- Invoice → Challan (end-to-end velocity) --}}
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="vel-tile" style="--vaccent: {{ $velPalette['i2c'] }};">
                <div class="vel-icon"><i class="fas fa-stopwatch"></i></div>
                <div class="vel-label">End-to-End Velocity</div>
                <div class="vel-value mono">{{ $fmtHrs($vk['avg_invoice_to_challan_hrs']) }}</div>
                <div class="vel-sub">Invoice → challan issued (full cycle)</div>
                <div class="vel-bar"><div style="width: {{ $velBarPct($vk['avg_invoice_to_challan_hrs']) }}%;"></div></div>
            </div>
        </div>

        {{-- Same-Day Dispatch --}}
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="vel-tile" style="--vaccent: {{ $velPalette['sdd'] }};">
                <div class="vel-icon"><i class="fas fa-truck-fast"></i></div>
                <div class="vel-label">Same-Day Dispatch</div>
                <div class="vel-value mono">{{ $vk['same_day_dispatch_pct'] }}<span class="unit">%</span></div>
                <div class="vel-sub">{{ $vk['dispatched_count'] }} of {{ $vk['total_invoices'] }} invoices dispatched same-day</div>
                <div class="vel-bar"><div style="width: {{ $sddProgress }}%;"></div></div>
            </div>
        </div>
    </div>

    {{-- ===== Activity summary chips row — 3 small gradient chips ===== --}}
    <div class="row g-3 mb-3">
        <div class="col-12 col-md-4">
            <div class="act-chip teal">
                <div class="ac-lbl"><i class="fas fa-bolt me-1"></i>Transactions / Day</div>
                <div class="ac-val mono">{{ $act['transactions_per_day'] }}</div>
                <div class="ac-sub">Avg activity intensity on days you work</div>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="act-chip fuchsia">
                <div class="ac-lbl"><i class="fas fa-calendar-day me-1"></i>Active Days (cross-table)</div>
                <div class="ac-val mono">{{ $act['active_days_cross_table'] }}</div>
                <div class="ac-sub">{{ $act['total_activity'] }} total transactions across 6 tables</div>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="act-chip cyan">
                <div class="ac-lbl"><i class="fas fa-fire me-1"></i>Peak Day</div>
                <div class="ac-val mono">{{ $act['peak_day'] ? \Carbon\Carbon::parse($act['peak_day'])->format('M j') : '—' }}</div>
                <div class="ac-sub">{{ $act['peak_day_count'] }} transactions — your busiest day</div>
            </div>
        </div>
    </div>

    {{-- ===== Charts row: Work Pattern histogram (8) + Pipeline Snapshot (4) ===== --}}
    <div class="row g-3 mb-3">
        {{-- Work Pattern — 24-hour histogram --}}
        <div class="col-12 col-xl-8">
            <div class="hist-card">
                <div class="hist-head">
                    <div>
                        <div class="hist-title"><i class="fas fa-clock-rotate-left text-info"></i> Work Pattern — Hour of Day</div>
                        <div class="hist-sub">When you do your work — 24-hour activity histogram across all tables</div>
                    </div>
                    @if ($wpPeakHour !== null)
                        <span class="peak-badge"><i class="fas fa-bolt me-1"></i>Peak: {{ $wpPeakLabel }}</span>
                    @endif
                </div>
                <div class="hist-wrap">
                    <canvas id="workPatternChart"></canvas>
                </div>
            </div>
        </div>

        {{-- Pipeline Snapshot --}}
        <div class="col-12 col-xl-4">
            <div class="chart-card" style="padding: 1.1rem 1.25rem;">
                <div class="chart-title"><i class="fas fa-tasks text-warning"></i> Pipeline Snapshot</div>
                <div class="chart-sub">Your work-in-progress (point-in-time)</div>

                <div class="pipe-item">
                    <div class="pipe-icon amber"><i class="fas fa-file-circle-exclamation"></i></div>
                    <div class="pipe-info">
                        <div class="pipe-name">Stale Drafts</div>
                        <div class="pipe-meta">Drafts older than 7 days</div>
                    </div>
                    <div class="pipe-val">{{ $pipe['stale_draft_count'] }}</div>
                </div>

                <div class="pipe-item">
                    <div class="pipe-icon blue"><i class="fas fa-truck-arrow-right"></i></div>
                    <div class="pipe-info">
                        <div class="pipe-name">Open Pipeline</div>
                        <div class="pipe-meta">Confirmed · awaiting dispatch</div>
                    </div>
                    <div class="pipe-val">৳ {{ number_format($pipe['open_pipeline_value'], 0) }}</div>
                </div>

                <div class="pipe-item">
                    <div class="pipe-icon rose"><i class="fas fa-pause"></i></div>
                    <div class="pipe-info">
                        <div class="pipe-name">Parked Sales</div>
                        <div class="pipe-meta">call_a_day = true</div>
                    </div>
                    <div class="pipe-val">{{ $pipe['parked_sales_count'] }}</div>
                </div>

                <div class="pipe-item">
                    <div class="pipe-icon green"><i class="fas fa-file-pen"></i></div>
                    <div class="pipe-info">
                        <div class="pipe-name">All Drafts</div>
                        <div class="pipe-meta">Total drafts on your book</div>
                    </div>
                    <div class="pipe-val">{{ $pipe['draft_count'] }}</div>
                </div>
            </div>
        </div>
    </div>

    {{-- ===== Bottom row: Notification engagement ring ===== --}}
    <div class="row g-3 mb-3">
        <div class="col-12">
            <div class="notif-card">
                <div class="chart-title"><i class="fas fa-bell text-purple"></i> Notification Engagement</div>
                <div class="chart-sub">How well you keep up with system alerts</div>
                <div class="notif-grid">
                    <div class="notif-ring">
                        <canvas id="notifRing"></canvas>
                        <div class="ring-center">
                            <div class="ring-pct mono">{{ $ne['read_rate'] }}<span style="font-size:0.78rem;">%</span></div>
                            <div class="ring-cap">read</div>
                        </div>
                    </div>
                    <div class="notif-stats">
                        <div class="ns-row">
                            <span class="ns-dot" style="background: #10b981;"></span>
                            Read
                            <span class="ns-num">{{ $ne['read'] }}</span>
                        </div>
                        <div class="ns-row">
                            <span class="ns-dot" style="background: #ef4444;"></span>
                            Unread
                            <span class="ns-num">{{ $ne['unread'] }}</span>
                        </div>
                        <div class="ns-row">
                            <span class="ns-dot" style="background: #64748b;"></span>
                            Total received
                            <span class="ns-num">{{ $ne['total'] }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    </div>
    @endif {{-- end of Phase 3 How You Work block --}}

    {{-- ============================================================
         PHASE 4 — COMMISSION, STOCK DISCIPLINE & ACCURACY
         ============================================================
         Commission block is ROLE-AWARE: only rendered when the target
         employee's role is 'salesman' (the only role with commission
         rules + entries in the schema). Stock Discipline and Accuracy
         are rendered for everyone — every user creates adjustments,
         invoices, payments, etc.

         Phase 5: now also gated by $roleSections so warehouse_manager /
         dispatcher don't see commission, accountant doesn't see stock
         discipline, etc. The outer @if renders the block if ANY of the
         three sub-sections is visible.
         --}}
    @if (isset($targetEmployee) && $targetEmployee && !$scaffoldingOnly && (
        ($roleSections['commission'] ?? false) ||
        ($roleSections['stock_discipline'] ?? false) ||
        ($roleSections['accuracy'] ?? false)
    ))
    <div class="perf-tab-pane @if ($visibleTabs && array_key_first($visibleTabs) === 'commission') active @endif" id="tab-commission" role="tabpanel" aria-labelledby="perf-tab-btn-commission">
    @php
        // Pull all Phase 4 datasets with safe defaults so missing data
        // doesn't break the markup.
        $cm = $commissionSummary ?? [
            'net_commission' => 0.0, 'calculated' => 0.0, 'confirmed' => 0.0,
            'paid' => 0.0, 'reversed' => 0.0, 'total_to_date' => 0.0,
            'attainment_pct' => 0.0, 'target_amount' => 0.0,
            'sales_to_date' => 0.0, 'has_rule' => false,
            'rule_type' => null, 'rate' => null, 'period_label' => '',
        ];
        $sd = $stockDiscipline ?? [
            'adjustments_initiated' => 0, 'adjustment_value' => 0.0,
            'loss_adjustments' => 0, 'accountable_damages' => 0.0,
            'accountable_damages_count' => 0, 'damage_recovery' => 0.0,
            'stock_take_variances' => 0, 'transfers_initiated' => 0,
        ];
        $ax = $accuracyKpis ?? [
            'reversed_invoices' => 0, 'cancelled_invoices' => 0,
            'reversed_payments' => 0, 'reversed_returns' => 0,
            'reversed_challans' => 0, 'manual_journals' => 0,
            'total_actions' => 0, 'composite_error_rate' => 0.0,
        ];

        // Role guard: commission block shows only for salesman role.
        // (super-admin viewing a non-salesman employee also won't see it,
        // which matches the plan's "salesman-role users only" rule.)
        $isSalesman = optional($targetEmployee)->role === 'salesman';

        // Attainment gauge color thresholds
        $attainPct = $cm['attainment_pct'];
        $attainColor = $attainPct >= 100 ? '#10b981'
                     : ($attainPct >= 70  ? '#f59e0b'
                     : '#ef4444');
        $attainClass = $attainPct >= 100 ? 'good'
                     : ($attainPct >= 70  ? 'mid'
                     : 'low');
        $attainMsg = $attainPct >= 100 ? 'Target achieved'
                   : ($attainPct >= 70  ? 'On track'
                   : 'Behind target');

        // Target progress bar — cap visually at 100% (attainment may be > 100%
        // which we mark with .over gradient). Milestone ticks at 50% and 100%.
        $targetFillPct = min(100, $attainPct);
        $targetIsOver = $attainPct >= 100;

        // Rule type label
        $ruleTypeLabel = match($cm['rule_type']) {
            'flat'         => 'Flat %',
            'tiered'       => 'Tiered',
            'product_group'=> 'By Group',
            'target_bonus' => 'Target Bonus',
            default        => 'No rule',
        };

        // Commission status breakdown donut data
        $cmStatusData = [
            ['label' => 'Calculated', 'value' => $cm['calculated'], 'color' => '#94a3b8'],
            ['label' => 'Confirmed',  'value' => $cm['confirmed'],  'color' => '#4f46e5'],
            ['label' => 'Paid',       'value' => $cm['paid'],       'color' => '#10b981'],
            ['label' => 'Reversed',   'value' => abs($cm['reversed']), 'color' => '#ef4444'],
        ];
        $cmStatusTotal = max(array_sum(array_map(fn($s) => $s['value'], $cmStatusData)), 0.0001);

        // Stock discipline tile accent palette
        $sdPalette = [
            'adjustments' => '#4f46e5',
            'loss'        => '#f59e0b',
            'damages'     => '#ef4444',
            'variances'   => '#0ea5e9',
            'transfers'   => '#14b8a6',
        ];

        // Accuracy gauge thresholds (error rate — lower is better)
        // 0–1% green, 1–3% amber, 3%+ red
        $errRate = $ax['composite_error_rate'];
        $errColor = $errRate <= 1.0 ? '#10b981'
                  : ($errRate <= 3.0 ? '#f59e0b'
                  : '#ef4444');
        $errClass = $errRate <= 1.0 ? 'good'
                  : ($errRate <= 3.0 ? 'mid'
                  : 'low');
        $errMsg = $errRate <= 1.0 ? 'Excellent accuracy'
                : ($errRate <= 3.0 ? 'Acceptable — review reversals'
                : 'High error rate — coach needed');

        // Accuracy breakdown rows (only non-zero categories shown)
        $accRows = [
            ['label' => 'Reversed Invoices',  'count' => $ax['reversed_invoices'],  'color' => '#ef4444', 'icon' => 'fa-file-invoice-dollar'],
            ['label' => 'Cancelled Invoices', 'count' => $ax['cancelled_invoices'], 'color' => '#f97316', 'icon' => 'fa-ban'],
            ['label' => 'Reversed Payments',  'count' => $ax['reversed_payments'],  'color' => '#f59e0b', 'icon' => 'fa-undo-alt'],
            ['label' => 'Reversed Returns',   'count' => $ax['reversed_returns'],   'color' => '#fb923c', 'icon' => 'fa-rotate-left'],
            ['label' => 'Reversed Challans',  'count' => $ax['reversed_challans'],  'color' => '#f87171', 'icon' => 'fa-truck-loading'],
        ];
        $accTotalErrors = array_sum(array_map(fn($r) => $r['count'], $accRows));
        $accMaxCount = max(array_map(fn($r) => $r['count'], $accRows) + [1]);
    @endphp

    <h3 class="section-h mt-4"><span class="bar"></span><i class="fas fa-bullseye text-warning"></i> Commission, Stock Discipline &amp; Accuracy</h3>

    {{-- ============================================================
         ROW 1 — COMMISSION & TARGETS (salesman role + roleSections.commission)
         ============================================================ --}}
    @if ($isSalesman && ($roleSections['commission'] ?? false))
    <h4 class="phase-sub-h"><i class="fas fa-coins text-primary"></i> Commission &amp; Targets</h4>
    <div class="row g-3 mb-3">
        {{-- Commission hero — net commission this period --}}
        <div class="col-12 col-md-6 col-xl-3">
            <div class="comm-hero">
                <span class="ch-period">{{ $cm['period_label'] }}</span>
                <div class="ch-row">
                    <div class="ch-icon"><i class="fas fa-coins"></i></div>
                    <div>
                        <div class="ch-lbl">Net Commission</div>
                        <div class="ch-val mono">৳ {{ number_format($cm['net_commission'], 0) }}</div>
                    </div>
                </div>
                <div class="ch-sub">
                    @if ($cm['has_rule'])
                        Rate <strong>{{ number_format($cm['rate'], 2) }}%</strong> · {{ $ruleTypeLabel }}
                    @else
                        No active commission rule configured.
                    @endif
                </div>
            </div>
        </div>

        {{-- Attainment gauge --}}
        <div class="col-12 col-md-6 col-xl-3">
            <div class="attain-card">
                <div class="kpi-label text-center" style="margin-top:0.2rem;">Target Attainment</div>
                <div class="attain-gauge-wrap">
                    <canvas id="attainGauge"></canvas>
                    <div class="attain-readout">
                        <div class="attain-pct mono">{{ number_format($attainPct, 0) }}<span style="font-size:1rem;">%</span></div>
                        <div class="attain-cap">of monthly target</div>
                    </div>
                </div>
                <div class="attain-meta">
                    Sales <strong>৳ {{ number_format($cm['sales_to_date'], 0) }}</strong>
                    @if ($cm['target_amount'] > 0)
                        / Target <strong>৳ {{ number_format($cm['target_amount'], 0) }}</strong>
                    @endif
                </div>
            </div>
        </div>

        {{-- Target progress bar with milestones --}}
        <div class="col-12 col-md-6 col-xl-3">
            <div class="target-card">
                <div class="tc-head">
                    <span class="tc-title">Monthly Progress</span>
                    <span class="tc-rule">{{ $ruleTypeLabel }}</span>
                </div>
                <div class="tc-numbers">
                    <span class="tc-current mono">৳ {{ number_format($cm['sales_to_date'], 0) }}</span>
                    @if ($cm['target_amount'] > 0)
                        <span class="tc-target">of ৳ {{ number_format($cm['target_amount'], 0) }}</span>
                    @else
                        <span class="tc-target">no target set</span>
                    @endif
                </div>
                <div class="target-track">
                    <div class="target-fill {{ $targetIsOver ? 'over' : '' }}" style="width: {{ $targetFillPct }}%;"></div>
                    <div class="target-tick t-50"></div>
                    <div class="target-tick t-100"></div>
                </div>
                <div class="tc-ticks-lbl">
                    <span>0</span><span>50%</span><span>100%</span>
                </div>
                <span class="tc-status {{ $attainClass }}">{{ $attainMsg }}</span>
            </div>
        </div>

        {{-- Commission status breakdown donut --}}
        <div class="col-12 col-md-6 col-xl-3">
            <div class="comm-status-card">
                <div class="cs-head">
                    <span class="cs-title">Status Breakdown</span>
                    <span class="cs-sub">lifetime</span>
                </div>
                <div class="cs-row">
                    <div class="cs-canvas-wrap">
                        <canvas id="commStatusDonut"></canvas>
                        <div class="cs-center">
                            <div class="cs-center-val mono">৳ {{ number_format($cm['total_to_date'], 0) }}</div>
                            <div class="cs-center-lbl">total</div>
                        </div>
                    </div>
                    <div class="cs-legend">
                        @foreach ($cmStatusData as $s)
                            <div class="cs-leg-item">
                                <span class="cs-leg-dot" style="background: {{ $s['color'] }};"></span>
                                <span class="cs-leg-lbl">{{ $s['label'] }}</span>
                                <span class="cs-leg-val mono">৳ {{ number_format($s['value'], 0) }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>
    @else
    {{-- Non-salesman: show a small info note explaining the commission block is hidden --}}
    <div class="alert alert-info py-2 mb-3" style="font-size:0.82rem; border-radius:0.7rem;">
        <i class="fas fa-info-circle me-1"></i>
        Commission &amp; Targets are visible only to employees with the <strong>salesman</strong> role.
        This employee's role is <code>{{ $targetEmployee->role }}</code>, so the commission block is hidden.
    </div>
    @endif

    {{-- ============================================================
         ROW 2 — STOCK DISCIPLINE (Phase 5: gated by roleSections.stock_discipline)
         ============================================================ --}}
    @if ($roleSections['stock_discipline'] ?? false)
    <h4 class="phase-sub-h"><i class="fas fa-warehouse text-info"></i> Stock Discipline</h4>
    <div class="row g-3 mb-3">
        {{-- Adjustments Initiated --}}
        <div class="col-6 col-md-4 col-xl">
            <div class="sd-tile" style="--sd-accent: {{ $sdPalette['adjustments'] }};">
                <div class="sd-icon"><i class="fas fa-sliders-h"></i></div>
                <div class="sd-lbl">Adjustments Initiated</div>
                <div class="sd-val mono">{{ $sd['adjustments_initiated'] }}</div>
                <div class="sd-sub">stock adjustments you created this period</div>
            </div>
        </div>

        {{-- Loss Adjustment Value --}}
        <div class="col-6 col-md-4 col-xl">
            <div class="sd-tile" style="--sd-accent: {{ $sdPalette['loss'] }};">
                <div class="sd-icon"><i class="fas fa-arrow-trend-down"></i></div>
                <div class="sd-lbl">Loss Adj. Value</div>
                <div class="sd-val mono">৳ {{ number_format($sd['adjustment_value'], 0) }}</div>
                <div class="sd-sub">{{ $sd['loss_adjustments'] }} decrease-type adjustment(s)</div>
            </div>
        </div>

        {{-- Accountable Damages (red highlight if > 0) --}}
        <div class="col-6 col-md-4 col-xl {{ $sd['accountable_damages'] > 0 ? '' : '' }}">
            <div class="sd-tile {{ $sd['accountable_damages'] > 0 ? 'danger' : '' }}" style="--sd-accent: {{ $sdPalette['damages'] }};">
                <div class="sd-icon"><i class="fas fa-exclamation-triangle"></i></div>
                <div class="sd-lbl">Accountable Damages</div>
                <div class="sd-val mono">৳ {{ number_format($sd['accountable_damages'], 0) }}</div>
                <div class="sd-sub">{{ $sd['accountable_damages_count'] }} damage invoice(s) attributed to you</div>
            </div>
        </div>

        {{-- Stock-Take Variances --}}
        <div class="col-6 col-md-4 col-xl">
            <div class="sd-tile" style="--sd-accent: {{ $sdPalette['variances'] }};">
                <div class="sd-icon"><i class="fas fa-clipboard-check"></i></div>
                <div class="sd-lbl">Stock-Take Variances</div>
                <div class="sd-val mono">{{ $sd['stock_take_variances'] }}</div>
                <div class="sd-sub">reconciliation_variance adjustments this period</div>
            </div>
        </div>

        {{-- Transfers Initiated --}}
        <div class="col-6 col-md-4 col-xl">
            <div class="sd-tile" style="--sd-accent: {{ $sdPalette['transfers'] }};">
                <div class="sd-icon"><i class="fas fa-exchange-alt"></i></div>
                <div class="sd-lbl">Transfers Initiated</div>
                <div class="sd-val mono">{{ $sd['transfers_initiated'] }}</div>
                <div class="sd-sub">warehouse transfers you created this period</div>
            </div>
        </div>
    </div>
    @endif {{-- end of Stock Discipline sub-section --}}

    {{-- ============================================================
         ROW 3 — ACCURACY SCORECARD (Phase 5: gated by roleSections.accuracy)
         ============================================================ --}}
    @if ($roleSections['accuracy'] ?? false)
    <h4 class="phase-sub-h"><i class="fas fa-bullseye text-success"></i> Accuracy Scorecard</h4>
    <div class="row g-3 mb-3">
        {{-- Composite error-rate gauge --}}
        <div class="col-12 col-md-6 col-xl-5">
            <div class="acc-gauge-card">
                <div class="ag-head">
                    <span class="ag-title">Composite Error Rate</span>
                    <span class="ag-sub">{{ $ax['total_actions'] }} actions this period</span>
                </div>
                <div class="ag-wrap">
                    <canvas id="accuracyGauge"></canvas>
                    <div class="ag-readout">
                        <div class="ag-pct mono {{ $errClass }}">{{ number_format($errRate, 2) }}<span style="font-size:1rem;">%</span></div>
                        <div class="ag-cap">reversed + cancelled / total</div>
                    </div>
                </div>
                <div class="ag-footer">
                    <i class="fas fa-info-circle me-1"></i>
                    <strong>{{ $errMsg }}</strong>
                </div>
            </div>
        </div>

        {{-- Breakdown bars --}}
        <div class="col-12 col-md-6 col-xl-7">
            <div class="acc-breakdown-card">
                <div class="ab-head">
                    <span class="ab-title">Error Breakdown by Category</span>
                    <span class="ab-sub">{{ $accTotalErrors }} error(s) total</span>
                </div>
                @if ($accTotalErrors === 0)
                    <div class="ab-empty">
                        <i class="fas fa-circle-check"></i>
                        <div>No reversals or cancellations this period. Pristine work!</div>
                    </div>
                @else
                    @foreach ($accRows as $r)
                        @if ($r['count'] > 0)
                            <div class="ab-row" style="--ab-color: {{ $r['color'] }};">
                                <div class="ab-lbl">
                                    <i class="fas {{ $r['icon'] }}" style="--ab-icon: {{ $r['color'] }};"></i>
                                    {{ $r['label'] }}
                                </div>
                                <div class="ab-track">
                                    <div class="ab-fill" style="width: {{ max(3, round(($r['count'] / $accMaxCount) * 100, 1)) }}%;"></div>
                                </div>
                                <div class="ab-num">{{ $r['count'] }}</div>
                            </div>
                        @endif
                    @endforeach
                @endif
            </div>
        </div>
    </div>
    @endif {{-- end of Accuracy sub-section --}}

    {{-- ============================================================
         PHASE 4 FOOTER NOTE (kept small; replaced the old
         "Phases 1, 2 & 3 complete" placeholder)
         ============================================================ --}}
    <div class="text-center text-muted small mt-3 mb-2">
        <i class="fas fa-check-circle text-success me-1"></i>
        <strong>Phases 1, 2, 3, 4 &amp; 5 complete.</strong> Sales + Collections &amp; Returns + How You Work + Commission, Stock Discipline &amp; Accuracy are all live, with role-aware visibility.
        @if (isset($customerPaymentsTxnType))
            <span class="ms-2">·</span>
            <span class="ms-2">G12 check: <code>customer_payments.transaction_type</code>
            @if ($customerPaymentsTxnType) <span class="text-success">exists</span> @else <span class="text-warning">missing</span> @endif
            </span>
        @endif
    </div>

    </div>
    @endif {{-- end of Phase 4 Commission, Stock Discipline & Accuracy block --}}

    {{-- ============================================================
         PHASE 5 — APPROVAL WORKLOAD (manager / admin / superadmin only)
         ============================================================
         Renders only when $roleSections['approval_workload'] is true.
         Uses the EXISTING approved_by / submitted_by columns on
         stock_adjustments and damage_invoices (no new migrations
         needed). "Pending my approval" counts are branch-wide (any
         manager in the branch can approve); "approved by me" counts
         are user-attributed via approved_by.
         --}}
    @if (isset($targetEmployee) && $targetEmployee && !$scaffoldingOnly && ($roleSections['approval_workload'] ?? false))
    <div class="perf-tab-pane @if ($visibleTabs && array_key_first($visibleTabs) === 'approvals') active @endif" id="tab-approvals" role="tabpanel" aria-labelledby="perf-tab-btn-approvals">
    @php
        $aw = $approvalWorkload ?? [
            'adjustments_pending_my_approval' => 0,
            'adjustments_approved_by_me'      => 0,
            'damages_pending_my_approval'     => 0,
            'damages_approved_by_me'          => 0,
            'total_pending_value'             => 0.0,
        ];

        // Total pending items = stock-adjustment pendings + damage pendings.
        // Urgency tier: 0 pending → green ("all caught up"), 1–5 → amber,
        // 6+ → red ("backlog").
        $totalPending = $aw['adjustments_pending_my_approval'] + $aw['damages_pending_my_approval'];
        $awUrgencyClass = $totalPending === 0 ? 'good' : ($totalPending <= 5 ? 'mid' : 'low');
        $awUrgencyMsg = $totalPending === 0 ? 'All caught up — no pending approvals'
                      : ($totalPending <= 5 ? 'Light queue — review soon'
                      : 'Backlog — review immediately');

        // Approved-this-period total (for the right-hand tile)
        $totalApproved = $aw['adjustments_approved_by_me'] + $aw['damages_approved_by_me'];
    @endphp

    <h3 class="section-h mt-4"><span class="bar"></span><i class="fas fa-check-double text-danger"></i> Approval Workload</h3>

    <div class="row g-3 mb-3">
        {{-- Pending approvals — the actionable tile (large, gradient) --}}
        <div class="col-12 col-md-6 col-xl-4">
            <div class="aw-hero aw-hero--{{ $awUrgencyClass }}">
                <div class="aw-row">
                    <div class="aw-icon"><i class="fas fa-inbox"></i></div>
                    <div>
                        <div class="aw-lbl">Pending My Approval</div>
                        <div class="aw-val mono">{{ $totalPending }}</div>
                    </div>
                </div>
                <div class="aw-sub">
                    <i class="fas fa-sliders-h me-1"></i>{{ $aw['adjustments_pending_my_approval'] }} stock adjustment(s)
                    &nbsp;·&nbsp;
                    <i class="fas fa-exclamation-triangle me-1"></i>{{ $aw['damages_pending_my_approval'] }} damage invoice(s)
                </div>
                <div class="aw-sub">
                    <i class="fas fa-coins me-1"></i>৳ {{ number_format($aw['total_pending_value'], 0) }} pending adjustment value
                </div>
                <span class="aw-urgency aw-urgency--{{ $awUrgencyClass }}">
                    <i class="fas fa-{{ $awUrgencyClass === 'good' ? 'check-circle' : ($awUrgencyClass === 'mid' ? 'clock' : 'exclamation-circle') }}"></i>
                    {{ $awUrgencyMsg }}
                </span>
            </div>
        </div>

        {{-- Approved this period — secondary tiles --}}
        <div class="col-12 col-md-6 col-xl-8">
            <div class="row g-3">
                <div class="col-12 col-sm-6">
                    <div class="aw-stat-tile" style="--aw-accent: #4f46e5;">
                        <div class="aw-stat-icon"><i class="fas fa-check"></i></div>
                        <div class="aw-stat-lbl">Adjustments Approved</div>
                        <div class="aw-stat-val mono">{{ $aw['adjustments_approved_by_me'] }}</div>
                        <div class="aw-stat-sub">stock adjustments you approved this period</div>
                    </div>
                </div>
                <div class="col-12 col-sm-6">
                    <div class="aw-stat-tile" style="--aw-accent: #ef4444;">
                        <div class="aw-stat-icon"><i class="fas fa-check-double"></i></div>
                        <div class="aw-stat-lbl">Damages Approved</div>
                        <div class="aw-stat-val mono">{{ $aw['damages_approved_by_me'] }}</div>
                        <div class="aw-stat-sub">damage invoices you approved this period</div>
                    </div>
                </div>
                <div class="col-12 col-sm-6">
                    <div class="aw-stat-tile" style="--aw-accent: #10b981;">
                        <div class="aw-stat-icon"><i class="fas fa-trophy"></i></div>
                        <div class="aw-stat-lbl">Total Approvals</div>
                        <div class="aw-stat-val mono">{{ $totalApproved }}</div>
                        <div class="aw-stat-sub">combined approvals this period</div>
                    </div>
                </div>
                <div class="col-12 col-sm-6">
                    <div class="aw-stat-tile" style="--aw-accent: #f59e0b;">
                        <div class="aw-stat-icon"><i class="fas fa-hourglass-half"></i></div>
                        <div class="aw-stat-lbl">Pending Value</div>
                        <div class="aw-stat-val mono">৳ {{ number_format($aw['total_pending_value'], 0) }}</div>
                        <div class="aw-stat-sub">total value of pending stock adjustments</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    </div>
    @endif {{-- end of Phase 5 Approval Workload block --}}

</div>
@endsection

@push('scripts')
{{-- Chart.js — already on the legacy dashboard view; load locally for this page.
     Phase 6: skipped in fragment mode (Chart.js is already loaded on the host
     page; reloading would reset Chart global state and break existing charts). --}}
@if (!($fragmentMode ?? false))
<script src="/assets/js/bootstrep/chart.umd.min.js"></script>
@endif

<script>
{{-- ============================================================
     PHASE 6 — RE-INITIALIZABLE CHART INIT
     ============================================================
     The chart init code is wrapped in window.initPerfDashboard() so the
     AJAX fragment refresher can call it again after swapping the
     #perf-dashboard innerHTML. Each call:

       1. Destroys any existing Chart.js instances attached to canvases
          inside #perf-dashboard (tracked in window.__perfCharts[]).
       2. Re-creates them from the freshly-injected @json data.

     Without this destroy step, Chart.js leaks <canvas> observers and
     the page accumulates dead chart instances on every period/employee
     switch — eventually causing "Maximum call stack exceeded" or
     jank. With it, the swap is clean and fast (<50ms on a dev laptop).
--}}
window.initPerfDashboard = function () {
    // ── 0. Restore the active tab BEFORE initialising charts.
    //       Chart.js canvases inside display:none panes get a 0×0 size
    //       on creation; we restore the active pane first so the charts
    //       in that pane measure the correct parent size. Charts in
    //       hidden panes are still created (they just have a default
    //       300×150 canvas size) and get .resize()'d when their pane
    //       becomes visible (see switchTab()).
    //       Active tab priority: URL hash > sessionStorage > first tab.
    //       ──────────────────────────────────────────────────────
    const perfRoot0 = document.getElementById('perf-dashboard');
    if (perfRoot0) {
        let activeTab = null;
        // 1. URL hash takes precedence (shareable links).
        if (window.location.hash && /^#tab-[\w-]+$/.test(window.location.hash)) {
            activeTab = window.location.hash.substring(1);
        }
        // 2. Fall back to sessionStorage (survives AJAX refresh + page reload).
        if (!activeTab) {
            try { activeTab = sessionStorage.getItem('perf-tab'); } catch (e) {}
        }
        // 3. Validate that the tab actually exists in this user's view.
        const panes = perfRoot0.querySelectorAll('.perf-tab-pane');
        const paneIds = Array.from(panes).map(p => p.id);
        if (!activeTab || paneIds.indexOf(activeTab) === -1) {
            activeTab = paneIds[0] || null;
        }
        if (activeTab) {
            // Defer to next tick so all DOM is ready.
            setTimeout(function () {
                if (typeof window.switchPerfTab === 'function') {
                    window.switchPerfTab(activeTab, { silent: true });
                } else {
                    // Fallback: just toggle classes directly.
                    perfRoot0.querySelectorAll('.perf-tab-pane').forEach(function (p) {
                        p.classList.toggle('active', p.id === activeTab);
                    });
                    perfRoot0.querySelectorAll('.perf-tab').forEach(function (b) {
                        const isActive = b.dataset.tab === activeTab;
                        b.classList.toggle('active', isActive);
                        b.setAttribute('aria-selected', isActive ? 'true' : 'false');
                    });
                }
            }, 0);
        }
    }

    // ── 1. Destroy any existing Chart instances on #perf-dashboard
    //       canvases. Chart.js v3+ exposes Chart.getChart(canvas) which
    //       returns the instance bound to that canvas (or undefined).
    //       We scan every <canvas> inside #perf-dashboard and destroy
    //       whatever we find. This is the safest cross-version way to
    //       clean up before re-creating charts after an AJAX swap —
    //       no need to track instances manually.
    //       ──────────────────────────────────────────────────────
    const perfRoot = document.getElementById('perf-dashboard');
    if (perfRoot && window.Chart && typeof window.Chart.getChart === 'function') {
        perfRoot.querySelectorAll('canvas').forEach(function (canvas) {
            try {
                const existing = window.Chart.getChart(canvas);
                if (existing && typeof existing.destroy === 'function') {
                    existing.destroy();
                }
            } catch (e) { /* ignore — stale canvas */ }
        });
    }

    // ============================================================
    // 1. Sales Trend — dual-axis line+bar chart
    // ============================================================
    const trendData = {!! json_encode($salesTrend ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) !!};
    const trendEl = document.getElementById('salesTrendChart');
    if (trendEl && trendData.length) {
        const labels = trendData.map(d => {
            // Short date: "Jul 15"
            const dt = new Date(d.date + 'T00:00:00');
            return dt.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
        });
        const values = trendData.map(d => Number(d.total_sales));
        const counts = trendData.map(d => Number(d.invoice_count));

        // Gradient fill for the line
        const ctx = trendEl.getContext('2d');
        const grad = ctx.createLinearGradient(0, 0, 0, 280);
        grad.addColorStop(0, 'rgba(79, 70, 229, 0.35)');
        grad.addColorStop(1, 'rgba(79, 70, 229, 0.02)');

        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [
                    {
                        type: 'line',
                        label: 'Sales Value (৳)',
                        data: values,
                        borderColor: '#4f46e5',
                        backgroundColor: grad,
                        borderWidth: 2.5,
                        tension: 0.35,
                        fill: true,
                        pointRadius: 0,
                        pointHoverRadius: 6,
                        pointHoverBackgroundColor: '#4f46e5',
                        pointHoverBorderColor: '#fff',
                        pointHoverBorderWidth: 2,
                        yAxisID: 'y',
                    },
                    {
                        type: 'bar',
                        label: 'Invoice Count',
                        data: counts,
                        backgroundColor: 'rgba(14, 165, 233, 0.55)',
                        borderColor: 'rgba(14, 165, 233, 0.9)',
                        borderWidth: 0,
                        borderRadius: 4,
                        maxBarThickness: 14,
                        yAxisID: 'y1',
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: {
                        position: 'top', align: 'end',
                        labels: { boxWidth: 10, boxHeight: 10, usePointStyle: true, font: { size: 11 } }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(15, 23, 42, 0.95)',
                        titleFont: { size: 12, weight: '600' },
                        bodyFont: { size: 12 },
                        padding: 10,
                        cornerRadius: 8,
                        callbacks: {
                            label: function (ctx) {
                                if (ctx.dataset.yAxisID === 'y') {
                                    return ' ' + ctx.dataset.label + ': ৳' + Number(ctx.parsed.y).toLocaleString();
                                }
                                return ' ' + ctx.dataset.label + ': ' + ctx.parsed.y;
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { font: { size: 10 }, color: '#64748b', maxRotation: 0, autoSkip: true, maxTicksLimit: 12 }
                    },
                    y: {
                        position: 'left',
                        beginAtZero: true,
                        grid: { color: '#f1f5f9' },
                        ticks: {
                            font: { size: 10 }, color: '#64748b',
                            callback: function (v) { return '৳' + (v >= 1000 ? (v/1000).toFixed(0) + 'k' : v); }
                        }
                    },
                    y1: {
                        position: 'right',
                        beginAtZero: true,
                        grid: { drawOnChartArea: false },
                        ticks: { font: { size: 10 }, color: '#0ea5e9', stepSize: 1 }
                    }
                }
            }
        });
    } else if (trendEl) {
        // No data — show empty state inside the canvas wrap
        trendEl.parentElement.innerHTML = '<div class="empty-card"><i class="fas fa-folder-open"></i><div>No sales recorded in this period yet.</div></div>';
    }

    // ============================================================
    // 2. Mini sparklines on each KPI card
    // ============================================================
    document.querySelectorAll('canvas.spark').forEach(function (cv) {
        const raw = cv.getAttribute('data-values');
        if (!raw) return;
        const values = raw.split(',').map(Number).filter(n => !isNaN(n));
        if (values.length < 2) return;
        const color = cv.getAttribute('data-color') || '#4f46e5';
        // Compact sparkline — no axes, no legend, just the line + fill
        new Chart(cv.getContext('2d'), {
            type: 'line',
            data: {
                labels: values.map((_, i) => i),
                datasets: [{
                    data: values,
                    borderColor: color,
                    backgroundColor: color + '22',
                    borderWidth: 1.5,
                    tension: 0.4,
                    fill: true,
                    pointRadius: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false }, tooltip: { enabled: false } },
                scales: { x: { display: false }, y: { display: false } },
                animation: { duration: 800 }
            }
        });
    });
    // ============================================================
    // 3. Phase 2 — Collection Rate gauge (semicircular doughnut)
    // ============================================================
    // We use a half-doughnut (rotation -90deg, circumference 180) with two
    // segments: the achieved % and the remainder. A needle/center readout
    // is rendered as HTML overlay (.gauge-readout) — keeps the canvas simple.
    const gaugeEl = document.getElementById('collectionGauge');
    if (gaugeEl && typeof Chart !== 'undefined') {
        const rate = Math.max(0, Math.min(100, Number({!! json_encode($ck['collection_rate'] ?? 0, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) !!})));
        // Color shifts with severity: red → amber → green
        let gaugeColor = '#ef4444';
        if (rate >= 80) gaugeColor = '#10b981';
        else if (rate >= 50) gaugeColor = '#f59e0b';

        new Chart(gaugeEl.getContext('2d'), {
            type: 'doughnut',
            data: {
                datasets: [{
                    data: [rate, Math.max(0.0001, 100 - rate)],
                    backgroundColor: [gaugeColor, '#f1f5f9'],
                    borderWidth: 0,
                    circumference: 180,
                    rotation: 270,
                    cutout: '72%',
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false }, tooltip: { enabled: false } },
                animation: { animateRotate: true, duration: 1100, easing: 'easeOutCubic' }
            }
        });
    }

    // ============================================================
    // 4. Phase 2 — Payment Mode Mix donut
    // ============================================================
    const pmixEl = document.getElementById('pmixDonut');
    const pmixData = {!! json_encode($pmix ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) !!};
    // pmixPalette is defined in the PHP block above; no inline fallback needed
    // (Blade's json directive regex doesn't reliably handle multi-line arrays).
    const pmixPalette = {!! json_encode($pmixPalette ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) !!};
    if (pmixEl && pmixData.length && typeof Chart !== 'undefined') {
        new Chart(pmixEl.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: pmixData.map(p => p.label),
                datasets: [{
                    data: pmixData.map(p => p.value),
                    backgroundColor: pmixData.map(p => pmixPalette[p.mode] || '#94a3b8'),
                    borderWidth: 2,
                    borderColor: '#fff',
                    hoverOffset: 6,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '68%',
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: 'rgba(15, 23, 42, 0.95)',
                        titleFont: { size: 12, weight: '600' },
                        bodyFont: { size: 12 },
                        padding: 10,
                        cornerRadius: 8,
                        callbacks: {
                            label: function (ctx) {
                                const p = pmixData[ctx.dataIndex];
                                return ' ' + p.label + ': ৳' + Number(p.value).toLocaleString() + ' (' + p.share + '%)';
                            }
                        }
                    }
                },
                animation: { animateRotate: true, animateScale: true, duration: 900 }
            }
        });
    }

    // ============================================================
    // 5. Phase 3 — Work Pattern histogram (24-bin hour-of-day)
    // ============================================================
    const wpEl = document.getElementById('workPatternChart');
    const wpData = {!! json_encode($wp ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) !!};
    const wpPeakHour = {!! json_encode($wpPeakHour ?? null, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) !!};
    if (wpEl && typeof Chart !== 'undefined') {
        const labels = wpData.map(d => {
            // "09:00"
            return String(d.hour).padStart(2, '0') + ':00';
        });
        const counts = wpData.map(d => Number(d.count));

        // Background color array — peak hour highlighted, business hours (9-18) a brighter shade
        const bgColors = wpData.map(d => {
            if (wpPeakHour !== null && d.hour === wpPeakHour) return '#f59e0b';  // amber peak
            if (d.hour >= 9 && d.hour < 18) return '#4f46e5';                    // indigo business
            if (d.hour >= 6 && d.hour < 22) return 'rgba(79, 70, 229, 0.55)';    // soft extended
            return 'rgba(100, 116, 139, 0.35)';                                  // off-hours muted
        });
        const borderColors = wpData.map(d => {
            if (wpPeakHour !== null && d.hour === wpPeakHour) return '#d97706';
            return 'transparent';
        });

        // Gradient fill for the bar chart area
        const ctx = wpEl.getContext('2d');

        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Activity Count',
                    data: counts,
                    backgroundColor: bgColors,
                    borderColor: borderColors,
                    borderWidth: 1,
                    borderRadius: 4,
                    maxBarThickness: 22,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: 'rgba(15, 23, 42, 0.95)',
                        titleFont: { size: 12, weight: '600' },
                        bodyFont: { size: 12 },
                        padding: 10,
                        cornerRadius: 8,
                        callbacks: {
                            title: function (items) {
                                const h = items[0].label;
                                const nextH = String((Number(h.slice(0, 2)) + 1) % 24).padStart(2, '0') + ':00';
                                return h + ' – ' + nextH;
                            },
                            label: function (ctx) {
                                return ' ' + ctx.parsed.y + ' transactions';
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: {
                            font: { size: 9 },
                            color: '#64748b',
                            maxRotation: 0,
                            autoSkip: true,
                            maxTicksLimit: 12,
                            callback: function (val, idx) {
                                // Show every 3rd hour: 00, 03, 06, 09, 12, 15, 18, 21
                                return idx % 3 === 0 ? this.getLabelForValue(val) : '';
                            }
                        }
                    },
                    y: {
                        beginAtZero: true,
                        grid: { color: '#f1f5f9' },
                        ticks: {
                            font: { size: 10 },
                            color: '#64748b',
                            precision: 0,
                            stepSize: Math.max(1, Math.ceil(Math.max(...counts) / 5))
                        }
                    }
                },
                animation: { duration: 900, easing: 'easeOutCubic' }
            }
        });
    }

    // ============================================================
    // 6. Phase 3 — Notification engagement ring (doughnut)
    // ============================================================
    const neEl = document.getElementById('notifRing');
    if (neEl && typeof Chart !== 'undefined') {
        const rate = Math.max(0, Math.min(100, Number({!! json_encode($ne['read_rate'] ?? 0, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) !!})));
        const neColor = {!! json_encode($neColor ?? '#94a3b8', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) !!};
        const total = Number({!! json_encode($ne['total'] ?? 0, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) !!});

        if (total > 0) {
            new Chart(neEl.getContext('2d'), {
                type: 'doughnut',
                data: {
                    datasets: [{
                        data: [rate, Math.max(0.0001, 100 - rate)],
                        backgroundColor: [neColor, '#f1f5f9'],
                        borderWidth: 0,
                        cutout: '72%',
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false }, tooltip: { enabled: false } },
                    animation: { animateRotate: true, duration: 1100, easing: 'easeOutCubic' }
                }
            });
        } else {
            // No notifications — render empty ring outline
            new Chart(neEl.getContext('2d'), {
                type: 'doughnut',
                data: {
                    datasets: [{
                        data: [1],
                        backgroundColor: ['#f1f5f9'],
                        borderWidth: 0,
                        cutout: '72%',
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false }, tooltip: { enabled: false } },
                    animation: { duration: 0 }
                }
            });
        }
    }

    // ============================================================
    // 7. Phase 4 — Commission status breakdown donut
    // ============================================================
    const csDonutEl = document.getElementById('commStatusDonut');
    if (csDonutEl && typeof Chart !== 'undefined') {
        const csData = {!! json_encode($cmStatusData ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) !!};
        const csTotal = {!! json_encode($cmStatusTotal ?? 0.0001, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) !!};
        if (csData.length && csTotal > 0) {
            const nonZero = csData.filter(s => s.value > 0);
            const data = nonZero.length ? nonZero : csData;
            const values = data.map(s => Math.max(0.0001, s.value));
            const colors = data.map(s => s.color);
            new Chart(csDonutEl.getContext('2d'), {
                type: 'doughnut',
                data: {
                    labels: data.map(s => s.label),
                    datasets: [{
                        data: values,
                        backgroundColor: colors,
                        borderWidth: 2,
                        borderColor: '#ffffff',
                        hoverOffset: 6,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '68%',
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: function(ctx) {
                                    const v = ctx.parsed;
                                    const pct = ((v / csTotal) * 100).toFixed(1);
                                    return ctx.label + ': ৳ ' + Math.round(v).toLocaleString() + ' (' + pct + '%)';
                                }
                            }
                        }
                    },
                    animation: { animateRotate: true, duration: 1000, easing: 'easeOutCubic' }
                }
            });
        } else {
            // Empty state — gray ring
            new Chart(csDonutEl.getContext('2d'), {
                type: 'doughnut',
                data: { datasets: [{ data: [1], backgroundColor: ['#f1f5f9'], borderWidth: 0 }] },
                options: {
                    responsive: true, maintainAspectRatio: false, cutout: '68%',
                    plugins: { legend: { display: false }, tooltip: { enabled: false } },
                    animation: { duration: 0 }
                }
            });
        }
    }

    // ============================================================
    // 8. Phase 4 — Target attainment semicircular gauge
    // ============================================================
    const attainEl = document.getElementById('attainGauge');
    if (attainEl && typeof Chart !== 'undefined') {
        const attainPct = Math.max(0, Math.min(150, Number({!! json_encode($cm['attainment_pct'] ?? 0, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) !!})));
        // For the gauge, we cap the visual at 100% (anything over 100% shows full + .over class)
        const visualPct = Math.min(100, attainPct);
        const attainColor = {!! json_encode($attainColor ?? '#ef4444', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) !!};
        new Chart(attainEl.getContext('2d'), {
            type: 'doughnut',
            data: {
                datasets: [{
                    data: [visualPct, Math.max(0.0001, 100 - visualPct)],
                    backgroundColor: [attainColor, '#f1f5f9'],
                    borderWidth: 0,
                    circumference: 180,
                    rotation: 270,
                    cutout: '72%',
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false }, tooltip: { enabled: false } },
                animation: { animateRotate: true, duration: 1100, easing: 'easeOutCubic' }
            }
        });
    }

    // ============================================================
    // 9. Phase 4 — Composite error-rate semicircular gauge
    // ============================================================
    // Error rate displayed on a 0–10% scale (anything > 10% pins the needle).
    const accGaugeEl = document.getElementById('accuracyGauge');
    if (accGaugeEl && typeof Chart !== 'undefined') {
        const errRateVal = Math.max(0, Math.min(10, Number({!! json_encode($ax['composite_error_rate'] ?? 0, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) !!})));
        // Scale 0–10% to 0–100% of the gauge arc
        const gaugePct = (errRateVal / 10) * 100;
        const errColor = {!! json_encode($errColor ?? '#10b981', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) !!};
        new Chart(accGaugeEl.getContext('2d'), {
            type: 'doughnut',
            data: {
                datasets: [{
                    data: [gaugePct, Math.max(0.0001, 100 - gaugePct)],
                    backgroundColor: [errColor, '#f1f5f9'],
                    borderWidth: 0,
                    circumference: 180,
                    rotation: 270,
                    cutout: '72%',
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false }, tooltip: { enabled: false } },
                animation: { animateRotate: true, duration: 1100, easing: 'easeOutCubic' }
            }
        });
    }
}; {{-- end of window.initPerfDashboard --}}

// ============================================================
// PHASE 6 — INITIAL CHART RENDER + AJAX FRAGMENT REFRESH
// ============================================================
//
// On the full-page load, we just call initPerfDashboard() once.
// In fragment mode, the host page's AJAX handler calls initPerfDashboard()
// after swapping the #perf-dashboard innerHTML — so we skip the auto-init
// in fragment mode (the host will trigger it).
//
// The AJAX refresh handler is ONLY attached on the full page (not in
// fragments). It intercepts:
//   • .btn-period clicks → switch period without full reload
//   • #employeeSwitchForm select change → switch employee
//   • popstate event → back/forward button
// Each fetch hit /dashboard/fragment, swaps #perf-dashboard innerHTML,
// re-runs initPerfDashboard(), and updates the URL via history.pushState
// so the link stays shareable.
//
// Visual polish: a skeleton overlay (.perf-skeleton-overlay) fades in
// during the fetch and fades out after the swap. Period pills get a
// subtle "active" transition. Charts animate in fresh on every swap.
//
@if (!($fragmentMode ?? false))
(function () {
    // Initial render — wait for Chart.js to be available.
    function bootCharts() {
        if (window.Chart && typeof window.initPerfDashboard === 'function') {
            window.initPerfDashboard();
        } else {
            // Chart.js not loaded yet — retry in 50ms (max 20 retries).
            if (!window.__perfBootRetries) window.__perfBootRetries = 0;
            if (window.__perfBootRetries < 20) {
                window.__perfBootRetries++;
                setTimeout(bootCharts, 50);
            }
        }
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bootCharts);
    } else {
        bootCharts();
    }

    // ============================================================
    // AJAX FRAGMENT REFRESH — Phase 6
    // ============================================================
    const FRAGMENT_URL = '{{ route("dashboard.fragment") }}';

    // Show the skeleton overlay (a translucent veil with a spinner that
    // covers just the #perf-dashboard region). The CSS lives in the
    // @@push('css') block above. NOTE: every literal "at-push" inside
    // this <script> block MUST be escaped as @@push or Blade will
    // interpret it as a real directive, swallow the rest of the script
    // into the wrong stack, and render the JS as visible text in <head>.
    // That was the root cause of the previous "all style and data broken"
    // bug — the bare at-symbol before "push('css')" here opened a NEW
    // css push section that captured everything from this point onward.
    function showSkeleton() {
        const root = document.getElementById('perf-dashboard');
        if (!root) return;
        root.classList.add('perf-refreshing');
        let overlay = root.querySelector('.perf-skeleton-overlay');
        if (!overlay) {
            overlay = document.createElement('div');
            overlay.className = 'perf-skeleton-overlay';
            overlay.innerHTML =
                '<div class="perf-skeleton-card">' +
                  '<div class="perf-skeleton-spinner"></div>' +
                  '<div class="perf-skeleton-text">Refreshing dashboard…</div>' +
                '</div>';
            root.appendChild(overlay);
        }
        // Force reflow so the transition fires.
        void overlay.offsetWidth;
        overlay.classList.add('visible');
    }

    function hideSkeleton() {
        const root = document.getElementById('perf-dashboard');
        if (!root) return;
        root.classList.remove('perf-refreshing');
        const overlay = root.querySelector('.perf-skeleton-overlay');
        if (overlay) {
            overlay.classList.remove('visible');
            // Detach after the fade-out transition completes.
            setTimeout(function () {
                if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
            }, 250);
        }
    }

    // ============================================================
    // SECTION TAB SWITCHING
    // ============================================================
    // window.switchPerfTab(tabId, opts) — switch the visible tab pane.
    // Called by:
    //   • tab button clicks (this IIFE's click listener)
    //   • initPerfDashboard() on page load / AJAX refresh (silent mode)
    //   • popstate / hashchange (browser back/forward to a #tab-X URL)
    //
    // Behaviour:
    //   1. Hide all panes, show the target pane.
    //   2. Update tab button active/aria-selected state.
    //   3. Resize all Chart.js instances inside the newly-shown pane
    //      (canvases created while inside display:none had 0×0 size;
    //      .resize() makes Chart.js re-measure the now-visible parent).
    //   4. Persist the tab to sessionStorage so it survives AJAX refresh.
    //   5. If opts.silent is false (default), also update location.hash
    //      so the tab is shareable. Silent mode skips hash update to
    //      avoid triggering a hashchange → switchTab loop.
    window.switchPerfTab = function (tabId, opts) {
        opts = opts || {};
        const root = document.getElementById('perf-dashboard');
        if (!root) return;
        const targetPane = root.querySelector('#' + tabId);
        if (!targetPane) return; // tab doesn't exist for this role

        // 1. Toggle pane visibility.
        root.querySelectorAll('.perf-tab-pane').forEach(function (p) {
            p.classList.toggle('active', p.id === tabId);
        });
        // 2. Toggle tab button state.
        root.querySelectorAll('.perf-tab').forEach(function (b) {
            const isActive = b.dataset.tab === tabId;
            b.classList.toggle('active', isActive);
            b.setAttribute('aria-selected', isActive ? 'true' : 'false');
        });

        // 3. Resize charts in the now-visible pane. Chart.js canvases
        //    created while inside display:none had a default 300×150
        //    size; .resize() forces a re-measure against the parent's
        //    actual width. We loop through all canvases in the pane
        //    and resize any Chart.js instances attached to them.
        if (window.Chart && typeof window.Chart.getChart === 'function') {
            targetPane.querySelectorAll('canvas').forEach(function (canvas) {
                try {
                    const c = window.Chart.getChart(canvas);
                    if (c && typeof c.resize === 'function') c.resize();
                } catch (e) { /* ignore */ }
            });
        }

        // 4. Persist to sessionStorage (survives AJAX refresh + reload).
        try { sessionStorage.setItem('perf-tab', tabId); } catch (e) {}

        // 5. Update URL hash so the tab is shareable.
        //    Non-silent mode (user clicked a tab): replaceState the hash
        //    so URL reflects the active tab.
        //    Silent mode (init / popstate / AJAX refresh restoration):
        //    still restore the hash via replaceState if it's missing —
        //    this fixes the bug where changing the period pill wiped the
        //    #tab-X hash from the URL even though the tab was preserved
        //    via sessionStorage. replaceState doesn't fire hashchange,
        //    so there's no infinite loop risk.
        //    We build the full URL (pathname + search + hash) explicitly
        //    rather than passing just '#tab-X' so the existing query
        //    string (period, employee_id, from, to) is always preserved.
        if (window.history && window.history.replaceState) {
            const desiredHash = '#' + tabId;
            if (window.location.hash !== desiredHash) {
                const newUrl = window.location.pathname
                    + window.location.search
                    + desiredHash;
                window.history.replaceState(null, '', newUrl);
            }
        } else if (!opts.silent) {
            window.location.hash = tabId;
        }

        // Scroll the dashboard top into view so the user sees the
        // newly-shown section's header, not whatever mid-page position
        // they were at in the previous tab.
        if (!opts.silent) {
            try { root.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
            catch (e) { root.scrollIntoView(); }
        }
    };

    // ── Tab button click listener ───────────────────────────────
    // Attached to document so it survives AJAX swap (the tab buttons
    // are inside #perf-dashboard and get re-rendered on every refresh,
    // but document-level listeners persist).
    document.addEventListener('click', function (e) {
        const btn = e.target.closest('.perf-tab');
        if (!btn) return;
        const root = document.getElementById('perf-dashboard');
        if (!root || !root.contains(btn)) return;
        e.preventDefault();
        const tabId = btn.dataset.tab;
        if (!tabId) return;
        window.switchPerfTab(tabId);
    });

    // ── Browser back/forward via hashchange ─────────────────────
    // If the user clicks back to a #tab-X URL, switch to that tab.
    window.addEventListener('hashchange', function () {
        if (window.location.hash && /^#tab-[\w-]+$/.test(window.location.hash)) {
            const tabId = window.location.hash.substring(1);
            window.switchPerfTab(tabId, { silent: true });
        }
    });

    // Swap #perf-dashboard innerHTML with the new fragment, then re-run
    // chart init. The fragment HTML contains <div id="perf-dashboard">…</div>
    // followed by <style> and <script> blocks. We use a detached container
    // div (not DOMParser) to parse the HTML, extract the new dashboard
    // innerHTML, swap it in, then re-execute the script tags by creating
    // fresh <script> elements (browser won't run scripts inserted via
    // innerHTML). All wrapped in try/catch so any failure falls back to
    // a full page reload instead of leaving the page half-swapped.
    function swapDashboard(html) {
        try {
            const root = document.getElementById('perf-dashboard');
            if (!root) { window.location.reload(); return; }

            // Parse the fragment in a detached <div> (NOT DOMParser —
            // DOMParser can move <style> to <head> and mishandle
            // <script> in some edge cases). A detached div keeps
            // everything in document order.
            const holder = document.createElement('div');
            holder.innerHTML = html;

            // Find the new #perf-dashboard.
            const newRoot = holder.querySelector('#perf-dashboard');
            if (!newRoot) { window.location.reload(); return; }

            // Preserve the skeleton overlay (it lives inside #perf-dashboard)
            // by detaching it before the swap, then re-attaching after.
            const oldOverlay = root.querySelector('.perf-skeleton-overlay');

            // Swap the dashboard content.
            root.innerHTML = newRoot.innerHTML;

            // Re-attach overlay so hideSkeleton() can fade it out smoothly.
            if (oldOverlay) {
                root.appendChild(oldOverlay);
            }

            // Re-execute any <script> tags that came with the fragment.
            // Browser doesn't run scripts inserted via innerHTML, so we
            // create new <script> elements with the same content.
            // We use Array.from() because forEach on a live NodeList
            // can skip elements if the list changes during iteration.
            const scripts = Array.from(holder.querySelectorAll('script'));
            scripts.forEach(function (oldScript) {
                const newScript = document.createElement('script');
                // Copy non-src attributes (e.g. type).
                for (let i = 0; i < oldScript.attributes.length; i++) {
                    const attr = oldScript.attributes[i];
                    newScript.setAttribute(attr.name, attr.value);
                }
                if (oldScript.src) {
                    newScript.src = oldScript.src;
                } else {
                    newScript.textContent = oldScript.textContent;
                }
                document.body.appendChild(newScript);
            });

            // Now that scripts have re-defined window.initPerfDashboard,
            // call it to render the fresh charts.
            if (window.Chart && typeof window.initPerfDashboard === 'function') {
                window.initPerfDashboard();
            }

            // Trigger the fade-in animation on the freshly-swapped content.
            root.classList.add('perf-fresh');
            setTimeout(function () {
                root.classList.remove('perf-fresh');
            }, 450);
        } catch (err) {
            // Any failure → full reload. Better to show a working page
            // than a half-swapped broken one.
            console.error('[perf] swapDashboard failed, reloading:', err);
            window.location.reload();
        }
    }

    // Core fetch + swap routine. `qs` is the query string (without ?).
    // `pushUrl` is the URL to push to history (or null to skip pushState).
    function refreshDashboard(qs, pushUrl) {
        showSkeleton();
        fetch(FRAGMENT_URL + '?' + qs, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        })
        .then(function (r) { return r.json(); })
        .then(function (payload) {
            if (payload && payload.error) {
                // Server returned an error state — fall back to a full
                // page reload so the user sees the actual error page.
                window.location.href = pushUrl || (FRAGMENT_URL + '?' + qs);
                return;
            }
            if (payload && payload.html) {
                swapDashboard(payload.html);
                if (pushUrl) {
                    history.pushState(
                        { perf: true, qs: qs, url: pushUrl },
                        '',
                        pushUrl
                    );
                }
            }
        })
        .catch(function (err) {
            // Network error or JSON parse failure → full reload fallback.
            console.error('[perf] fragment fetch failed:', err);
            window.location.href = pushUrl || (FRAGMENT_URL + '?' + qs);
        })
        .finally(function () {
            hideSkeleton();
        });
    }

    // ── Period pill clicks ──────────────────────────────────────
    // Convert the existing <a class="btn-period" href="..."> links into
    // AJAX triggers. We preventDefault, extract the period from the
    // href's query string, fetch the fragment, swap, and pushState.
    // The current #tab-X hash is preserved on the pushUrl so switching
    // the period does NOT reset the active tab in the URL bar.
    document.addEventListener('click', function (e) {
        const link = e.target.closest('a.btn-period');
        if (!link) return;
        // Only intercept clicks on period pills inside #perf-dashboard.
        const root = document.getElementById('perf-dashboard');
        if (!root || !root.contains(link)) return;

        e.preventDefault();
        const href = link.getAttribute('href') || '';
        const url = new URL(href, window.location.origin);
        const qs = url.search.replace(/^\?/, '');
        // The pushUrl should be the /dashboard?... URL (not /dashboard/fragment).
        // Preserve the active-tab hash so the URL reflects the user's
        // current section (e.g. /dashboard?period=last30#tab-collections).
        const currentHash = window.location.hash || '';
        const pushUrl = '{{ route("dashboard") }}' + (qs ? '?' + qs : '') + currentHash;
        refreshDashboard(qs, pushUrl);
    });

    // ── Employee <select> change (super-admin only) ─────────────
    // Preserves the active-tab hash so switching employees doesn't
    // reset the URL to a hashless state.
    document.addEventListener('change', function (e) {
        const sel = e.target;
        if (!sel || sel.name !== 'employee_id') return;
        const root = document.getElementById('perf-dashboard');
        if (!root || !root.contains(sel)) return;

        // Build query: keep current period + from/to, swap employee_id.
        const params = new URLSearchParams(window.location.search);
        params.delete('employee_id');
        if (sel.value) params.set('employee_id', sel.value);
        const qs = params.toString();
        const currentHash = window.location.hash || '';
        const pushUrl = '{{ route("dashboard") }}' + (qs ? '?' + qs : '') + currentHash;
        refreshDashboard(qs, pushUrl);
    });

    // ── Custom date-range form submit ───────────────────────────
    // Preserves the active-tab hash so the URL stays consistent.
    document.addEventListener('submit', function (e) {
        const form = e.target;
        if (!form || form.id !== 'customPeriodForm') return;
        const root = document.getElementById('perf-dashboard');
        if (!root || !root.contains(form)) return;

        e.preventDefault();
        const fd = new FormData(form);
        const params = new URLSearchParams();
        for (const [k, v] of fd.entries()) {
            if (v) params.set(k, v);
        }
        const qs = params.toString();
        const currentHash = window.location.hash || '';
        const pushUrl = '{{ route("dashboard") }}' + (qs ? '?' + qs : '') + currentHash;
        refreshDashboard(qs, pushUrl);
    });

    // ── Back/forward button ─────────────────────────────────────
    window.addEventListener('popstate', function (e) {
        // Re-load the dashboard via AJAX for the URL we landed on.
        const params = new URLSearchParams(window.location.search);
        const qs = params.toString();
        // No pushUrl — popstate already updated the URL.
        refreshDashboard(qs, null);
    });
})();
@endif
</script>
@endpush
