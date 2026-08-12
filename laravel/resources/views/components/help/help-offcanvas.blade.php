@php
/**
 * Help Offcanvas — the shared right-side drawer (used by both doors).
 *
 * Bootstrap offcanvas-end, 420px desktop / full-screen mobile.
 * Content is injected by help.js after fetching /help/menu/{key}.
 * The header gradient tints to the loaded menu's module colour
 * (via the --help-tint-c1/c2 CSS custom properties set by help.js).
 *
 * This component is the SINGLE offcanvas instance on the page. Door 1 (help button)
 * and Door 2 (module offcanvas → menu chip) both load their menu content here.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §6.2 (component visual rules)
 */
@endphp

@if(auth()->check())
<div
    class="offcanvas offcanvas-end help-offcanvas"
    role="dialog"
    tabindex="-1"
    id="helpOffcanvas"
    aria-modal="true"
    aria-labelledby="helpOffcanvasLabel"
    data-bs-scroll="false"
    data-bs-backdrop="true"
>
    <div class="offcanvas-header help-offcanvas__header" data-help-color="slate">
        <h5 class="offcanvas-title" id="helpOffcanvasLabel">
            <i class="fa-solid fa-circle-question" aria-hidden="true"></i>
            <span class="help-offcanvas__title-text">সাহায্য</span>
        </h5>
        <button
            type="button"
            class="btn-close btn-close-white"
            data-bs-dismiss="offcanvas"
            aria-label="বন্ধ করুন"
        ></button>
    </div>
    {{-- Back-to-module bar (Phase 5 §4.3 content-swap UX). Hidden by default;
         help.js reveals it when the menu offcanvas is opened FROM a module
         (via the footer pill → module sheet → module offcanvas → menu chip flow).
         When opened directly from the FAB (Door 1), this stays hidden. --}}
    <div class="help-offcanvas__back" id="helpOffcanvasBack" hidden>
        <button
            type="button"
            class="help-back-btn"
            id="helpBackToModule"
            aria-label="মডিউলে ফিরে যান"
        >
            <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
            <span>মডিউলে ফিরে যান</span>
        </button>
        <nav class="help-breadcrumb" id="helpBreadcrumb" aria-label="breadcrumb">
            <span class="help-breadcrumb__module">মডিউল</span>
            <i class="fa-solid fa-chevron-right help-breadcrumb__sep" aria-hidden="true"></i>
            <span class="help-breadcrumb__menu">মেনু</span>
        </nav>
    </div>
    {{-- §9.5 Print actions bar. Hidden by default; help.js reveals it once real
         menu content is loaded (so the button never prints a loading spinner or
         the empty-state). Clicking opens a clean print view in a new window. --}}
    <div class="help-offcanvas__actions" id="helpOffcanvasActions" hidden>
        <button
            type="button"
            class="help-print-btn"
            id="helpPrintBtn"
            aria-label="এই সাহায্য প্রিন্ট করুন"
        >
            <i class="fa-solid fa-print" aria-hidden="true"></i>
            <span>প্রিন্ট করুন</span>
        </button>
    </div>
    <div class="offcanvas-body help-offcanvas__body" id="helpOffcanvasBody">
        {{-- Initial loading placeholder (replaced on first fetch) --}}
        <div class="help-loading-placeholder">
            <div class="spinner-border spinner-border-sm text-secondary" role="status" aria-hidden="true"></div>
            <span class="ms-2 text-muted">লোড হচ্ছে…</span>
        </div>
    </div>
</div>
@endif
