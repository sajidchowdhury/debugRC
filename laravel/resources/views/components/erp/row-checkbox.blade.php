{{--
  x-erp.row-checkbox — styled checkbox for DataTable rows + select-all header.

  Usage (per-row):
    <x-erp.row-checkbox name="invoice_ids[]" :value="$invoice->id" label="Select invoice INV-001" />
  Usage (select-all header):
    <x-erp.row-checkbox name="select_all" label="Select all invoices on this page" :all="true" />

  Phase 1 (UI/UX plan): col 0 of the invoices DataTable. Tailwind-only
  (no Bootstrap classes). The same class string is replicated in the
  JS column render function (see index.blade.php) because DataTables
  builds rows client-side.
--}}
@props([
    'name' => 'invoice_ids[]',
    'value' => null,
    'label' => 'Select row',
    'all' => false,
])

@php
    $isAll = filter_var($all, FILTER_VALIDATE_BOOLEAN);
@endphp

<input type="checkbox"
       name="{{ $name }}"
       @if ($value !== null) value="{{ $value }}" @endif
       @if ($isAll) data-select-all="1" @else data-row-checkbox="1" @endif
       aria-label="{{ $label }}"
       class="size-4 rounded border-gray-300 text-amber-600 focus:ring-2 focus:ring-amber-500 focus:ring-offset-0 cursor-pointer align-middle" />
