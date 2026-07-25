{{--
  x-erp.collapsible-card — a <x-erp.left-accent-card> variant whose body
  can be toggled open/closed via a native <details>/<summary> element.

  On mobile (<768px) it collapses by default to save vertical space; on
  desktop (≥768px) a CSS media query forces it open (body always visible).

  Usage:
    <x-erp.collapsible-card accent="amber" icon="search" title="Filters" title-bn="ফিল্টার">
        ... body content ...
    </x-erp.collapsible-card>

  Accessibility:
    - <details>/<summary> gives native toggle semantics + keyboard
      support (Enter/Space) + screen-reader "expanded/collapsed" state.
    - The chevron rotates via CSS when [open] is set.
    - aria-controls on the summary points at the body id (best practice).
--}}
@props([
    'accent' => 'amber',
    'icon' => null,
    'title' => '',
    'titleBn' => '',
    'id' => null,
])

@php
    $a = App\Support\Accents::get($accent);
    $borderClass = $a['border_l_400'];
    $classes = implode(' ', [
        'bg-white rounded-xl shadow-sm border-l-4 overflow-hidden',
        $borderClass,
        'rc-collapsible-card',
    ]);
    $bodyId = $id ? $id . '-body' : 'rcCollapsibleBody' . random_int(1000, 9999);
@endphp

<details class="{{ $classes }}" {{ $attributes->only('id') }} open>
    <summary class="rc-collapsible-summary flex items-center justify-between gap-3 px-4 py-3 cursor-pointer list-none"
             aria-controls="{{ $bodyId }}">
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
        <div class="flex items-center gap-2">
            @isset($actions)
                <div class="flex items-center gap-2">{{ $actions }}</div>
            @endisset
            <i class="fas fa-chevron-down rc-collapsible-chevron {{ $a['text_400'] }}" aria-hidden="true"></i>
        </div>
    </summary>
    <div id="{{ $bodyId }}" class="rc-collapsible-body p-4">
        {{ $slot }}
    </div>
</details>
