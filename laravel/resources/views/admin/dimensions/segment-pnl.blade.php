@extends('layouts.admin')

@section('title', 'Segment P&L Report')

@section('content')
<div class="space-y-6">
    {{-- Header --}}
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-slate-800">Segment Profit & Loss</h1>
            <p class="text-sm text-slate-500 mt-1">P&L by department, project, location, or any dimension</p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('admin.dimensions.segment-bs') }}" class="px-4 py-2 bg-blue-50 text-blue-700 rounded-lg text-sm hover:bg-blue-100 transition border border-blue-200">
                <i class="fas fa-balance-scale mr-1"></i> Segment BS
            </a>
            <a href="{{ route('admin.dimensions.index') }}" class="px-4 py-2 text-slate-500 hover:text-slate-700 text-sm">
                <i class="fas fa-arrow-left mr-1"></i> Dimensions
            </a>
        </div>
    </div>

    {{-- Filters --}}
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
        <form method="GET" action="{{ route('admin.dimensions.segment-pnl') }}" class="flex flex-wrap gap-3 items-end">
            <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">Dimension</label>
                <select name="dimension_id" id="dimensionSelect" class="border border-slate-300 rounded-lg px-3 py-2 text-sm w-40 focus:ring-2 focus:ring-indigo-500">
                    <option value="">Select Dimension</option>
                    @foreach($dimensions as $dim)
                        <option value="{{ $dim->id }}" {{ $selectedDimension == $dim->id ? 'selected' : '' }}>{{ $dim->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">Dimension Value</label>
                <select name="dimension_value_id" id="dimensionValueSelect" class="border border-slate-300 rounded-lg px-3 py-2 text-sm w-48 focus:ring-2 focus:ring-indigo-500">
                    <option value="">All (Comparison)</option>
                    @foreach($dimensions as $dim)
                        @if($selectedDimension == $dim->id)
                            @foreach($dim->values as $val)
                                <option value="{{ $val->id }}" {{ $selectedValue == $val->id ? 'selected' : '' }}>{{ $val->name }}</option>
                            @endforeach
                        @endif
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">From</label>
                <input type="date" name="from_date" value="{{ $fromDate }}" class="border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500">
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">To</label>
                <input type="date" name="to_date" value="{{ $toDate }}" class="border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500">
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">Branch</label>
                <select name="branch_id" class="border border-slate-300 rounded-lg px-3 py-2 text-sm w-40 focus:ring-2 focus:ring-indigo-500">
                    <option value="">All Branches</option>
                    @foreach($branches as $branch)
                        <option value="{{ $branch->id }}" {{ $selectedBranch == $branch->id ? 'selected' : '' }}>{{ $branch->branch_name }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm hover:bg-indigo-700 transition">
                <i class="fas fa-search mr-1"></i> Generate
            </button>
        </form>
    </div>

    {{-- Single Segment P&L --}}
    @if($segmentData)
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="px-4 py-3 border-b border-slate-200 bg-slate-50">
            <h2 class="font-semibold text-slate-800">
                {{ $segmentData['dimension_value']->name }}
                <span class="text-xs text-slate-500 ml-2">({{ $segmentData['dimension']->name }})</span>
            </h2>
        </div>
        <div class="p-4">
            <table class="w-full text-sm">
                <tbody>
                    <tr class="border-b border-slate-100">
                        <td class="py-2 text-slate-600">Revenue</td>
                        <td class="py-2 text-right font-mono text-green-600">{{ number_format($segmentData['revenue'], 2) }}</td>
                    </tr>
                    <tr class="border-b border-slate-100">
                        <td class="py-2 text-slate-600 pl-4">Less: Contra-Revenue</td>
                        <td class="py-2 text-right font-mono text-red-600">({{ number_format($segmentData['contra_revenue'], 2) }})</td>
                    </tr>
                    <tr class="border-b border-slate-200 bg-green-50">
                        <td class="py-2 font-semibold text-slate-800">Net Revenue</td>
                        <td class="py-2 text-right font-mono font-bold text-green-700">{{ number_format($segmentData['net_revenue'], 2) }}</td>
                    </tr>
                    <tr class="border-b border-slate-100">
                        <td class="py-2 text-slate-600">Cost of Goods Sold</td>
                        <td class="py-2 text-right font-mono text-red-600">({{ number_format($segmentData['cogs'], 2) }})</td>
                    </tr>
                    <tr class="border-b border-slate-200 bg-blue-50">
                        <td class="py-2 font-semibold text-slate-800">Gross Profit <span class="text-xs font-normal text-slate-500">({{ $segmentData['gross_margin'] }}%)</span></td>
                        <td class="py-2 text-right font-mono font-bold text-blue-700">{{ number_format($segmentData['gross_profit'], 2) }}</td>
                    </tr>
                    <tr class="border-b border-slate-100">
                        <td class="py-2 text-slate-600">Operating Expenses</td>
                        <td class="py-2 text-right font-mono text-red-600">({{ number_format($segmentData['operating_expense'], 2) }})</td>
                    </tr>
                    <tr class="bg-indigo-50">
                        <td class="py-3 font-bold text-slate-800">Net Operating Income <span class="text-xs font-normal text-slate-500">({{ $segmentData['net_margin'] }}%)</span></td>
                        <td class="py-3 text-right font-mono font-bold text-indigo-700">{{ number_format($segmentData['operating_income'], 2) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
    @endif

    {{-- Comparison Across All Values --}}
    @if($comparisonData)
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="px-4 py-3 border-b border-slate-200 bg-slate-50">
            <h2 class="font-semibold text-slate-800">{{ $comparisonData['dimension']->name }} — Comparison</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-slate-50 border-b border-slate-200">
                        <th class="text-left px-4 py-2 font-semibold text-slate-600">Segment</th>
                        <th class="text-right px-4 py-2 font-semibold text-slate-600">Revenue</th>
                        <th class="text-right px-4 py-2 font-semibold text-slate-600">COGS</th>
                        <th class="text-right px-4 py-2 font-semibold text-slate-600">Gross Profit</th>
                        <th class="text-right px-4 py-2 font-semibold text-slate-600">OpEx</th>
                        <th class="text-right px-4 py-2 font-semibold text-slate-600">Net Income</th>
                        <th class="text-center px-4 py-2 font-semibold text-slate-600">Margin</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($comparisonData['segments'] as $seg)
                    <tr class="border-b border-slate-100 hover:bg-slate-50">
                        <td class="px-4 py-2 font-medium text-slate-800">{{ $seg['dimension_value']->name }}</td>
                        <td class="px-4 py-2 text-right font-mono text-green-600">{{ number_format($seg['net_revenue'], 2) }}</td>
                        <td class="px-4 py-2 text-right font-mono text-red-600">{{ number_format($seg['cogs'], 2) }}</td>
                        <td class="px-4 py-2 text-right font-mono font-semibold">{{ number_format($seg['gross_profit'], 2) }}</td>
                        <td class="px-4 py-2 text-right font-mono text-red-600">{{ number_format($seg['operating_expense'], 2) }}</td>
                        <td class="px-4 py-2 text-right font-mono font-bold {{ $seg['operating_income'] >= 0 ? 'text-green-600' : 'text-red-600' }}">{{ number_format($seg['operating_income'], 2) }}</td>
                        <td class="px-4 py-2 text-center">{{ $seg['net_margin'] }}%</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif

    @if(!$segmentData && !$comparisonData)
    <div class="bg-slate-50 border border-slate-200 rounded-xl p-12 text-center text-slate-400">
        <i class="fas fa-chart-line text-3xl mb-2"></i>
        <p>Select a dimension and date range to generate the segment P&L report.</p>
    </div>
    @endif
</div>
@endsection
