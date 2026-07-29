{{--
  x-erp.journey-stepper — 4-step workflow indicator for the Godown & Challan pipeline.

  Usage:
    <x-erp.journey-stepper :current="2" />   (Invoice done, Godown active, Challan+Receipt pending)
    <x-erp.journey-stepper :current="4" />   (all done — Receipt view)

  Renders ON the dark gradient hero (text-white). Steps: Invoice → Godown → Challan → Receipt.
  States: done (green), active (amber), pending (white/30).
  Responsive: horizontal row on md+, vertical stack on mobile.

  Props:
    current (int, 1-4): the 1-indexed active step.
    steps (array, optional): override the default 4 steps.
--}}
@props([
    'current' => 1,
    'steps' => null,
])

@php
    $defaultSteps = [
        ['label' => 'Invoice', 'label_bn' => 'চালান',     'icon' => 'file-text'],
        ['label' => 'Godown',  'label_bn' => 'গোডাউন',    'icon' => 'warehouse'],
        ['label' => 'Challan', 'label_bn' => 'চালানপত্র',  'icon' => 'truck'],
        ['label' => 'Receipt', 'label_bn' => 'রসিদ',       'icon' => 'check-circle'],
    ];
    $steps = $steps ?? $defaultSteps;
    $current = (int) $current;

    // State resolver: done / active / pending
    $stateOf = function (int $stepNum) use ($current): string {
        if ($stepNum < $current) return 'done';
        if ($stepNum === $current) return 'active';
        return 'pending';
    };

    // Circle classes per state (designed for the dark gradient hero)
    $circleClasses = [
        'done'    => 'bg-green-500/20 border-green-400 text-green-300',
        'active'  => 'bg-amber-500 border-amber-300 text-white shadow-lg shadow-amber-500/30',
        'pending' => 'bg-white/10 border-white/30 text-white/50',
    ];
    $labelClasses = [
        'done'    => 'text-green-300',
        'active'  => 'text-white font-semibold',
        'pending' => 'text-white/50',
    ];
@endphp

<div class="flex flex-col md:flex-row md:items-center gap-1 md:gap-0 mt-6" role="list" aria-label="Workflow progress">
    @foreach ($steps as $i => $step)
        @php
            $stepNum = $i + 1;
            $state = $stateOf($stepNum);
            $isLast = ($i === count($steps) - 1);
            $prevDone = ($stepNum > 1) && ($stateOf($stepNum - 1) === 'done');
        @endphp

        <div class="flex md:items-center gap-2 @if(!$isLast) mb-1 md:mb-0 md:mr-1 @endif" role="listitem">
            {{-- Step circle + label --}}
            <div class="flex items-center gap-2 shrink-0">
                <div class="size-8 rounded-full flex items-center justify-center border-2 shrink-0 {{ $circleClasses[$state] }}"
                     aria-current="{{ $state === 'active' ? 'step' : 'false' }}">
                    @if ($state === 'done')
                        <x-erp.icon name="check" class="size-4" />
                    @else
                        <x-erp.icon :name="$step['icon']" class="size-4" />
                    @endif
                </div>
                <div class="flex flex-col leading-tight whitespace-nowrap">
                    <span class="text-xs {{ $labelClasses[$state] }}">{{ $step['label'] }}</span>
                    <span class="text-[10px] text-amber-200/70">{{ $step['label_bn'] }}</span>
                </div>
            </div>

            {{-- Connector: vertical on mobile (under the circle), horizontal on md+ (between steps) --}}
            @if (! $isLast)
                <div class="ml-4 md:ml-0 md:flex-1 w-0.5 h-3 md:h-0.5 md:w-auto rounded-full {{ $prevDone ? 'bg-green-400/60' : 'bg-white/30' }}"
                     aria-hidden="true"></div>
            @endif
        </div>
    @endforeach
</div>
