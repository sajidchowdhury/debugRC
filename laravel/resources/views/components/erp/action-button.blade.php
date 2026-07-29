{{--
  x-erp.action-button — compact icon-button for per-row DataTable actions.

  Usage:
    <x-erp.action-button variant="view" href="/admin/sales-invoices/1" label="View invoice INV-001" icon="eye" />
    <x-erp.action-button variant="receive" label="Receive payment for INV-001" icon="banknote" data-invoice-id="1" />
    <x-erp.action-button variant="call-it-a-day" label="Call it a day for INV-001" icon="check-circle" data-invoice-id="1" />

  Variants (color map):
    view          — gray (neutral, view-only)
    edit          — amber (write action)
    cancel        — red   (destructive)
    receive       — green (money in)
    call-it-a-day — orange (workflow complete)
    print         — gray  (output)

  Phase 1 (UI/UX plan): the per-row actions column. Tailwind-only. The
  same class strings are replicated in the JS column render function
  (DataTables builds rows client-side).
--}}
@props([
    'variant' => 'view',
    'href' => null,
    'label' => '',
    'icon' => 'eye',
    'type' => 'button',
])

@php
    // Per-variant color overrides (hover state). Base classes are neutral.
    $variantClasses = [
        'view'          => 'hover:bg-gray-50 hover:text-gray-800 hover:border-gray-300',
        'edit'          => 'hover:bg-amber-50 hover:text-amber-700 hover:border-amber-300',
        'cancel'        => 'hover:bg-red-50 hover:text-red-700 hover:border-red-300',
        'receive'       => 'hover:bg-green-50 hover:text-green-700 hover:border-green-300',
        'call-it-a-day' => 'hover:bg-orange-50 hover:text-orange-700 hover:border-orange-300',
        'print'         => 'hover:bg-gray-50 hover:text-gray-800 hover:border-gray-300',
    ];
    $hoverClass = $variantClasses[$variant] ?? $variantClasses['view'];

    $classes = implode(' ', [
        'inline-flex items-center justify-center size-8 rounded-md border border-gray-200 bg-white text-gray-600 transition-colors',
        $hoverClass,
        'focus:outline-none focus:ring-2 focus:ring-amber-500 focus:ring-offset-1',
    ]);

    // Strip handled attributes from passthrough.
    $extraAttrs = clone $attributes;
    $extraAttrs = $extraAttrs->except(['href', 'type', 'variant', 'label', 'icon']);
@endphp

@if ($href !== null)
    <a href="{{ $href }}" title="{{ $label }}" aria-label="{{ $label }}" {{ $extraAttrs->merge(['class' => $classes]) }}>
        <x-erp.icon :name="$icon" class="size-4" />
    </a>
@else
    <button type="{{ $type }}" title="{{ $label }}" aria-label="{{ $label }}" {{ $extraAttrs->merge(['class' => $classes]) }}>
        <x-erp.icon :name="$icon" class="size-4" />
    </button>
@endif
