{{--
  x-erp.empty-state — no-data state for tables/lists/filters.

  Usage:
    <x-erp.empty-state icon="inbox" title="No invoices found" title-bn="কোনো চালান পাওয়া যায়নি"
        message="Try changing the filter or branch." />
    <x-erp.empty-state icon="inbox" title="Empty">
        <x-slot:action>
            <button class="...">Create one</button>
        </x-slot:action>
    </x-erp.empty-state>
--}}
@props([
    'icon' => 'inbox',
    'title' => '',
    'titleBn' => '',
    'message' => '',
    'messageBn' => '',
])

<div {{ $attributes->merge(['class' => 'flex flex-col items-center justify-center py-12 px-4 text-center']) }}>
    <div class="mb-3 size-14 rounded-full bg-amber-50 flex items-center justify-center">
        <x-erp.icon :name="$icon" class="size-7 text-amber-400" />
    </div>
    <p class="text-sm font-semibold text-gray-700">
        {{ $title }}
        @if ($titleBn)
            <span class="text-gray-500 font-normal ml-1.5">/ {{ $titleBn }}</span>
        @endif
    </p>
    @if ($message)
        <p class="text-xs text-gray-500 mt-1 max-w-sm">
            {{ $message }}
            @if ($messageBn)
                <span class="block mt-0.5">{{ $messageBn }}</span>
            @endif
        </p>
    @endif
    @isset($action)
        <div class="mt-4">{{ $action }}</div>
    @endisset
</div>
