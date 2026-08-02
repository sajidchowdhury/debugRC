@extends('layouts.admin')

@section('title', 'Fiscal Years')

@section('content')
<div class="space-y-6">
    {{-- Header --}}
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-slate-800">Fiscal Years</h1>
            <p class="text-sm text-slate-500 mt-1">Manage fiscal years, periods, and closing controls</p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('admin.fiscal-years.close-log') }}" class="px-4 py-2 bg-slate-100 text-slate-700 rounded-lg text-sm hover:bg-slate-200 transition">
                <i class="fas fa-history mr-1"></i> Close Log
            </a>
            <a href="{{ route('admin.fiscal-years.create') }}" class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm hover:bg-indigo-700 transition">
                <i class="fas fa-plus mr-1"></i> New Fiscal Year
            </a>
        </div>
    </div>

    {{-- Filters --}}
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
        <form method="GET" action="{{ route('admin.fiscal-years.index') }}" class="flex flex-wrap gap-3 items-end">
            <div>
                <label class="block text-xs font-medium text-slate-500 mb-1">Status</label>
                <select name="status" class="border border-slate-300 rounded-lg px-3 py-2 text-sm">
                    <option value="">All</option>
                    <option value="draft" {{ request('status') === 'draft' ? 'selected' : '' }}>Draft</option>
                    <option value="active" {{ request('status') === 'active' ? 'selected' : '' }}>Active</option>
                    <option value="closed" {{ request('status') === 'closed' ? 'selected' : '' }}>Closed</option>
                    <option value="locked" {{ request('status') === 'locked' ? 'selected' : '' }}>Locked</option>
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-500 mb-1">Branch</label>
                <select name="branch_id" class="border border-slate-300 rounded-lg px-3 py-2 text-sm">
                    <option value="">All Branches</option>
                    @foreach($branches as $branch)
                        <option value="{{ $branch->id }}" {{ request('branch_id') == $branch->id ? 'selected' : '' }}>{{ $branch->branch_name }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="px-4 py-2 bg-slate-800 text-white rounded-lg text-sm">Filter</button>
            <a href="{{ route('admin.fiscal-years.index') }}" class="px-4 py-2 text-slate-500 text-sm hover:text-slate-700">Clear</a>
        </form>
    </div>

    {{-- Flash Messages --}}
    @if (session('success'))
        <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg text-sm">
            <i class="fas fa-check-circle mr-1"></i> {{ session('success') }}
        </div>
    @endif
    @if (session('error'))
        <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg text-sm">
            <i class="fas fa-exclamation-circle mr-1"></i> {{ session('error') }}
        </div>
    @endif

    {{-- Fiscal Years Table --}}
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <table class="w-full text-sm">
            <thead>
                <tr class="bg-slate-800 text-white">
                    <th class="text-left px-4 py-3">Fiscal Year</th>
                    <th class="text-left px-4 py-3">Period</th>
                    <th class="text-left px-4 py-3">Branch</th>
                    <th class="text-center px-4 py-3">Status</th>
                    <th class="text-center px-4 py-3">Progress</th>
                    <th class="text-center px-4 py-3">Current</th>
                    <th class="text-right px-4 py-3">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($fiscalYears as $fy)
                <tr class="border-b border-slate-100 hover:bg-slate-50">
                    <td class="px-4 py-3">
                        <a href="{{ route('admin.fiscal-years.show', $fy) }}" class="font-semibold text-indigo-600 hover:text-indigo-800">
                            {{ $fy->name }}
                        </a>
                        <div class="text-xs text-slate-400">{{ $fy->fiscal_year_code }}</div>
                    </td>
                    <td class="px-4 py-3">
                        <div class="text-xs">{{ $fy->start_date->format('d M Y') }}</div>
                        <div class="text-xs text-slate-400">→ {{ $fy->end_date->format('d M Y') }}</div>
                    </td>
                    <td class="px-4 py-3 text-xs">{{ $fy->branch?->branch_name ?? 'All Branches' }}</td>
                    <td class="px-4 py-3 text-center">
                        @php
                            $statusColors = ['draft' => 'bg-slate-100 text-slate-700', 'active' => 'bg-green-100 text-green-700', 'closed' => 'bg-amber-100 text-amber-700', 'locked' => 'bg-red-100 text-red-700'];
                        @endphp
                        <span class="px-2 py-1 rounded-full text-xs font-medium {{ $statusColors[$fy->status] ?? 'bg-slate-100' }}">{{ ucfirst($fy->status) }}</span>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <div class="flex items-center gap-2">
                            <div class="flex-1 bg-slate-200 rounded-full h-2">
                                <div class="bg-indigo-500 h-2 rounded-full" style="width: {{ $fy->progress_percent }}%"></div>
                            </div>
                            <span class="text-xs text-slate-500">{{ $fy->progress_percent }}%</span>
                        </div>
                        <div class="text-xs text-slate-400 mt-1">{{ $fy->closed_periods_count }}/{{ $fy->periods->count() }} closed</div>
                    </td>
                    <td class="px-4 py-3 text-center">
                        @if($fy->is_current)
                            <span class="text-green-600"><i class="fas fa-check-circle"></i></span>
                        @else
                            <span class="text-slate-300"><i class="far fa-circle"></i></span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-right">
                        <a href="{{ route('admin.fiscal-years.show', $fy) }}" class="p-2 text-slate-400 hover:text-indigo-600 transition" title="View">
                            <i class="fas fa-eye"></i>
                        </a>
                        @if($fy->status === 'draft')
                            <form method="POST" action="{{ route('admin.fiscal-years.activate', $fy) }}" class="inline">
                                @csrf
                                <button type="submit" class="p-2 text-green-400 hover:text-green-600 transition" title="Activate" onclick="return confirm('Activate this fiscal year?')">
                                    <i class="fas fa-play-circle"></i>
                                </button>
                            </form>
                        @endif
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="7" class="px-4 py-8 text-center text-slate-400">
                        No fiscal years found. <a href="{{ route('admin.fiscal-years.create') }}" class="text-indigo-600 hover:underline">Create one</a>
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>

        {{-- Pagination --}}
        <div class="px-4 py-3 border-t border-slate-100">
            {{ $fiscalYears->withQueryString()->links() }}
        </div>
    </div>
</div>
@endsection
