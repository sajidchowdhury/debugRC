{{--
  x-erp.step-indicator — 4-step workflow indicator with done/active/pending states.

  Usage:
    <x-erp.step-indicator :steps="[
        ['label' => 'Invoice',     'label_bn' => 'চালান',          'icon' => 'file-text',       'state' => 'done'],
        ['label' => 'Blank Godown','label_bn' => 'ব্লাঙ্ক গোডাউন','icon' => 'clipboard-list',  'state' => 'active'],
        ['label' => 'Godown Prep', 'label_bn' => 'গোডাউন প্রস্তুতি','icon' => 'warehouse',      'state' => 'pending'],
        ['label' => 'Challan Issue','label_bn' => 'চালান ইস্যু',    'icon' => 'truck',           'state' => 'pending'],
    ]" />

  Showcase spec (Prepare Blank Godown page):
    Invoice ✓ (green) → Blank Godown (active amber) → Godown Prep (gray) → Challan Issue (gray)
    Each step: size-8 rounded-full circle with icon (or checkmark if done),
    label below. Connector: w-4 h-0.5 (green if done else gray).

  state: 'done' | 'active' | 'pending'
--}}
@props(['steps' => []])

@php
    $stateClasses = [
        'done'    => 'bg-green-500 border-green-500 text-white',
        'active'  => 'bg-amber-500 border-amber-500 text-white',
        'pending' => 'bg-white border-gray-300 text-gray-400',
    ];
    $labelClasses = [
        'done'    => 'text-green-600',
        'active'  => 'text-amber-600 font-semibold',
        'pending' => 'text-gray-400',
    ];
@endphp

<div {{ $attributes->merge(['class' => 'flex items-center justify-center gap-1']) }}>
    @foreach ($steps as $i => $step)
        @php
            $state = $step['state'] ?? 'pending';
            $icon = $step['icon'] ?? 'box';
        @endphp
        <div class="flex items-center">
            <div class="flex flex-col items-center gap-1">
                <div class="size-8 rounded-full flex items-center justify-center border-2 transition-colors {{ $stateClasses[$state] }}">
                    @if ($state === 'done')
                        <x-erp.icon name="check-circle" class="size-4" />
                    @else
                        <x-erp.icon :name="$icon" class="size-4" />
                    @endif
                </div>
                <div class="text-center min-w-[64px]">
                    <p class="text-[11px] {{ $labelClasses[$state] }}">{{ $step['label'] }}</p>
                    @if (!empty($step['label_bn']))
                        <p class="text-[9px] text-gray-500">{{ $step['label_bn'] }}</p>
                    @endif
                </div>
            </div>
            @if ($i < count($steps) - 1)
                <div class="w-4 h-0.5 mx-1 mt-[-20px] {{ $state === 'done' ? 'bg-green-400' : 'bg-gray-300' }}"></div>
            @endif
        </div>
    @endforeach
</div>
