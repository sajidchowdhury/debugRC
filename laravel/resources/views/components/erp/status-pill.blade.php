{{--
  x-erp.status-pill — invoice status badge (color + icon + label).

  Usage:
    <x-erp.status-pill status="blank_godown_created" />
    <x-erp.status-pill status="finalized" bilingual />
    <x-erp.status-pill status="challan_issued" bn />

  Color mapping (from App\Support\StatusPalette):
    draft                → gray    (Draft)
    finalized            → amber   (Needs Godown)
    blank_godown_created → orange  (Blank Godown)
    godown_prepared      → cyan    (Ready for Challan)
    challan_issued       → green   (Completed)
    cancelled            → red     (Cancelled)

  Showcase spec: bg-{c}-100 text-{c}-700 border border-{c}-300 font-medium
  text-xs rounded-full px-2 py-0.5 inline-flex items-center gap-1
--}}
@props([
    'status' => 'draft',
    'bn' => false,
    'bilingual' => false,
])

@php
    $config = App\Support\StatusPalette::get($status);
    if ($bilingual) {
        $label = $config['label'] . ' / ' . $config['label_bn'];
    } elseif ($bn) {
        $label = $config['label_bn'];
    } else {
        $label = $config['label'];
    }
    $classes = implode(' ', [
        'font-medium text-xs rounded-full px-2 py-0.5 inline-flex items-center gap-1',
        $config['badge_class'],
    ]);
@endphp

<span {{ $attributes->merge(['class' => $classes]) }}>
    <x-erp.icon :name="$config['icon']" class="size-3" />
    {{ $label }}
</span>
