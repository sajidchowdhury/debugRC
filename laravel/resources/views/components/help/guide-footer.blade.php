@php
/**
 * Guide Footer — fixed bottom pill "🧭 My Creative Code Guide" (Door 2 trigger).
 * Phase 2 scaffold: visible glassmorphism bar. Phase 8 polishes.
 *
 * NOTE: respects the sticky-footer rule (§11.1). The footer sits at z-index above
 * existing content but does NOT overlap the existing ERP footer — the existing
 * footer gets margin-bottom compensation via help-system.css.
 */
@endphp

@if(auth()->check())
<div class="help-footer-bar" role="region" aria-label="সিস্টেম গাইড">
    <button
        type="button"
        id="guideFooterPill"
        class="help-footer-pill"
        aria-haspopup="dialog"
        aria-expanded="false"
        aria-controls="helpModuleSheet"
    >
        <span class="help-footer-pill__icon" aria-hidden="true">🧭</span>
        <span class="help-footer-pill__label">My Creative Code Guide</span>
    </button>
</div>
@endif
