{{--
  x-erp.skeleton — loading placeholder.

  Usage:
    <x-erp.skeleton type="card" />
    <x-erp.skeleton type="text" class="w-40" />
    <x-erp.skeleton type="table" :rows="5" />
    <x-erp.skeleton type="row" />
    <x-erp.skeleton type="circle" />

  Types:
    card   — card-shaped block (h-32)
    row    — table row (h-12)
    text   — line of text (h-4, width via class)
    circle — avatar/icon placeholder (size-10)
    table  — stack of rows (rows count, default 5)
--}}
@props([
    'type' => 'text',
    'rows' => 5,
])

@switch($type)
    @case('table')
        <div {{ $attributes->merge(['class' => 'space-y-2']) }}>
            @for ($i = 0; $i < $rows; $i++)
                <div class="h-12 rounded-md bg-amber-50/60 animate-pulse"></div>
            @endfor
        </div>
        @break

    @case('card')
        <div {{ $attributes->merge(['class' => 'h-32 rounded-xl bg-amber-50/60 animate-pulse']) }}></div>
        @break

    @case('row')
        <div {{ $attributes->merge(['class' => 'h-12 rounded-md bg-amber-50/60 animate-pulse']) }}></div>
        @break

    @case('circle')
        <div {{ $attributes->merge(['class' => 'size-10 rounded-full bg-amber-50/60 animate-pulse']) }}></div>
        @break

    @case('text')
    @default
        <div {{ $attributes->merge(['class' => 'h-4 rounded bg-amber-50/60 animate-pulse']) }}></div>
        @break
@endswitch
