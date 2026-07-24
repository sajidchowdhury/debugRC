{{--
  x-erp.sticky-action-bar — sticky bottom bar for Save/Issue buttons.

  Usage:
    <x-erp.sticky-action-bar>
        <x-erp.outline-button href="/sales/invoices">Cancel</x-erp.outline-button>
        <x-erp.gradient-button icon="truck" type="submit">Issue Challan</x-erp.gradient-button>
    </x-erp.sticky-action-bar>

  Showcase spec: sticky bottom-4 bg-white/80 backdrop-blur-sm py-4 px-4
  border-t rounded-t-lg shadow-lg flex items-center justify-end gap-3 no-print
--}}
@props(['align' => 'end'])

@php
    $justifyClass = $align === 'center' ? 'justify-center' : ($align === 'start' ? 'justify-start' : 'justify-end');
@endphp

<div {{ $attributes->merge(['class' => 'sticky bottom-4 z-30 bg-white/80 backdrop-blur-sm py-4 px-4 border-t rounded-t-lg shadow-lg flex items-center gap-3 no-print ' . $justifyClass]) }}>
    {{ $slot }}
</div>
