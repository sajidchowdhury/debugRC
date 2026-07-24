{{--
  x-erp.journey-stepper — 3-step hero indicator (Invoice → Godown → Challan).

  Usage:
    <x-erp.journey-stepper />   {{-- defaults: Invoice / Godown / Challan --}}
    <x-erp.journey-stepper :steps="$customSteps" />

  Designed to render ON the dark gradient hero (text-white). Each step is a
  size-12 rounded-full circle with colored border + icon, bilingual labels below,
  white/40 connector lines with chevron-right.

  $steps (optional): array of ['label'=>..., 'label_bn'=>..., 'icon'=>..., 'color'=>'amber'|'orange'|'green']
  Default steps: Invoice(amber) → Godown(orange) → Challan(green)
--}}
@props(['steps' => null])

@php
    $defaultSteps = [
        ['label' => 'Invoice', 'label_bn' => 'চালান', 'icon' => 'file-text', 'color' => 'amber'],
        ['label' => 'Godown',  'label_bn' => 'গোডাউন', 'icon' => 'warehouse', 'color' => 'orange'],
        ['label' => 'Challan', 'label_bn' => 'চালানপত্র', 'icon' => 'truck', 'color' => 'green'],
    ];
    $steps = $steps ?? $defaultSteps;

    $circleClasses = [
        'amber' => 'bg-amber-500/20 border-amber-400 text-amber-300',
        'orange' => 'bg-orange-500/20 border-orange-400 text-orange-300',
        'green' => 'bg-green-500/20 border-green-400 text-green-300',
    ];
@endphp

<div class="flex items-center gap-4 justify-center">
    @foreach ($steps as $i => $step)
        <div class="flex items-center gap-4">
            <div class="flex flex-col items-center gap-1">
                <div class="size-12 rounded-full flex items-center justify-center border-2 {{ $circleClasses[$step['color']] ?? $circleClasses['amber'] }}">
                    <x-erp.icon :name="$step['icon']" class="size-5" />
                </div>
                <span class="text-white text-xs font-semibold">{{ $step['label'] }}</span>
                <span class="text-amber-200 text-[10px]">{{ $step['label_bn'] }}</span>
            </div>
            @if ($i < count($steps) - 1)
                <div class="flex items-center">
                    <div class="w-8 h-0.5 bg-white/40"></div>
                    <x-erp.icon name="chevron-right" class="size-3 text-white" />
                </div>
            @endif
        </div>
    @endforeach
</div>
