@extends('layouts.admin')

@section('title', $budget->name)

@section('content')
<div class="space-y-6">
    {{-- Header --}}
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-slate-800">{{ $budget->name }}</h1>
            <p class="text-sm text-slate-500 mt-1">FY {{ $budget->fiscal_year }} &middot; {{ ucfirst($budget->period_type) }} &middot; {{ $budget->branch?->branch_name ?? 'All Branches' }}</p>
        </div>
        <div class="flex items-center gap-2">
            @if($budget->isEditable())
            <a href="{{ route('admin.budgets.edit', $budget) }}" class="px-4 py-2 bg-indigo-50 text-indigo-700 rounded-lg text-sm hover:bg-indigo-100 transition border border-indigo-200">
                <i class="fas fa-pen mr-1"></i> Edit
            </a>
            <form method="POST" action="{{ route('admin.budgets.activate', $budget) }}" class="inline">
                @csrf @method('PATCH')
                <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded-lg text-sm hover:bg-green-700 transition"
                    onclick="return confirm('Activate this budget? It will be used for budget control checks.')">
                    <i class="fas fa-check mr-1"></i> Activate
                </button>
            </form>
            @endif
            @if($budget->isActive())
            <form method="POST" action="{{ route('admin.budgets.close', $budget) }}" class="inline">
                @csrf @method('PATCH')
                <button type="submit" class="px-4 py-2 bg-slate-600 text-white rounded-lg text-sm hover:bg-slate-700 transition"
                    onclick="return confirm('Close this budget? No further actuals will be tracked.')">
                    <i class="fas fa-lock mr-1"></i> Close
                </button>
            </form>
            @endif
            <a href="{{ route('admin.budgets.index') }}" class="px-4 py-2 text-slate-500 hover:text-slate-700 text-sm">
                <i class="fas fa-arrow-left mr-1"></i> Back
            </a>
        </div>
    </div>

    {{-- Budget Info Cards --}}
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
            <div class="text-xs text-slate-500 uppercase tracking-wide">Status</div>
            @php
                $statusColors = ['draft' => 'bg-yellow-100 text-yellow-800', 'active' => 'bg-green-100 text-green-800', 'closed' => 'bg-slate-100 text-slate-700', 'cancelled' => 'bg-red-100 text-red-800'];
            @endphp
            <div class="mt-1"><span class="inline-flex px-3 py-1 rounded-full text-sm font-medium {{ $statusColors[$budget->status] ?? '' }}">{{ ucfirst($budget->status) }}</span></div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
            <div class="text-xs text-slate-500 uppercase tracking-wide">Total Budget</div>
            <div class="mt-1 text-xl font-bold text-slate-800 font-mono">{{ number_format($budget->total_amount, 2) }}</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
            <div class="text-xs text-slate-500 uppercase tracking-wide">Created By</div>
            <div class="mt-1 text-sm text-slate-700">{{ $budget->creator?->username ?? 'System' }}</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
            <div class="text-xs text-slate-500 uppercase tracking-wide">Created At</div>
            <div class="mt-1 text-sm text-slate-700">{{ $budget->created_at?->format('M d, Y') }}</div>
        </div>
    </div>

    {{-- Budget Lines --}}
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="px-4 py-3 border-b border-slate-200 flex items-center justify-between">
            <h2 class="font-semibold text-slate-800">Budget Lines</h2>
            <span class="text-xs text-slate-500">{{ $budget->lines->count() }} line items</span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-slate-50 border-b border-slate-200">
                        <th class="text-left px-4 py-2 font-semibold text-slate-600">Ledger</th>
                        <th class="text-left px-4 py-2 font-semibold text-slate-600">Type</th>
                        <th class="text-center px-4 py-2 font-semibold text-slate-600">Period</th>
                        <th class="text-right px-4 py-2 font-semibold text-slate-600">Budgeted</th>
                        @if($budget->isActive() || $budget->status === 'closed')
                        <th class="text-right px-4 py-2 font-semibold text-slate-600">Actual</th>
                        <th class="text-right px-4 py-2 font-semibold text-slate-600">Variance</th>
                        <th class="text-center px-4 py-2 font-semibold text-slate-600">%</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @forelse($budget->lines->groupBy('ledger.account_type') as $type => $lines)
                        <tr class="bg-slate-100">
                            <td colspan="{{ ($budget->isActive() || $budget->status === 'closed') ? 7 : 4 }}" class="px-4 py-2 font-semibold text-slate-700">
                                <i class="fas fa-{{ $type == 'Expense' ? 'arrow-down text-red-500' : 'arrow-up text-green-500' }} mr-1"></i>
                                {{ $type }} Accounts
                            </td>
                        </tr>
                        @foreach($lines as $line)
                        <tr class="border-b border-slate-100 hover:bg-slate-50">
                            <td class="px-4 py-2">
                                <span class="text-xs text-slate-400 mr-1">{{ $line->ledger?->ledger_code }}</span>
                                {{ $line->ledger?->ledger_name }}
                            </td>
                            <td class="px-4 py-2 text-xs {{ $line->ledger?->account_type == 'Expense' ? 'text-red-500' : 'text-green-600' }}">{{ $line->ledger?->account_type }}</td>
                            <td class="px-4 py-2 text-center">{{ $line->period }}</td>
                            <td class="px-4 py-2 text-right font-mono">{{ number_format($line->amount, 2) }}</td>
                            @if($budget->isActive() || $budget->status === 'closed')
                            @php
                                $bvaLine = $varianceData ? collect($varianceData['lines'][$type] ?? [])->firstWhere('ledger_id', $line->ledger_id) : null;
                            @endphp
                            <td class="px-4 py-2 text-right font-mono">{{ $bvaLine ? number_format((float) $bvaLine->actual_amount, 2) : '-' }}</td>
                            <td class="px-4 py-2 text-right font-mono {{ $bvaLine && (float) $bvaLine->variance_amount < 0 ? 'text-red-600' : 'text-green-600' }}">
                                {{ $bvaLine ? number_format((float) $bvaLine->variance_amount, 2) : '-' }}
                            </td>
                            <td class="px-4 py-2 text-center">
                                @if($bvaLine && $bvaLine->variance_percent !== null)
                                    <span class="{{ (float) $bvaLine->variance_percent < 0 ? 'text-red-600' : 'text-green-600' }}">
                                        {{ $bvaLine->variance_percent }}%
                                    </span>
                                @else
                                    -
                                @endif
                            </td>
                            @endif
                        </tr>
                        @endforeach
                    @empty
                    <tr>
                        <td colspan="7" class="px-4 py-8 text-center text-slate-400">No budget lines yet.</td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
