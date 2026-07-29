{{--
  x-erp.filter-tag — a single removable filter tag pill.

  Usage (server-side):
    <x-erp.filter-tag key="from_date" label="From: 2025-01-01" />

  Phase 2 (UI/UX plan — Filter UX): rendered inside <x-erp.active-filter-bar>.
  The × button carries data-clear-filter="{key}" so a single delegated
  handler can remove that one filter from the page state.

  On the Today Invoice screen the tags are actually generated client-side
  by JS (renderActiveFilterBar → filterTagHTML) so the bar updates live as
  the user toggles filters. This Blade component is the canonical markup
  reference + reusable for any server-rendered filter bar in other modules.

  Tag: white pill, amber border. × button: 16px circle, hover amber-100.
  aria-label on the × button is "Remove {label} filter" for screen readers.
--}}
@props([
    'key' => '',
    'label' => '',
])

<span class="inline-flex items-center gap-1.5 rounded-full bg-white border border-amber-300 px-2.5 py-0.5 text-xs text-amber-900">
    <span>{{ $label }}</span>
    <button type="button"
            data-clear-filter="{{ $key }}"
            aria-label="Remove {{ $label }} filter"
            class="size-4 rounded-full hover:bg-amber-100 inline-flex items-center justify-center text-amber-600 transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-400">
        <x-erp.icon name="x" class="size-3" />
    </button>
</span>
