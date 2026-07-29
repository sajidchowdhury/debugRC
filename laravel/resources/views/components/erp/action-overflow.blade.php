{{--
  x-erp.action-overflow — "⋯" dropdown for less-common per-row actions.

  Usage:
    <x-erp.action-overflow label="More actions for INV-001">
        <a href="/admin/sales-invoices/1/edit" class="dropdown-item">Edit</a>
        <button type="button" class="dropdown-item" data-row-action="cancel">Cancel</button>
    </x-erp.action-overflow>

  Phase 1 (UI/UX plan): wraps overflow actions on mobile + when >3
  actions are visible. Reuses Bootstrap's .dropdown (already loaded)
  but styles the menu with Tailwind. The trigger button matches
  <x-erp.action-button>'s base styling for visual consistency.
--}}
@props([
    'label' => 'More actions',
])

<div class="dropdown d-inline-block">
    <button type="button"
            class="inline-flex items-center justify-center size-8 rounded-md border border-gray-200 bg-white text-gray-600 hover:bg-gray-50 hover:text-gray-800 hover:border-gray-300 transition-colors focus:outline-none focus:ring-2 focus:ring-amber-500 focus:ring-offset-1"
            data-bs-toggle="dropdown"
            data-bs-auto-close="true"
            aria-expanded="false"
            title="{{ $label }}"
            aria-label="{{ $label }}">
        <x-erp.icon name="more-horizontal" class="size-4" />
    </button>
    <ul class="dropdown-menu dropdown-menu-end shadow-lg rounded-md border border-gray-200 bg-white py-1" style="min-width: 12rem;">
        <li class="px-1">{{ $slot }}</li>
    </ul>
</div>
