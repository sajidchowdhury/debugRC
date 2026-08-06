@php
/**
 * Help Offcanvas — the shared right-side drawer (used by both doors).
 * Phase 2 scaffold: Bootstrap offcanvas-end shell. Content is injected by help.js
 * after fetching /help/menu/{key}. Phase 4 styles the header + body sections.
 *
 * This component is the SINGLE offcanvas instance on the page. Door 1 (help button)
 * and Door 2 (module offcanvas → menu chip) both load their menu content here.
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
        {{-- Content injected here by help.js --}}
        <div class="help-loading-placeholder">
            <div class="spinner-border spinner-border-sm text-secondary" role="status" aria-hidden="true"></div>
            <span class="ms-2 text-muted">লোড হচ্ছে…</span>
        </div>
    </div>
</div>
@endif
