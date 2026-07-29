{{--
  x-erp.date-presets — row of 5 quick date-range pill buttons.

  Usage:
    <x-erp.date-presets id="datePresets" />

  Phase 2 (UI/UX plan — Filter UX): one-click date navigation for the
  Today Invoice screen. The five presets resolve to concrete from_date /
  to_date values on the client (no server round-trip needed for the
  preset itself — the resolved range is pushed into #from_date / #to_date
  and the DataTables AJAX request carries it).

  Pills use aria-pressed for toggle semantics (managed by JS — the
  "Custom" pill is never "pressed"; clicking it only clears the active
  state and focuses the date inputs so the user can pick a custom range).

  Active styling mirrors <x-erp.gradient-button>: bg-gradient-to-r
  from-amber-500 to-orange-500. Inactive pills are amber-tinted outlines.

  The click wiring + active-state toggling lives in the page JS (see
  sales-invoices/index.blade.php → applyDatePreset / setActivePreset).
--}}
@props([
    'id' => 'datePresets',
])

@php
    $presets = [
        ['key' => 'today',       'label' => 'Today',        'bn' => 'আজ'],
        ['key' => 'yesterday',   'label' => 'Yesterday',    'bn' => 'গতকাল'],
        ['key' => 'last_7_days', 'label' => 'Last 7 days',  'bn' => 'গত ৭ দিন'],
        ['key' => 'this_month',  'label' => 'This month',   'bn' => 'এই মাস'],
        ['key' => 'custom',      'label' => 'Custom',       'bn' => 'নিজে বাছুন'],
    ];
@endphp

<div {{ $attributes->merge(['id' => $id, 'role' => 'group', 'aria-label' => 'Date presets', 'class' => 'flex flex-wrap gap-1.5']) }}>
    @foreach ($presets as $p)
        <button type="button"
                data-preset="{{ $p['key'] }}"
                aria-pressed="false"
                aria-label="Filter to {{ $p['label'] }}"
                class="date-preset-btn inline-flex items-center gap-1 rounded-full px-3 py-1.5 min-h-[36px] text-xs font-medium border transition-colors bg-white border-amber-200 text-amber-700 hover:bg-amber-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-400 focus-visible:ring-offset-1">
            <span>{{ $p['label'] }}</span>
            <span class="text-[10px] text-amber-500/80 hidden sm:inline">{{ $p['bn'] }}</span>
        </button>
    @endforeach
</div>
