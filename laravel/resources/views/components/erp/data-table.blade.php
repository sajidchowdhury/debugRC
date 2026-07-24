{{--
  x-erp.data-table — generic table with sticky amber-tinted header + scroll.

  Usage (columns via $cols prop, rows via $rows prop):
    <x-erp.data-table :cols="[
        ['key' => 'code',     'header' => 'Invoice Code'],
        ['key' => 'customer', 'header' => 'Customer'],
        ['key' => 'total',    'header' => 'Total (৳)', 'header_class' => 'text-right', 'cell_class' => 'text-right font-semibold', 'raw' => true],
    ]" :rows="$invoices" :row-key="fn($r) => $r['code']" />

  Or via slots (full control over cells):
    <x-erp.data-table :columns="[['key'=>'code','header'=>'Code'],['key'=>'total','header'=>'Total']]"
        :rows="$invoices" row-key="code">
        <x-slot:cell-code="{ row }">
            <span class="font-medium text-amber-900">{{ $row['code'] }}</span>
        </x-slot:cell-code>
    </x-erp.data-table>

  cols[] fields: key, header, header_bn?, header_class?, cell_class?, raw?
    - raw=true → cell prints row[$key] without Blade auto-escaping (use ONLY for trusted HTML/closures)
    - raw absent/false → cell prints e(row[$key]) (escaped)
  rows: array of arrays/objects. Each row[$col['key']] is the cell value.
  rowKey: closure or string column name (default: row index).

  Showcase spec: bg-white rounded-xl shadow-sm overflow-hidden;
  thead bg-amber-50/50 sticky top-0; rows hover:bg-amber-50/30 border-b border-gray-100.
--}}
@props([
    'cols' => [],
    'rows' => [],
    'rowKey' => null,
    'maxHeight' => 'max-h-96',
    'accent' => 'amber',
    'emptyState' => null,
])

@php
    $a = App\Support\Accents::get($accent);
    // Build thead bg class from accent (e.g. amber → bg-amber-50)
    $theadBg = $a['bg_50'];
    // Build an explicit mapping in case bg_50 ever returns empty
    $bg50Map = ['amber' => 'bg-amber-50', 'orange' => 'bg-orange-50', 'cyan' => 'bg-cyan-50', 'green' => 'bg-green-50', 'red' => 'bg-red-50', 'yellow' => 'bg-yellow-50', 'blue' => 'bg-blue-50', 'gray' => 'bg-gray-50'];
    $theadBg = $bg50Map[$accent] ?? 'bg-amber-50';

    // Normalize row key
    $keyResolver = null;
    if (is_callable($rowKey)) {
        $keyResolver = $rowKey;
    } elseif (is_string($rowKey)) {
        $keyResolver = function ($row, $i) use ($rowKey) {
            return is_array($row) ? ($row[$rowKey] ?? $i) : (is_object($row) ? ($row->{$rowKey} ?? $i) : $i);
        };
    } else {
        $keyResolver = function ($row, $i) { return $i; };
    };

    // Value getter for a cell
    $getValue = function ($row, $key) {
        if (is_array($row)) return $row[$key] ?? '';
        if (is_object($row)) return $row->{$key} ?? '';
        return '';
    };
@endphp

<div {{ $attributes->merge(['class' => 'bg-white rounded-xl shadow-sm overflow-hidden']) }}>
    <div class="overflow-y-auto custom-scroll {{ $maxHeight }}">
        <table class="w-full text-sm">
            <thead>
                <tr class="sticky top-0 z-10 {{ $theadBg }}">
                    @foreach ($cols as $col)
                        <th class="px-4 py-3 text-left font-medium text-gray-600 border-b {{ $col['header_class'] ?? '' }}">
                            {{ $col['header'] }}
                            @if (!empty($col['header_bn']))
                                <span class="block text-[9px] text-gray-400 font-normal">{{ $col['header_bn'] }}</span>
                            @endif
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @if (empty($rows) && $emptyState)
                    <tr>
                        <td colspan="{{ count($cols) }}" class="p-0">{{ $emptyState }}</td>
                    </tr>
                @else
                    @foreach ($rows as $i => $row)
                        @php $rowKeyVal = $keyResolver($row, $i); @endphp
                        <tr class="hover:bg-amber-50/30 border-b border-gray-100 transition-colors">
                            @foreach ($cols as $col)
                                @php
                                    $slotName = 'cell-' . $col['key'];
                                    $cellClass = $col['cell_class'] ?? '';
                                    $value = $getValue($row, $col['key']);
                                    $isRaw = $col['raw'] ?? false;
                                @endphp
                                <td class="px-4 py-3 text-gray-700 {{ $cellClass }}">
                                    @if ($slot->has($slotName))
                                        {{ $slot->{$slotName} }}
                                    @elseif ($isRaw)
                                        {!! $value !!}
                                    @else
                                        {{ $value }}
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                @endif
            </tbody>
        </table>
    </div>
</div>
