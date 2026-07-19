@extends('admin.partials.print-layout')

@section('print_content')
<div class="table-responsive">
    <table class="print-table">
        <thead>
            <tr>
                @foreach ($columns as $key => $label)
                    <th>{{ $label }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($items as $item)
                <tr>
                    @foreach ($columns as $key => $label)
                        <td>
                            @php
                                $value = $item;
                                if (str_contains($key, '.')) {
                                    foreach (explode('.', $key) as $segment) {
                                        if ($value === null) break;
                                        $value = is_object($value) ? ($value->{$segment} ?? null) : null;
                                    }
                                } else {
                                    $value = $item->{$key};
                                }
                            @endphp

                            @if (is_bool($value))
                                <span class="{{ $value ? 'badge-active' : 'badge-inactive' }}">{{ $value ? 'Active' : 'Inactive' }}</span>
                            @elseif ($value instanceof \DateTimeInterface)
                                {{ $value->format('Y-m-d H:i:s') }}
                            @elseif (is_numeric($value) && in_array($key, ['balance', 'purchase_rate', 'sales_rate', 'amount', 'total', 'min_stock', 'max_stock', 'reorder_level']))
                                {{ number_format((float) $value, 2) }}
                            @elseif ($value === null || $value === '')
                                <span style="color:#9ca3af;">—</span>
                            @else
                                {{ (string) $value }}
                            @endif
                        </td>
                    @endforeach
                </tr>
            @empty
                <tr class="empty">
                    <td colspan="{{ count($columns) }}">No {{ strtolower($label) }} records found.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
