{{--
  x-erp.warning-callout — irreversible-action warning (amber box + alert-triangle).

  Usage:
    <x-erp.warning-callout title="This action is irreversible" title-bn="এই কাজটি ফিরিয়ে আনা যাবে না">
        <p>Issuing the challan will:</p>
        <ul class="list-disc list-inside">
            <li>Deduct stock from the assigned warehouses</li>
            <li>Post a COGS journal entry</li>
        </ul>
    </x-erp.warning-callout>

  Showcase spec: bg-amber-50 border border-amber-200 rounded-lg p-4
  flex items-start gap-3 with an AlertTriangle icon.
--}}
@props([
    'title' => 'Important',
    'titleBn' => 'গুরুত্বপূর্ণ',
])

<div {{ $attributes->merge(['class' => 'bg-amber-50 border border-amber-200 rounded-lg p-4 flex items-start gap-3']) }}>
    <x-erp.icon name="alert-triangle" class="size-5 text-amber-500 shrink-0 mt-0.5" />
    <div class="flex-1 min-w-0">
        <p class="text-sm font-semibold text-amber-800">
            {{ $title }}
            @if ($titleBn)<span class="font-normal text-amber-700 ml-1.5">/ {{ $titleBn }}</span>@endif
        </p>
        <div class="text-xs text-amber-700 mt-1 space-y-1">{{ $slot }}</div>
    </div>
</div>
