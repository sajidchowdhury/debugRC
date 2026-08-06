@php
/**
 * Module Offcanvas — right-side drawer showing a module's intro + menu list.
 * Phase 2 scaffold: shell only. Content is injected by help.js after fetching
 * /help/module/{key}. Phase 5 polishes + adds the content-swap UX.
 */
@endphp

@if(auth()->check())
<div
    class="offcanvas offcanvas-end help-module-offcanvas"
    tabindex="-1"
    id="helpModuleOffcanvas"
    aria-labelledby="helpModuleOffcanvasLabel"
    data-bs-scroll="false"
    data-bs-backdrop="true"
>
    <div class="offcanvas-header help-module-offcanvas__header" data-help-color="slate">
        <h5 class="offcanvas-title" id="helpModuleOffcanvasLabel">
            <i class="fa-solid fa-layer-group" aria-hidden="true"></i>
            <span class="help-module-offcanvas__title-text">মডিউল</span>
        </h5>
        <button
            type="button"
            class="btn-close btn-close-white"
            data-bs-dismiss="offcanvas"
            aria-label="বন্ধ করুন"
        ></button>
    </div>
    <div class="offcanvas-body help-module-offcanvas__body" id="helpModuleOffcanvasBody">
        <div class="help-loading-placeholder">
            <div class="spinner-border spinner-border-sm text-secondary" role="status" aria-hidden="true"></div>
            <span class="ms-2 text-muted">লোড হচ্ছে…</span>
        </div>
    </div>
</div>
@endif
