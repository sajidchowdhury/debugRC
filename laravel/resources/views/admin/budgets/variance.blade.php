@extends('layouts.admin')

@section('title', 'Budget vs Actual')

@section('content')
<div class="space-y-6">
    {{-- Header --}}
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-slate-800">Budget vs Actual Variance Report</h1>
            <p class="text-sm text-slate-500 mt-1">Compare budgeted amounts against actual postings for the fiscal year</p>
        </div>
        <div class="flex gap-2">
            @if($budget)
            <a href="{{ route('admin.budgets.export-csv', ['fiscal_year' => $fiscalYear, 'branch_id' => $selectedBranch]) }}" class="px-4 py-2 bg-emerald-50 text-emerald-700 rounded-lg text-sm hover:bg-emerald-100 transition border border-emerald-200">
                <i class="fas fa-file-csv mr-1"></i> Export CSV
            </a>
            @endif
            <a href="{{ route('admin.budgets.index') }}" class="px-4 py-2 text-slate-500 hover:text-slate-700 text-sm">
                <i class="fas fa-arrow-left mr-1"></i> Budgets
            </a>
        </div>
    </div>

    {{-- Filters --}}
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
        <form method="GET" action="{{ route('admin.budgets.variance') }}" class="flex flex-wrap gap-3 items-end">
            <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">Fiscal Year</label>
                <input type="text" name="fiscal_year" value="{{ $fiscalYear }}" class="border border-slate-300 rounded-lg px-3 py-2 text-sm w-28 focus:ring-2 focus:ring-indigo-500">
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
            <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">Period</label>
                <select name="period" class="border border-slate-300 rounded-lg px-3 py-2 text-sm w-28 focus:ring-2 focus:ring-indigo-500">
                    <option value="">All Periods</option>
                    @for($p = 1; $p <= 12; $p++)
                        <option value="{{ $p }}" {{ $selectedPeriod == $p ? 'selected' : '' }}>{{ $p }}</option>
                    @endfor
                </select>
            </div>
            <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm hover:bg-indigo-700 transition">
                <i class="fas fa-search mr-1"></i> Generate
            </button>
        </form>
    </div>

    @if(!$budget)
    <div class="bg-yellow-50 border border-yellow-200 rounded-xl p-6 text-center">
        <i class="fas fa-exclamation-triangle text-yellow-500 text-2xl mb-2"></i>
        <p class="text-yellow-800">No active budget found for FY {{ $fiscalYear }}. Create and activate a budget first.</p>
    </div>
    @elseif($varianceData)
    {{-- Summary Cards --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
            <div class="text-xs text-slate-500 uppercase tracking-wide">Total Budget</div>
            <div class="mt-1 text-2xl font-bold text-slate-800 font-mono">{{ number_format($varianceData['totals']['budget_amount'], 2) }}</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
            <div class="text-xs text-slate-500 uppercase tracking-wide">Total Actual</div>
            <div class="mt-1 text-2xl font-bold {{ $varianceData['totals']['actual_amount'] > $varianceData['totals']['budget_amount'] ? 'text-red-600' : 'text-green-600' }} font-mono">{{ number_format($varianceData['totals']['actual_amount'], 2) }}</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
            <div class="text-xs text-slate-500 uppercase tracking-wide">Total Variance</div>
            <div class="mt-1 text-2xl font-bold {{ $varianceData['totals']['variance_amount'] < 0 ? 'text-red-600' : 'text-green-600' }} font-mono">{{ number_format($varianceData['totals']['variance_amount'], 2) }}</div>
        </div>
    </div>

    {{-- Variance Detail Table --}}
    @foreach($varianceData['lines'] as $type => $lines)
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="px-4 py-3 border-b border-slate-200 bg-slate-50">
            <h2 class="font-semibold text-slate-800">
                <i class="fas fa-{{ $type == 'Expense' ? 'arrow-down text-red-500' : 'arrow-up text-green-500' }} mr-1"></i>
                {{ $type }} Accounts
            </h2>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-slate-50 border-b border-slate-200">
                        <th class="text-left px-4 py-2 font-semibold text-slate-600">Ledger</th>
                        <th class="text-center px-4 py-2 font-semibold text-slate-600">Period</th>
                        <th class="text-right px-4 py-2 font-semibold text-slate-600">Budget</th>
                        <th class="text-right px-4 py-2 font-semibold text-slate-600">Actual</th>
                        <th class="text-right px-4 py-2 font-semibold text-slate-600">Variance</th>
                        <th class="text-center px-4 py-2 font-semibold text-slate-600">Variance %</th>
                        <th class="text-center px-4 py-2 font-semibold text-slate-600">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($lines as $row)
                    @php
                        $variance = (float) $row->variance_amount;
                        $isOver = $variance < 0;
                        $usagePct = $row->budget_amount > 0 ? ((float) $row->actual_amount / (float) $row->budget_amount * 100) : 0;
                    @endphp
                    <tr class="border-b border-slate-100 hover:bg-slate-50">
                        <td class="px-4 py-2">
                            <span class="text-xs text-slate-400 mr-1">{{ $row->ledger_code }}</span>
                            {{ $row->ledger_name }}
                        </td>
                        <td class="px-4 py-2 text-center">{{ $row->period }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ number_format((float) $row->budget_amount, 2) }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ number_format((float) $row->actual_amount, 2) }}</td>
                        <td class="px-4 py-2 text-right font-mono {{ $isOver ? 'text-red-600' : 'text-green-600' }}">
                            {{ number_format($variance, 2) }}
                        </td>
                        <td class="px-4 py-2 text-center">
                            @if($row->variance_percent !== null)
                                <span class="{{ $isOver ? 'text-red-600' : 'text-green-600' }}">{{ $row->variance_percent }}%</span>
                            @else
                                -
                            @endif
                        </td>
                        <td class="px-4 py-2 text-center">
                            @if($isOver)
                                <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">Over Budget</span>
                            @elseif($usagePct >= 80)
                                <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">Warning</span>
                            @else
                                <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">On Track</span>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endforeach
    @endif
</div>
@endsection
