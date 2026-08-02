@extends('layouts.admin')

@section('title', 'Period Close Log')

@section('content')
<div class="space-y-6">
    {{-- Header --}}
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-slate-800">Period Close Log</h1>
            <p class="text-sm text-slate-500 mt-1">Complete audit trail of all period close/reopen/lock actions</p>
        </div>
        <a href="{{ route('admin.fiscal-years.index') }}" class="px-4 py-2 bg-slate-100 text-slate-700 rounded-lg text-sm hover:bg-slate-200 transition">
            <i class="fas fa-arrow-left mr-1"></i> Back to Fiscal Years
        </a>
    </div>

    {{-- Filters --}}
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
        <form method="GET" action="{{ route('admin.fiscal-years.close-log') }}" class="flex flex-wrap gap-3 items-end">
            <div>
                <label class="block text-xs font-medium text-slate-500 mb-1">Fiscal Year</label>
                <select name="fiscal_year_id" class="border border-slate-300 rounded-lg px-3 py-2 text-sm">
                    <option value="">All</option>
                    @foreach($fiscalYears as $fy)
                        <option value="{{ $fy->id }}" {{ request('fiscal_year_id') == $fy->id ? 'selected' : '' }}>{{ $fy->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-500 mb-1">Action</label>
                <select name="action" class="border border-slate-300 rounded-lg px-3 py-2 text-sm">
                    <option value="">All</option>
                    <option value="close" {{ request('action') === 'close' ? 'selected' : '' }}>Close</option>
                    <option value="reopen" {{ request('action') === 'reopen' ? 'selected' : '' }}>Reopen</option>
                    <option value="lock" {{ request('action') === 'lock' ? 'selected' : '' }}>Lock</option>
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
            <a href="{{ route('admin.fiscal-years.close-log') }}" class="px-4 py-2 text-slate-500 text-sm hover:text-slate-700">Clear</a>
        </form>
    </div>

    {{-- Log Table --}}
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <table class="w-full text-sm">
            <thead>
                <tr class="bg-slate-800 text-white">
                    <th class="text-left px-4 py-3">Date/Time</th>
                    <th class="text-left px-4 py-3">Action</th>
                    <th class="text-left px-4 py-3">Fiscal Year</th>
                    <th class="text-left px-4 py-3">Period</th>
                    <th class="text-left px-4 py-3">Date Range</th>
                    <th class="text-left px-4 py-3">Performed By</th>
                    <th class="text-left px-4 py-3">Branch</th>
                    <th class="text-left px-4 py-3">Reason</th>
                </tr>
            </thead>
            <tbody>
                @forelse($logs as $log)
                <tr class="border-b border-slate-100 hover:bg-slate-50">
                    <td class="px-4 py-3 text-xs text-slate-500">
                        {{ $log->created_at->format('d M Y') }}<br>
                        <span class="text-slate-400">{{ $log->created_at->format('H:i:s') }}</span>
                    </td>
                    <td class="px-4 py-3">
                        <i class="{{ $log->action_icon }} mr-1"></i>
                        <span class="font-medium">{{ $log->action_label }}</span>
                    </td>
                    <td class="px-4 py-3 text-xs">
                        @if($log->fiscalYear)
                            <a href="{{ route('admin.fiscal-years.show', $log->fiscalYear) }}" class="text-indigo-600 hover:underline">{{ $log->fiscalYear->name }}</a>
                        @else
                            —
                        @endif
                    </td>
                    <td class="px-4 py-3 text-xs">{{ $log->fiscalPeriod?->period_name ?? 'Full Year' }}</td>
                    <td class="px-4 py-3 text-xs text-slate-500">
                        {{ $log->period_start_date->format('d M') }} — {{ $log->period_end_date->format('d M Y') }}
                    </td>
                    <td class="px-4 py-3 text-xs">{{ $log->performer?->employee?->name ?? 'System' }}</td>
                    <td class="px-4 py-3 text-xs">{{ $log->branch?->branch_name ?? '—' }}</td>
                    <td class="px-4 py-3 text-xs text-slate-500 max-w-xs truncate">{{ $log->reason ?? '—' }}</td>
                </tr>
                @empty
                <tr>
                    <td colspan="8" class="px-4 py-8 text-center text-slate-400">No close log entries found.</td>
                </tr>
                @endforelse
            </tbody>
        </table>

        {{-- Pagination --}}
        <div class="px-4 py-3 border-t border-slate-100">
            {{ $logs->withQueryString()->links() }}
        </div>
    </div>
</div>
@endsection
