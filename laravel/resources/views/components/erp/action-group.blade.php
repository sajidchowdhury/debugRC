{{--
  x-erp.action-group — compact horizontal group of action buttons + overflow.

  Usage:
    <x-erp.action-group label="Actions for INV-2025-00001">
        <x-erp.action-button variant="view" href="/admin/sales-invoices/1" label="View invoice INV-2025-00001" icon="eye" />
        <x-erp.action-button variant="edit" href="/admin/sales-invoices/1/edit" label="Edit invoice INV-2025-00001" icon="pencil" />
        <x-erp.action-button variant="receive" label="Receive payment for INV-2025-00001" icon="banknote" data-invoice-id="1" />
        <x-slot:overflow>
            <x-erp.action-button variant="call-it-a-day" label="Call it a day for INV-2025-00001" icon="check-circle" />
            <x-erp.action-button variant="cancel" label="Cancel invoice INV-2025-00001" icon="ban" />
        </x-slot:overflow>
    </x-erp.action-group>

  Phase 3 (UI/UX plan — Per-Row Actions Parity): the canonical markup for a
  per-row actions cell. Up to 3 inline <x-erp.action-button>s render in the
  default slot; the rest go into the `overflow` slot (rendered inside an
  <x-erp.action-overflow> ⋯ dropdown).

  NOTE: the Today Invoice DataTable builds its rows client-side (DataTables
  JS string-concatenation), so the actual actions column does NOT use this
  Blade component at runtime — it replicates this markup in JS. This
  component exists as (a) the canonical design-system reference the JS
  mirrors, and (b) a reusable component for any server-rendered action group
  (e.g. the invoice show page).
--}}
@props([
    'label' => 'Row actions',
])

<div {{ $attributes->merge(['role' => 'group', 'aria-label' => $label, 'class' => 'inline-flex items-center gap-1 justify-center']) }}>
    {{ $slot }}
    @isset($overflow)
        <x-erp.action-overflow :label="$label . ' (more)'" >
            <div class="d-flex flex-column gap-1">{{ $overflow }}</div>
        </x-erp.action-overflow>
    @endisset
</div>
