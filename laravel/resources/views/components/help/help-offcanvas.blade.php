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
    tabindex="-1"
    id="helpOffcanvas"
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
    <div class="offcanvas-body help-offcanvas__body" id="helpOffcanvasBody">
        {{-- Initial loading placeholder (replaced on first fetch) --}}
        <div class="help-loading-placeholder">
            <div class="spinner-border spinner-border-sm text-secondary" role="status" aria-hidden="true"></div>
            <span class="ms-2 text-muted">লোড হচ্ছে…</span>
        </div>
    </div>
</div>
@endif
