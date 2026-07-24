{{--
  x-erp.stat-card — dashboard metric card with left-accent border.

  Usage:
    <x-erp.stat-card label="Needs Godown" label-bn="গোডাউন প্রয়োজন"
        value="4" accent="amber" icon="clock" />
    <x-erp.stat-card label="Completed" value="1" accent="green" icon="check-circle" class="mt-4" />

  Showcase spec: bg-white rounded-xl border-l-4 border-l-{c}-500 shadow-sm p-4
  with label (sm, muted), big colored value (2xl, bold), faded icon top-right.
--}}
@props([
    'label' => '',
    'labelBn' => '',
    'value' => '',
    'accent' => 'amber',
    'icon' => null,
])

@php
    $a = App\Support\Accents::get($accent);
    $classes = implode(' ', [
        'relative bg-white rounded-xl border-l-4 shadow-sm p-4 overflow-hidden',
        $a['border_l_500'],
    ]);
@endphp

<div {{ $attributes->merge(['class' => $classes]) }}>
    <p class="text-sm text-gray-500">{{ $label }}</p>
    @if ($labelBn)
        <p class="text-[11px] text-gray-400">{{ $labelBn }}</p>
    @endif
    <p class="text-2xl font-bold mt-1 {{ $a['text_500'] }}">{{ $value }}</p>
    @if ($icon)
        <x-erp.icon :name="$icon" class="size-8 absolute right-4 top-4 opacity-20 {{ $a['text_500'] }}" />
    @endif
</div>
