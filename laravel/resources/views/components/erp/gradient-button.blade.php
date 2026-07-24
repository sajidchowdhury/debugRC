{{--
  x-erp.gradient-button — brand CTA (amber→orange gradient).

  Usage:
    <x-erp.gradient-button icon="truck" type="submit">Issue Challan / চালান ইস্যু</x-erp.gradient-button>
    <x-erp.gradient-button icon="printer" href="/sales/invoices/1/print/blank-godown">Create & Print</x-erp.gradient-button>

  Showcase spec: bg-gradient-to-r from-amber-500 to-orange-500
  hover:from-amber-600 hover:to-orange-600 text-white gap-2 min-w-[200px]
  rounded-lg px-4 py-2 text-sm font-medium shadow-md
--}}
@props([
    'icon' => null,
    'href' => null,
    'type' => 'button',
])

@php
    $classes = implode(' ', [
        'inline-flex items-center justify-center gap-2 rounded-lg min-w-[200px] px-4 py-2 text-sm font-medium text-white shadow-md transition-all',
        'bg-gradient-to-r from-amber-500 to-orange-500 hover:from-amber-600 hover:to-orange-600',
        'disabled:opacity-50 disabled:pointer-events-none',
    ]);
    $extraAttrs = clone $attributes;
    $extraAttrs = $extraAttrs->except(['href', 'type']);
@endphp

@if ($href !== null)
    <a href="{{ $href }}" {{ $extraAttrs->merge(['class' => $classes]) }}>
        @if ($icon)<x-erp.icon :name="$icon" class="size-4" />@endif
        <span>{{ $slot }}</span>
    </a>
@else
    <button type="{{ $type }}" {{ $extraAttrs->merge(['class' => $classes]) }}>
        @if ($icon)<x-erp.icon :name="$icon" class="size-4" />@endif
        <span>{{ $slot }}</span>
    </button>
@endif
