@extends('layouts.admin')

@section('title', 'Budgets')

@section('content')
<div class="space-y-6">
    {{-- Header --}}
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-slate-800">Budgets</h1>
            <p class="text-sm text-slate-500 mt-1">Manage budget definitions and track budget vs actual variances</p>
        </div>
        <a href="{{ route('admin.budgets.create') }}" class="inline-flex items-center gap-2 px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition">
            <i class="fas fa-plus"></i> New Budget
        </a>
    </div>

    {{-- Filters --}}
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
        <form method="GET" action="{{ route('admin.budgets.index') }}" class="flex flex-wrap gap-3 items-end">
            <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">Fiscal Year</label>
                <input type="text" name="fiscal_year" value="{{ $filters['fiscal_year'] ?? '' }}" placeholder="e.g. 2026"
                    class="border border-slate-300 rounded-lg px-3 py-2 text-sm w-28 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">Status</label>
                <select name="status" class="border border-slate-300 rounded-lg px-3 py-2 text-sm w-36 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                    <option value="">All Statuses</option>
                    <option value="draft" {{ ($filters['status'] ?? '') == 'draft' ? 'selected' : '' }}>Draft</option>
                    <option value="active" {{ ($filters['status'] ?? '') == 'active' ? 'selected' : '' }}>Active</option>
                    <option value="closed" {{ ($filters['status'] ?? '') == 'closed' ? 'selected' : '' }}>Closed</option>
                    <option value="cancelled" {{ ($filters['status'] ?? '') == 'cancelled' ? 'selected' : '' }}>Cancelled</option>
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">Branch</label>
                <select name="branch_id" class="border border-slate-300 rounded-lg px-3 py-2 text-sm w-40 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                    <option value="">All Branches</option>
                    @foreach($branches as $branch)
                        <option value="{{ $branch->id }}" {{ ($filters['branch_id'] ?? '') == $branch->id ? 'selected' : '' }}>{{ $branch->branch_name }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="px-4 py-2 bg-slate-100 text-slate-700 rounded-lg text-sm hover:bg-slate-200 transition">
                <i class="fas fa-filter mr-1"></i> Filter
            </button>
            <a href="{{ route('admin.budgets.index') }}" class="px-4 py-2 text-slate-500 text-sm hover:text-slate-700">Clear</a>
        </form>
    </div>

    {{-- Quick Links --}}
    <div class="flex gap-3">
        <a href="{{ route('admin.budgets.variance') }}" class="inline-flex items-center gap-2 px-4 py-2 bg-emerald-50 text-emerald-700 rounded-lg text-sm hover:bg-emerald-100 transition border border-emerald-200">
            <i class="fas fa-chart-bar"></i> Budget vs Actual
        </a>
    </div>

    {{-- Budget List --}}
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-slate-50 border-b border-slate-200">
                        <th class="text-left px-4 py-3 font-semibold text-slate-600">Budget Name</th>
                        <th class="text-left px-4 py-3 font-semibold text-slate-600">Fiscal Year</th>
                        <th class="text-left px-4 py-3 font-semibold text-slate-600">Branch</th>
                        <th class="text-left px-4 py-3 font-semibold text-slate-600">Period</th>
                        <th class="text-right px-4 py-3 font-semibold text-slate-600">Total Amount</th>
                        <th class="text-center px-4 py-3 font-semibold text-slate-600">Status</th>
                        <th class="text-center px-4 py-3 font-semibold text-slate-600">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($budgets as $budget)
                    <tr class="border-b border-slate-100 hover:bg-slate-50 transition">
                        <td class="px-4 py-3">
                            <a href="{{ route('admin.budgets.show', $budget) }}" class="text-indigo-600 hover:text-indigo-800 font-medium">{{ $budget->name }}</a>
                        </td>
                        <td class="px-4 py-3 text-slate-700">{{ $budget->fiscal_year }}</td>
                        <td class="px-4 py-3 text-slate-700">{{ $budget->branch?->branch_name ?? 'All Branches' }}</td>
                        <td class="px-4 py-3 text-slate-700 capitalize">{{ $budget->period_type }}</td>
                        <td class="px-4 py-3 text-right font-mono text-slate-700">{{ number_format($budget->total_amount, 2) }}</td>
                        <td class="px-4 py-3 text-center">
                            @php
                                $statusColors = ['draft' => 'bg-yellow-100 text-yellow-800', 'active' => 'bg-green-100 text-green-800', 'closed' => 'bg-slate-100 text-slate-700', 'cancelled' => 'bg-red-100 text-red-800'];
                            @endphp
                            <span class="inline-flex px-2.5 py-0.5 rounded-full text-xs font-medium {{ $statusColors[$budget->status] ?? 'bg-slate-100 text-slate-600' }}">{{ ucfirst($budget->status) }}</span>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <div class="flex items-center justify-center gap-1">
                                <a href="{{ route('admin.budgets.show', $budget) }}" class="p-1.5 text-slate-400 hover:text-indigo-600 transition" title="View">
                                    <i class="fas fa-eye"></i>
                                </a>
                                @if($budget->isEditable())
                                <a href="{{ route('admin.budgets.edit', $budget) }}" class="p-1.5 text-slate-400 hover:text-indigo-600 transition" title="Edit">
                                    <i class="fas fa-pen"></i>
                                </a>
                                @endif
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="7" class="px-4 py-12 text-center text-slate-400">
                            <i class="fas fa-inbox text-3xl mb-2"></i>
                            <p>No budgets found. Create your first budget to get started.</p>
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($budgets->hasPages())
        <div class="px-4 py-3 border-t border-slate-200">
            {{ $budgets->withQueryString()->links() }}
        </div>
        @endif
    </div>
</div>
@endsection
