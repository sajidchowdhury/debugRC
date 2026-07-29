{{--
  x-erp.active-filter-bar — horizontal wrap of removable filter tags + "Clear all".

  Usage:
    <x-erp.active-filter-bar id="activeFilterBar" />

  Phase 2 (UI/UX plan — Filter UX): rendered above the invoices DataTable.
  Shows the user at a glance every filter currently narrowing the list, with
  one-click removal per filter (data-clear-filter on each tag) + a "Clear all"
  button that wipes every filter back to defaults.

  The bar is `hidden` by default; the page JS (renderActiveFilterBar) removes
  the `hidden` class when ≥1 filter is active. The tag container #activeFilterTags
  is populated by JS — it starts empty.

  Styling: soft amber tint (bg-amber-50/60) so it reads as "ambient context"
  rather than a primary action area. "Clear all" sits at the far right via
  ml-auto and wraps to its own line on very narrow screens.
--}}
@props([
    'id' => 'activeFilterBar',
])

<div {{ $attributes->merge(['id' => $id, 'role' => 'group', 'aria-label' => 'Active filters', 'class' => 'hidden flex flex-wrap items-center gap-2 bg-amber-50/60 border border-amber-200 rounded-lg px-3 py-2']) }}>
    <span class="inline-flex items-center gap-1 text-xs font-medium text-amber-800">
        <x-erp.icon name="filter" class="size-3.5" />
        Active:
    </span>
    <div id="activeFilterTags" class="flex flex-wrap items-center gap-2"></div>
    <button type="button"
            id="clearAllFilters"
            aria-label="Clear all filters"
            class="ml-auto inline-flex items-center gap-1 text-xs text-amber-700 hover:text-amber-900 hover:underline font-medium focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-400 rounded">
        <x-erp.icon name="x" class="size-3" />
        Clear all
    </button>
</div>
