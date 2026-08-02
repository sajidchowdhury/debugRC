@extends('layouts.admin')

@section('title', $fiscalYear->name)

@section('content')
<div class="space-y-6">
    {{-- Header --}}
    <div class="flex items-center justify-between">
        <div>
            <div class="flex items-center gap-2">
                <a href="{{ route('admin.fiscal-years.index') }}" class="text-slate-500 hover:text-slate-700 text-sm">
                    <i class="fas fa-arrow-left mr-1"></i> Fiscal Years
                </a>
            </div>
            <h1 class="text-2xl font-bold text-slate-800 mt-1">{{ $fiscalYear->name }}</h1>
            <p class="text-sm text-slate-500">{{ $fiscalYear->fiscal_year_code }} &middot; {{ $fiscalYear->start_date->format('d M Y') }} → {{ $fiscalYear->end_date->format('d M Y') }}</p>
        </div>
        <div class="flex gap-2">
            @if($fiscalYear->status === 'active')
                <form method="POST" action="{{ route('admin.fiscal-years.close', $fiscalYear) }}" class="inline">
                    @csrf
                    <button type="submit" class="px-4 py-2 bg-amber-500 text-white rounded-lg text-sm hover:bg-amber-600 transition"
                            onclick="return confirm('Execute year-end close? This will zero all Income/Expense ledgers and transfer net P&L to Retained Earnings.')">
                        <i class="fas fa-calendar-xmark mr-1"></i> Year-End Close
                    </button>
                </form>
            @endif
            @if(in_array($fiscalYear->status, ['active', 'closed']))
                <button type="button" class="px-4 py-2 bg-red-50 text-red-700 rounded-lg text-sm hover:bg-red-100 transition border border-red-200"
                        onclick="document.getElementById('lockForm').classList.toggle('hidden')">
                    <i class="fas fa-shield-alt mr-1"></i> Lock
                </button>
            @endif
        </div>
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

    {{-- Lock Form (hidden by default) --}}
    @if(in_array($fiscalYear->status, ['active', 'closed']))
    <div id="lockForm" class="hidden bg-red-50 border border-red-200 rounded-xl p-4">
        <h4 class="text-red-700 font-semibold mb-2"><i class="fas fa-shield-alt mr-1"></i> Lock Fiscal Year</h4>
        <p class="text-sm text-red-600 mb-3">Locking prevents any changes to this fiscal year. Only superadmin can unlock.</p>
        <form method="POST" action="{{ route('admin.fiscal-years.lock', $fiscalYear) }}">
            @csrf
            <div class="flex gap-2">
                <input type="text" name="reason" required minlength="10" placeholder="Reason for locking (min 10 chars)"
                       class="flex-1 border border-red-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-red-500">
                <button type="submit" class="px-4 py-2 bg-red-600 text-white rounded-lg text-sm hover:bg-red-700"
                        onclick="return confirm('Lock this fiscal year? This cannot be undone by non-superadmin users.')">
                    <i class="fas fa-lock mr-1"></i> Lock
                </button>
            </div>
        </form>
    </div>
    @endif

    {{-- Fiscal Year Info Cards --}}
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
            <div class="text-xs text-slate-500 mb-1">Status</div>
            @php
                $statusColors = ['draft' => 'bg-slate-100 text-slate-700', 'active' => 'bg-green-100 text-green-700', 'closed' => 'bg-amber-100 text-amber-700', 'locked' => 'bg-red-100 text-red-700'];
            @endphp
            <span class="px-2 py-1 rounded-full text-xs font-medium {{ $statusColors[$fiscalYear->status] ?? '' }}">{{ ucfirst($fiscalYear->status) }}</span>
            @if($fiscalYear->is_current)<span class="ml-2 text-green-600 text-xs"><i class="fas fa-check-circle"></i> Current</span>@endif
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
            <div class="text-xs text-slate-500 mb-1">Branch</div>
            <div class="font-semibold text-slate-800">{{ $fiscalYear->branch?->branch_name ?? 'All Branches' }}</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
            <div class="text-xs text-slate-500 mb-1">Periods Closed</div>
            <div class="font-semibold text-slate-800">{{ $fiscalYear->closed_periods_count }} / {{ $fiscalYear->periods->count() }}</div>
            <div class="w-full bg-slate-200 rounded-full h-2 mt-2">
                <div class="bg-indigo-500 h-2 rounded-full" style="width: {{ $fiscalYear->progress_percent }}%"></div>
            </div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
            <div class="text-xs text-slate-500 mb-1">Period Type</div>
            <div class="font-semibold text-slate-800">{{ ucfirst($fiscalYear->period_type) }}</div>
        </div>
    </div>

    {{-- Period Grid --}}
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="px-4 py-3 border-b border-slate-200 flex items-center justify-between">
            <h3 class="font-semibold text-slate-800"><i class="fas fa-calendar-alt mr-2 text-indigo-500"></i>Periods</h3>
            @if($fiscalYear->status === 'active')
                <span class="text-xs text-slate-400">Click actions to close/reopen individual periods</span>
            @endif
        </div>
        <table class="w-full text-sm">
            <thead>
                <tr class="bg-slate-50">
                    <th class="text-left px-4 py-2 text-xs font-medium text-slate-500">#</th>
                    <th class="text-left px-4 py-2 text-xs font-medium text-slate-500">Period</th>
                    <th class="text-left px-4 py-2 text-xs font-medium text-slate-500">Start Date</th>
                    <th class="text-left px-4 py-2 text-xs font-medium text-slate-500">End Date</th>
                    <th class="text-center px-4 py-2 text-xs font-medium text-slate-500">Status</th>
                    <th class="text-left px-4 py-2 text-xs font-medium text-slate-500">Closed By</th>
                    <th class="text-right px-4 py-2 text-xs font-medium text-slate-500">Actions</th>
                </tr>
            </thead>
            <tbody>
                @foreach($fiscalYear->periods as $period)
                <tr class="border-b border-slate-100 hover:bg-slate-50">
                    <td class="px-4 py-2 text-slate-400">{{ $period->period_number }}</td>
                    <td class="px-4 py-2 font-medium text-slate-800">{{ $period->period_name }}</td>
                    <td class="px-4 py-2 text-slate-600">{{ $period->start_date->format('d M Y') }}</td>
                    <td class="px-4 py-2 text-slate-600">{{ $period->end_date->format('d M Y') }}</td>
                    <td class="px-4 py-2 text-center">
                        @if($period->isOpen())
                            <span class="px-2 py-1 rounded-full text-xs font-medium bg-green-100 text-green-700">Open</span>
                        @elseif($period->isClosed())
                            <span class="px-2 py-1 rounded-full text-xs font-medium bg-amber-100 text-amber-700">Closed</span>
                        @else
                            <span class="px-2 py-1 rounded-full text-xs font-medium bg-red-100 text-red-700">Locked</span>
                        @endif
                    </td>
                    <td class="px-4 py-2 text-xs text-slate-400">
                        @if($period->closed_at)
                            {{ $period->closer?->employee?->name ?? 'System' }}
                            <br>{{ $period->closed_at->format('d M Y H:i') }}
                        @else
                            —
                        @endif
                    </td>
                    <td class="px-4 py-2 text-right">
                        @if($fiscalYear->isActive())
                            @if($period->isOpen())
                                <form method="POST" action="{{ route('admin.fiscal-years.periods.close', $period) }}" class="inline">
                                    @csrf
                                    <input type="text" name="notes" placeholder="Notes (optional)" class="border border-slate-200 rounded px-2 py-1 text-xs w-32 mr-1">
                                    <button type="submit" class="px-2 py-1 bg-amber-50 text-amber-700 rounded text-xs hover:bg-amber-100 border border-amber-200"
                                            onclick="return confirm('Close period {{ $period->period_name }}?')">
                                        <i class="fas fa-lock mr-1"></i>Close
                                    </button>
                                </form>
                            @elseif($period->isClosed())
                                <form method="POST" action="{{ route('admin.fiscal-years.periods.reopen', $period) }}" class="inline">
                                    @csrf
                                    <input type="text" name="reason" required minlength="10" placeholder="Reason (min 10 chars)" class="border border-slate-200 rounded px-2 py-1 text-xs w-32 mr-1">
                                    <button type="submit" class="px-2 py-1 bg-green-50 text-green-700 rounded text-xs hover:bg-green-100 border border-green-200"
                                            onclick="return confirm('Reopen period {{ $period->period_name }}?')">
                                        <i class="fas fa-unlock mr-1"></i>Reopen
                                    </button>
                                </form>
                            @endif
                        @else
                            <span class="text-xs text-slate-400">—</span>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    {{-- Close Log (Recent) --}}
    @if($closeLogs->count() > 0)
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="px-4 py-3 border-b border-slate-200">
            <h3 class="font-semibold text-slate-800"><i class="fas fa-history mr-2 text-indigo-500"></i>Recent Activity</h3>
        </div>
        <table class="w-full text-sm">
            <thead>
                <tr class="bg-slate-50">
                    <th class="text-left px-4 py-2 text-xs font-medium text-slate-500">Date/Time</th>
                    <th class="text-left px-4 py-2 text-xs font-medium text-slate-500">Action</th>
                    <th class="text-left px-4 py-2 text-xs font-medium text-slate-500">Period</th>
                    <th class="text-left px-4 py-2 text-xs font-medium text-slate-500">By</th>
                    <th class="text-left px-4 py-2 text-xs font-medium text-slate-500">Reason</th>
                </tr>
            </thead>
            <tbody>
                @foreach($closeLogs as $log)
                <tr class="border-b border-slate-100">
                    <td class="px-4 py-2 text-xs text-slate-500">{{ $log->created_at->format('d M Y H:i') }}</td>
                    <td class="px-4 py-2">
                        <i class="{{ $log->action_icon }} mr-1"></i>
                        <span class="text-xs font-medium">{{ $log->action_label }}</span>
                    </td>
                    <td class="px-4 py-2 text-xs">{{ $log->fiscalPeriod?->period_name ?? 'Full Year' }}</td>
                    <td class="px-4 py-2 text-xs">{{ $log->performer?->employee?->name ?? 'System' }}</td>
                    <td class="px-4 py-2 text-xs text-slate-500">{{ $log->reason ?? '—' }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif
</div>
@endsection
