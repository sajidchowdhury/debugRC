{{--
  x-erp.left-accent-card — workhorse section panel with colored left border.

  Usage:
    <x-erp.left-accent-card accent="amber" icon="package" title="Product Demand" title-bn="পণ্য চাহিদা">
        <p class="text-sm">Card body content...</p>
    </x-erp.left-accent-card>

    <x-erp.left-accent-card accent="red" title="Invoice Info" :strong="true">
        ...
    </x-erp.left-accent-card>

  Showcase spec: bg-white rounded-xl shadow-sm border-l-4 border-l-{c}-400/500 p-4
  with optional header (icon + bilingual title + actions slot).
--}}
@props([
    'accent' => 'amber',
    'icon' => null,
    'title' => '',
    'titleBn' => '',
    'strong' => false,
])

@php
    $a = App\Support\Accents::get($accent);
    $borderClass = $strong ? $a['border_l_500'] : $a['border_l_400'];
    $classes = implode(' ', [
        'bg-white rounded-xl shadow-sm border-l-4 overflow-hidden',
        $borderClass,
    ]);
    $hasHeader = $title || $icon || isset($actions);
@endphp

<div {{ $attributes->merge(['class' => $classes]) }}>
    @if ($hasHeader)
        <div class="flex items-center justify-between gap-3 px-4 pt-4 pb-2">
            <div class="flex items-center gap-2">
                @if ($icon)
                    <x-erp.icon :name="$icon" class="size-5 {{ $a['text_500'] }}" />
                @endif
                @if ($title)
                    <h3 class="font-semibold text-sm">
                        {{ $title }}
                        @if ($titleBn)
                            <span class="text-gray-500 font-normal ml-1.5">/ {{ $titleBn }}</span>
                        @endif
                    </h3>
                @endif
            </div>
            @isset($actions)
                <div class="flex items-center gap-2">{{ $actions }}</div>
            @endisset
        </div>
    @endif
    <div class="p-4">{{ $slot }}</div>
</div>
