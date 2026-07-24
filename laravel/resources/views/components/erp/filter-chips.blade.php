{{--
  x-erp.filter-chips — status filter pill row with counts.

  Usage:
    <x-erp.filter-chips :chips="[
        ['key' => 'all', 'label' => 'All', 'count' => 10],
        ['key' => 'finalized', 'label' => 'Needs Godown', 'label_bn' => 'গোডাউন প্রয়োজন', 'count' => 4],
    ]" active="all" accent="orange" on-change="filterForm.submit()" />

  Or with hrefs (GET-based filtering):
    <x-erp.filter-chips :chips="$chips" active="all" :link-base="'/sales/invoices?status='" />

  Showcase spec (active): bg-{c}-500 text-white gap-1.5 rounded-full px-3 py-1
  text-xs font-medium. Count badge: active bg-white/30, inactive bg-gray-100.

  If $onChange is set, each chip renders as a <button type="button"> calling a
  JS function. If $linkBase is set, each chip is an <a> linking to
  $linkBase . $chip['key']. Prefer $linkBase for SEO/refresh-friendly GET filtering.
--}}
@props([
    'chips' => [],
    'active' => '',
    'accent' => 'orange',
    'onChange' => null,
    'linkBase' => null,
])

@php
    $a = App\Support\Accents::get($accent);
    $activeClass = $a['bg_500'] . ' text-white';
    $inactiveClass = 'border border-gray-200 hover:bg-amber-50 text-gray-600';
@endphp

<div {{ $attributes->merge(['class' => 'flex flex-wrap gap-2']) }}>
    @foreach ($chips as $chip)
        @php
            $isActive = ($chip['key'] === $active);
            $chipClass = $isActive ? $activeClass : $inactiveClass;
            $count = $chip['count'] ?? null;
            $countClass = $isActive ? 'bg-white/30' : 'bg-gray-100';
            $label = $chip['label'] . (!empty($chip['label_bn']) ? ' / ' . $chip['label_bn'] : '');
            $baseAttrs = 'inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium transition-colors ' . $chipClass;
        @endphp

        @if ($linkBase !== null)
            <a href="{{ rtrim($linkBase, '?&=') . $chip['key'] }}" class="{{ $baseAttrs }}">
                {{ $label }}
                @if ($count !== null)
                    <span class="rounded-full px-1.5 text-[10px] ml-0.5 {{ $countClass }}">{{ $count }}</span>
                @endif
            </a>
        @elseif ($onChange !== null)
            <button type="button" onclick="{{ $onChange }}('{{ $chip['key'] }}')" class="{{ $baseAttrs }}">
                {{ $label }}
                @if ($count !== null)
                    <span class="rounded-full px-1.5 text-[10px] ml-0.5 {{ $countClass }}">{{ $count }}</span>
                @endif
            </button>
        @else
            <span class="{{ $baseAttrs }}">
                {{ $label }}
                @if ($count !== null)
                    <span class="rounded-full px-1.5 text-[10px] ml-0.5 {{ $countClass }}">{{ $count }}</span>
                @endif
            </span>
        @endif
    @endforeach
</div>
