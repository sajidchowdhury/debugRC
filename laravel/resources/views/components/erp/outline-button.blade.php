{{--
  x-erp.outline-button — secondary / cancel button (or link if href set).

  Usage:
    <x-erp.outline-button href="/sales/invoices">Cancel / বাতিল</x-erp.outline-button>
    <x-erp.outline-button icon="arrow-left" type="button" onclick="history.back()">Back</x-erp.outline-button>

  Showcase spec: border border-gray-200 hover:bg-gray-50 rounded-lg
  px-4 py-2 text-sm font-medium text-gray-700
--}}
@props([
    'icon' => null,
    'href' => null,
    'type' => 'button',
])

@php
    $classes = implode(' ', [
        'inline-flex items-center justify-center gap-2 rounded-lg border border-gray-200 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors',
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
