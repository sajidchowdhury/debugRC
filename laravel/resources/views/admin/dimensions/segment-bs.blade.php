@extends('layouts.admin')

@section('title', 'Segment Balance Sheet')

@section('content')
<div class="space-y-6">
    {{-- Header --}}
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-slate-800">Segment Balance Sheet</h1>
            <p class="text-sm text-slate-500 mt-1">Balance Sheet by dimension value</p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('admin.dimensions.segment-pnl') }}" class="px-4 py-2 bg-emerald-50 text-emerald-700 rounded-lg text-sm hover:bg-emerald-100 transition border border-emerald-200">
                <i class="fas fa-chart-line mr-1"></i> Segment P&L
            </a>
            <a href="{{ route('admin.dimensions.index') }}" class="px-4 py-2 text-slate-500 hover:text-slate-700 text-sm">
                <i class="fas fa-arrow-left mr-1"></i> Dimensions
            </a>
        </div>
    </div>

    {{-- Filters --}}
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
        <form method="GET" action="{{ route('admin.dimensions.segment-bs') }}" class="flex flex-wrap gap-3 items-end">
            <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">Dimension Value</label>
                <select name="dimension_value_id" class="border border-slate-300 rounded-lg px-3 py-2 text-sm w-48 focus:ring-2 focus:ring-indigo-500">
                    <option value="">Select a value</option>
                    @foreach($dimensions as $dim)
                        <optgroup label="{{ $dim->name }}">
                            @foreach($dim->values as $val)
                                <option value="{{ $val->id }}" {{ $selectedValue == $val->id ? 'selected' : '' }}>{{ $val->name }}</option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">As of Date</label>
                <input type="date" name="as_of_date" value="{{ $asOfDate }}" class="border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500">
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

    @if($segmentData)
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="px-4 py-3 border-b border-slate-200 bg-slate-50">
            <h2 class="font-semibold text-slate-800">
                {{ $segmentData['dimension_value']->name }}
                <span class="text-xs text-slate-500 ml-2">({{ $segmentData['dimension']->name }}) as of {{ $asOfDate }}</span>
            </h2>
        </div>
        <div class="p-4">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                {{-- Assets --}}
                <div>
                    <h3 class="font-semibold text-slate-700 mb-2 pb-1 border-b border-slate-200">
                        <i class="fas fa-arrow-up text-blue-500 mr-1"></i> Assets
                    </h3>
                    <div class="text-2xl font-bold font-mono text-blue-700">{{ number_format($segmentData['assets'], 2) }}</div>
                </div>
                {{-- Liabilities --}}
                <div>
                    <h3 class="font-semibold text-slate-700 mb-2 pb-1 border-b border-slate-200">
                        <i class="fas fa-arrow-down text-red-500 mr-1"></i> Liabilities
                    </h3>
                    <div class="text-2xl font-bold font-mono text-red-600">{{ number_format($segmentData['liabilities'], 2) }}</div>
                </div>
                {{-- Equity --}}
                <div>
                    <h3 class="font-semibold text-slate-700 mb-2 pb-1 border-b border-slate-200">
                        <i class="fas fa-balance-scale text-green-500 mr-1"></i> Equity
                    </h3>
                    <div class="text-2xl font-bold font-mono text-green-600">{{ number_format($segmentData['equity'], 2) }}</div>
                </div>
            </div>
            <div class="mt-4 pt-4 border-t border-slate-200 flex items-center justify-between">
                <div>
                    <span class="text-sm text-slate-600">Total Assets = Liabilities + Equity</span>
                </div>
                <div class="flex gap-4">
                    <span class="font-mono font-bold text-blue-700">{{ number_format($segmentData['total_assets'], 2) }}</span>
                    <span class="text-slate-400">=</span>
                    <span class="font-mono font-bold text-slate-700">{{ number_format($segmentData['total_liabilities_equity'], 2) }}</span>
                </div>
            </div>
        </div>
    </div>
    @else
    <div class="bg-slate-50 border border-slate-200 rounded-xl p-12 text-center text-slate-400">
        <i class="fas fa-balance-scale text-3xl mb-2"></i>
        <p>Select a dimension value and date to generate the segment Balance Sheet.</p>
    </div>
    @endif
</div>
@endsection
