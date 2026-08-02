@extends('layouts.admin')

@section('title', 'Create Budget')

@section('content')
<div class="space-y-6">
    {{-- Header --}}
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-slate-800">Create Budget</h1>
            <p class="text-sm text-slate-500 mt-1">Enter budget amounts for each ledger and period</p>
        </div>
        <a href="{{ route('admin.budgets.index') }}" class="text-slate-500 hover:text-slate-700 text-sm">
            <i class="fas fa-arrow-left mr-1"></i> Back to Budgets
        </a>
    </div>

    {{-- Budget Settings --}}
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
        <form id="budgetForm" method="POST" action="{{ route('admin.budgets.store') }}">
            @csrf
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Budget Name <span class="text-red-500">*</span></label>
                    <input type="text" name="name" value="{{ $gridData['budget']?->name ?? "Budget FY {$fiscalYear}" }}" required
                        class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Fiscal Year</label>
                    <input type="text" name="fiscal_year" value="{{ $fiscalYear }}" id="fiscalYear"
                        class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-slate-50" readonly>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Branch</label>
                    <select name="branch_id" id="branchSelect" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                        <option value="">All Branches</option>
                        @foreach($branches as $branch)
                            <option value="{{ $branch->id }}" {{ $branchId == $branch->id ? 'selected' : '' }}>{{ $branch->branch_name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Period Type</label>
                    <select name="period_type" id="periodType" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                        <option value="monthly" {{ $periodType == 'monthly' ? 'selected' : '' }}>Monthly</option>
                        <option value="quarterly" {{ $periodType == 'quarterly' ? 'selected' : '' }}>Quarterly</option>
                        <option value="yearly" {{ $periodType == 'yearly' ? 'selected' : '' }}>Yearly</option>
                    </select>
                </div>
            </div>

            {{-- Budget Grid --}}
            <div class="overflow-x-auto border border-slate-200 rounded-lg">
                <table class="w-full text-sm budget-grid">
                    <thead>
                        <tr class="bg-slate-800 text-white">
                            <th class="text-left px-3 py-2 sticky left-0 bg-slate-800 z-10 min-w-[200px]">Ledger</th>
                            <th class="text-left px-2 py-2 min-w-[60px]">Type</th>
                            @foreach($gridData['period_labels'] as $p => $label)
                            <th class="text-right px-2 py-2 min-w-[110px]">{{ $label }}</th>
                            @endforeach
                            <th class="text-right px-2 py-2 min-w-[110px] bg-slate-700">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @php
                            $currentType = '';
                        @endphp
                        @foreach($gridData['ledgers'] as $i => $ledger)
                            @if($ledger['account_type'] !== $currentType)
                                @php $currentType = $ledger['account_type']; @endphp
                                <tr class="bg-slate-100">
                                    <td colspan="{{ 3 + $gridData['max_period'] }}" class="px-3 py-2 font-semibold text-slate-700">
                                        <i class="fas fa-{{ $currentType == 'Expense' ? 'arrow-down text-red-500' : 'arrow-up text-green-500' }} mr-1"></i>
                                        {{ $currentType }} Accounts
                                    </td>
                                </tr>
                            @endif
                            <tr class="border-b border-slate-100 hover:bg-slate-50">
                                <td class="px-3 py-1.5 sticky left-0 bg-white z-10">
                                    <span class="text-xs text-slate-400 mr-1">{{ $ledger['ledger_code'] }}</span>
                                    {{ $ledger['ledger_name'] }}
                                </td>
                                <td class="px-2 py-1.5">
                                    <span class="text-xs {{ $ledger['account_type'] == 'Expense' ? 'text-red-500' : 'text-green-600' }}">{{ $ledger['account_type'] }}</span>
                                </td>
                                @foreach($gridData['period_labels'] as $p => $label)
                                <td class="px-1 py-1">
                                    <input type="number" step="0.01" min="0"
                                        name="lines[{{ $i }}][periods][{{ $p }}]"
                                        value="{{ $ledger['periods'][$p]['amount'] ?? '' }}"
                                        data-row="{{ $i }}"
                                        class="w-full border border-slate-200 rounded px-2 py-1 text-right text-sm font-mono focus:ring-1 focus:ring-indigo-500 focus:border-indigo-500 budget-input"
                                        placeholder="0.00">
                                </td>
                                @endforeach
                                <td class="px-2 py-1.5 text-right font-mono font-semibold bg-slate-50 row-total" data-row="{{ $i }}">
                                    0.00
                                </td>
                            </tr>
                            <input type="hidden" name="lines[{{ $i }}][ledger_id]" value="{{ $ledger['ledger_id'] }}">
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="bg-slate-800 text-white font-semibold">
                            <td class="px-3 py-2 sticky left-0 bg-slate-800 z-10" colspan="2">Grand Total</td>
                            @foreach($gridData['period_labels'] as $p => $label)
                            <td class="px-2 py-2 text-right font-mono" id="col-total-{{ $p }}">0.00</td>
                            @endforeach
                            <td class="px-2 py-2 text-right font-mono bg-slate-700" id="grand-total">0.00</td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            {{-- Actions --}}
            <div class="flex items-center justify-between mt-6 pt-4 border-t border-slate-200">
                <a href="{{ route('admin.budgets.index') }}" class="px-4 py-2 text-slate-600 hover:text-slate-800 text-sm">Cancel</a>
                <button type="submit" class="px-6 py-2.5 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition font-medium">
                    <i class="fas fa-save mr-2"></i> Save as Draft
                </button>
            </div>
        </form>
    </div>
</div>

@section('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Auto-calculate row and column totals
    function updateTotals() {
        const rows = {};
        const colTotals = {};
        let grandTotal = 0;

        document.querySelectorAll('.budget-input').forEach(input => {
            const row = input.dataset.row;
            const name = input.name;
            const match = name.match(/periods\]\[(\d+)\]/);
            if (!match) return;
            const period = match[1];

            const val = parseFloat(input.value) || 0;
            rows[row] = (rows[row] || 0) + val;
            colTotals[period] = (colTotals[period] || 0) + val;
            grandTotal += val;
        });

        // Update row totals
        Object.entries(rows).forEach(([row, total]) => {
            const el = document.querySelector(`.row-total[data-row="${row}"]`);
            if (el) el.textContent = total.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        });

        // Update column totals
        Object.entries(colTotals).forEach(([period, total]) => {
            const el = document.getElementById(`col-total-${period}`);
            if (el) el.textContent = total.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        });

        // Update grand total
        const gt = document.getElementById('grand-total');
        if (gt) gt.textContent = grandTotal.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    document.querySelectorAll('.budget-input').forEach(input => {
        input.addEventListener('input', updateTotals);
    });

    updateTotals();
});
</script>
@endsection
@endsection
