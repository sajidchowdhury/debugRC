{{--
  x-erp.sticky-action-bar — sticky bottom bar for Save/Issue buttons.

  Usage (classic — default, used by ui-preview):
    <x-erp.sticky-action-bar>
        <x-erp.outline-button href="/sales/invoices">Cancel</x-erp.outline-button>
        <x-erp.gradient-button icon="truck" type="submit">Issue Challan</x-erp.gradient-button>
    </x-erp.sticky-action-bar>

  Usage (Phase 8 — godown/issue bars with justify-between + mobile-aware):
    <x-erp.sticky-action-bar variant="phase8" align="between">
        <span class="hint">…</span>
        <div class="flex flex-col w-full md:flex-row gap-3">…buttons…</div>
    </x-erp.sticky-action-bar>

  Showcase spec (classic): sticky bottom-4 bg-white/80 backdrop-blur-sm py-4 px-4
    border-t rounded-t-lg shadow-lg flex items-center gap-3 no-print
  Phase 8 spec: sticky bottom-0 inset-x-0 bg-white/95 backdrop-blur-sm border-t
    shadow-lg z-40 flex items-center gap-3 flex-wrap p-4 no-print
--}}
@props([
    'align' => 'end',       // start | center | end | between
    'variant' => null,      // null = classic | 'phase8'
])

@php
    $justifyMap = [
        'start'   => 'justify-start',
        'center'  => 'justify-center',
        'end'     => 'justify-end',
        'between' => 'justify-between',
    ];
    $justifyClass = $justifyMap[$align] ?? 'justify-end';

    if ($variant === 'phase8') {
        // Phase 8 spec: full-bleed bottom bar, higher z, denser padding,
        // wrap-friendly. Mobile stacking is handled by the slot markup
        // (a flex-col w-full md:flex-row wrapper around the buttons).
        $base = 'sticky bottom-0 inset-x-0 z-40 bg-white/95 backdrop-blur-sm border-t shadow-lg flex items-center gap-3 flex-wrap p-4 no-print ' . $justifyClass;
    } else {
        // Classic (backward-compat — ui-preview + any pre-Phase-8 callers).
        $base = 'sticky bottom-4 z-30 bg-white/80 backdrop-blur-sm py-4 px-4 border-t rounded-t-lg shadow-lg flex items-center gap-3 no-print ' . $justifyClass;
    }
@endphp

<div {{ $attributes->merge(['class' => $base]) }}>
    {{ $slot }}
</div>
