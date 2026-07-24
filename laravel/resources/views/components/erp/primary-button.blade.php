{{--
  x-erp.primary-button — solid accent-tinted button (or link if href set).

  Usage:
    <x-erp.primary-button accent="amber" icon="save">Save / সংরক্ষণ</x-erp.primary-button>
    <x-erp.primary-button accent="orange" icon="warehouse" href="/sales/godown/1">Enter Info</x-erp.primary-button>
    <x-erp.primary-button type="submit" :disabled="$disabled">Submit</x-erp.primary-button>

  Showcase spec: bg-{c}-500 hover:bg-{c}-600 text-white gap-2 rounded-lg
  px-4 py-2 text-sm font-medium inline-flex items-center shadow-md
--}}
@props([
    'accent' => 'amber',
    'icon' => null,
    'href' => null,
    'type' => 'button',
])

@php
    $a = App\Support\Accents::get($accent);
    $classes = implode(' ', [
        'inline-flex items-center justify-center gap-2 rounded-lg px-4 py-2 text-sm font-medium text-white shadow-md transition-colors',
        $a['bg_500'],
        $a['hover_bg_600'],
        'disabled:opacity-50 disabled:pointer-events-none',
    ]);
    // Strip href/type from passthrough attributes (handled explicitly below)
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
