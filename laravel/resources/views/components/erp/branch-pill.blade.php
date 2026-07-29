{{--
  x-erp.branch-pill — branch indicator colored by branch config.

  Usage:
    <x-erp.branch-pill branch-code="HO" />
    <x-erp.branch-pill branch-code="PAT" :show-code="false" />
    <x-erp.branch-pill branch-code="NOW" bn />

  Showcase spec (HO): bg-red-100 border border-red-400 text-red-700 font-semibold
  rounded-full px-3 py-1 text-sm inline-flex items-center gap-1

  Colors come from App\Support\BranchColor (config/branches.php):
    HO=Red, PAT=Blue, NOW=Green, TAR=Orange
--}}
@props([
    'branchCode' => null,
    'showCode' => true,
    'bn' => false,
])

@php
    $config = App\Support\BranchColor::get($branchCode);
    $label = $bn ? $config['name_bn'] : $config['name'];
    $classes = implode(' ', [
        'inline-flex items-center gap-1 font-semibold rounded-full px-3 py-1 text-sm border',
        $config['bg_class'],
        $config['border_class'],
        $config['text_class'],
    ]);
@endphp

<span {{ $attributes->merge(['class' => $classes]) }}>
    <x-erp.icon name="map-pin" class="size-3" />
    {{ $label }}
    @if ($showCode)
        <span class="opacity-70">({{ $config['code'] }})</span>
    @endif
</span>
