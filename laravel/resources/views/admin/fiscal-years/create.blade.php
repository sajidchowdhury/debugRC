@extends('layouts.admin')

@section('title', 'Create Fiscal Year')

@section('content')
<div class="space-y-6">
    {{-- Header --}}
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-slate-800">Create Fiscal Year</h1>
            <p class="text-sm text-slate-500 mt-1">Define a new fiscal year with auto-generated periods</p>
        </div>
        <a href="{{ route('admin.fiscal-years.index') }}" class="text-slate-500 hover:text-slate-700 text-sm">
            <i class="fas fa-arrow-left mr-1"></i> Back to Fiscal Years
        </a>
    </div>

    {{-- Flash Messages --}}
    @if (session('error'))
        <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg text-sm">
            <i class="fas fa-exclamation-circle mr-1"></i> {{ session('error') }}
        </div>
    @endif

    {{-- Form --}}
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
        <form method="POST" action="{{ route('admin.fiscal-years.store') }}">
            @csrf
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Fiscal Year Name <span class="text-red-500">*</span></label>
                    <input type="text" name="name" value="{{ old('name', 'Fiscal Year ' . ($suggestedCode ?? '')) }}" required
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                    <p class="text-xs text-slate-400 mt-1">A descriptive name for this fiscal year</p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Fiscal Year Code <span class="text-red-500">*</span></label>
                    <input type="text" name="fiscal_year_code" value="{{ old('fiscal_year_code', $suggestedCode ?? '') }}" required
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                    <p class="text-xs text-slate-400 mt-1">Unique code (e.g. FY2026-27)</p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Start Date <span class="text-red-500">*</span></label>
                    <input type="date" name="start_date" value="{{ old('start_date', $suggestedStart ?? '') }}" required
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">End Date <span class="text-red-500">*</span></label>
                    <input type="date" name="end_date" value="{{ old('end_date', $suggestedEnd ?? '') }}" required
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Branch</label>
                    <select name="branch_id" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                        <option value="">All Branches</option>
                        @foreach($branches as $branch)
                            <option value="{{ $branch->id }}" {{ old('branch_id') == $branch->id ? 'selected' : '' }}>{{ $branch->branch_name }}</option>
                        @endforeach
                    </select>
                    <p class="text-xs text-slate-400 mt-1">Leave blank for a company-wide fiscal year</p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Period Type <span class="text-red-500">*</span></label>
                    <select name="period_type" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                        <option value="monthly" {{ old('period_type', 'monthly') === 'monthly' ? 'selected' : '' }}>Monthly (12 periods)</option>
                        <option value="quarterly" {{ old('period_type') === 'quarterly' ? 'selected' : '' }}>Quarterly (4 periods)</option>
                        <option value="yearly" {{ old('period_type') === 'yearly' ? 'selected' : '' }}>Yearly (1 period)</option>
                    </select>
                </div>

                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-slate-700 mb-1">Description</label>
                    <textarea name="description" rows="2" placeholder="Optional notes about this fiscal year"
                              class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">{{ old('description') }}</textarea>
                </div>
            </div>

            {{-- Info box --}}
            <div class="mt-6 bg-blue-50 border border-blue-200 rounded-lg p-4">
                <h4 class="text-blue-700 font-semibold text-sm mb-2"><i class="fas fa-info-circle mr-1"></i> How it works</h4>
                <ol class="text-xs text-blue-600 space-y-1 list-decimal list-inside">
                    <li>Create a fiscal year in <strong>draft</strong> status — periods are auto-generated</li>
                    <li>Activate it to make it the <strong>current fiscal year</strong></li>
                    <li>Close individual periods as you go (pre-close gate checks run automatically)</li>
                    <li>Execute <strong>year-end close</strong> when all periods are closed</li>
                    <li>Lock the fiscal year to prevent any further changes</li>
                </ol>
            </div>

            {{-- Actions --}}
            <div class="flex items-center justify-between mt-6 pt-4 border-t border-slate-200">
                <a href="{{ route('admin.fiscal-years.index') }}" class="px-4 py-2 text-slate-600 hover:text-slate-800 text-sm">Cancel</a>
                <button type="submit" class="px-6 py-2.5 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition font-medium">
                    <i class="fas fa-save mr-2"></i> Create Fiscal Year
                </button>
            </div>
        </form>
    </div>
</div>
@endsection
