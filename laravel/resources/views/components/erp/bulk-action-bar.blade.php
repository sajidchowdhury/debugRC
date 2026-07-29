{{--
  x-erp.bulk-action-bar — sticky bar above a DataTable, shown when ≥1 row is checked.

  Usage:
    <x-erp.bulk-action-bar id="invoiceBulkBar">
        <x-slot:actions>
            <button type="button" data-bulk-action="call-it-a-day">Call It A Day</button>
            <button type="button" data-bulk-action="clear">Clear</button>
        </x-slot:actions>
    </x-erp.bulk-action-bar>

  Phase 1 (UI/UX plan): appears above the invoices DataTable. The count
  span (#bulkSelectedCount) is updated by JS; the whole bar toggles
  `hidden` based on selection. role="region" + aria-live="polite" so
  screen readers announce selection changes.
--}}
@props([
    'id' => 'bulkActionBar',
    'count' => 0,
])

<div {{ $attributes->merge(['id' => $id, 'role' => 'region', 'aria-label' => 'Bulk actions', 'class' => 'hidden sticky top-0 z-20 bg-amber-50 border border-amber-200 rounded-lg px-4 py-2.5 shadow-sm flex flex-wrap items-center justify-between gap-3 mb-3']) }}>
    <div class="flex items-center gap-2 text-sm">
        <span class="inline-flex items-center justify-center size-5 rounded-full bg-amber-500 text-white text-xs font-bold">
            <x-erp.icon name="check" class="size-3" />
        </span>
        <span class="font-medium text-amber-900">
            <span id="bulkSelectedCount" aria-live="polite" aria-atomic="true">{{ (int) $count }}</span>
            selected / নির্বাচিত
        </span>
    </div>
    <div class="flex items-center gap-2 flex-wrap">
        @isset($actions)
            {{ $actions }}
        @endisset
    </div>
</div>
