{{--
  x-erp.signature-row — evenly-spaced signature lines for print layouts.

  Usage:
    <x-erp.signature-row :signers="[
        ['label' => 'Dispatcher',      'label_bn' => 'ডিসপ্যাচার'],
        ['label' => 'Godown Manager',  'label_bn' => 'গোডাউন ম্যানেজার'],
        ['label' => 'Verifier',        'label_bn' => 'যাচাইকারী'],
    ]" />

  Or single-string signers (label only, no Bengali):
    <x-erp.signature-row :signers="['Authorized By', 'Dispatcher', 'Received By']" />

  Showcase spec: flex justify-between mt-8, each box w-[140px] with
  border-t border-black mt-[40px] and centered bilingual label.

  Used in: Blank Godown (Dispatcher / Godown Manager / Verifier),
  Godown Copy (WM / Dispatcher / Received By),
  Challan Copy (Authorized / Dispatcher / Received By Customer).
--}}
@props([
    'signers' => [],
    'boxWidth' => 'w-[140px]',
])

@php
    // Normalize: each signer is ['label'=>..., 'label_bn'=>...]
    $normalized = [];
    foreach ($signers as $s) {
        if (is_array($s)) {
            $normalized[] = ['label' => $s['label'] ?? '', 'label_bn' => $s['label_bn'] ?? null];
        } else {
            $normalized[] = ['label' => (string) $s, 'label_bn' => null];
        }
    }
@endphp

<div {{ $attributes->merge(['class' => 'flex justify-between mt-8 gap-4']) }}>
    @foreach ($normalized as $signer)
        <div class="flex flex-col items-center {{ $boxWidth }}">
            <div class="h-[40px]"></div>
            <div class="w-full border-t border-black"></div>
            <p class="text-[10px] text-center mt-1 text-gray-700">
                {{ $signer['label'] }}
                @if ($signer['label_bn'])
                    <span class="block text-[9px] text-gray-500">{{ $signer['label_bn'] }}</span>
                @endif
            </p>
        </div>
    @endforeach
</div>
