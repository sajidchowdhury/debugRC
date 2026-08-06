@php
/**
 * Guide Footer — fixed bottom pill "🧭 My Creative Code Guide" (Door 2 trigger)
 * + §9.2 recently-viewed ★ button beside it.
 *
 * Phase 9 adds a ★ button that opens a popover listing the last 5 menus the
 * user opened (stored in localStorage by help.js). The popover is rendered
 * here (empty, populated by JS). Degrades gracefully if localStorage is
 * unavailable (help.js hides the ★ button in that case).
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

    {{-- §9.2 recently-viewed ★ button (popover trigger). JS hides it if
         localStorage is unavailable OR if there is no history yet. --}}
    <button
        type="button"
        id="helpRecentBtn"
        class="help-recent-btn"
        aria-haspopup="menu"
        aria-expanded="false"
        aria-controls="helpRecentPopover"
        aria-label="সম্প্রতি দেখা সাহায্য"
        title="সম্প্রতি দেখা (Recently viewed)"
        hidden
    >
        <i class="fa-solid fa-star" aria-hidden="true"></i>
    </button>
</div>

{{-- §9.2 recently-viewed popover (populated dynamically by help.js) --}}
<div
    class="help-recent-popover"
    id="helpRecentPopover"
    role="menu"
    aria-label="সম্প্রতি দেখা সাহায্য"
    hidden
>
    <div class="help-recent-popover__header">
        <span class="help-recent-popover__title">
            <i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i>
            সম্প্রতি দেখা
        </span>
        <button type="button" class="help-recent-popover__clear" id="helpRecentClear">
            মুছুন
        </button>
    </div>
    <div class="help-recent-popover__list" id="helpRecentList"></div>
</div>
@endif
