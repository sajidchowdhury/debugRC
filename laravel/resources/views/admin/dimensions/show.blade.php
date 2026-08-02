@extends('layouts.admin')

@section('title', $dimension->name)

@section('content')
<div class="space-y-6">
    {{-- Header --}}
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-slate-800">{{ $dimension->name }}</h1>
            <p class="text-sm text-slate-500 mt-1">{{ $dimension->typeLabel() }} &middot; Code: {{ $dimension->code }}</p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('admin.dimensions.edit', $dimension) }}" class="px-4 py-2 bg-indigo-50 text-indigo-700 rounded-lg text-sm hover:bg-indigo-100 transition border border-indigo-200">
                <i class="fas fa-pen mr-1"></i> Edit
            </a>
            <a href="{{ route('admin.dimensions.index') }}" class="px-4 py-2 text-slate-500 hover:text-slate-700 text-sm">
                <i class="fas fa-arrow-left mr-1"></i> Back
            </a>
        </div>
    </div>

    {{-- Dimension Values --}}
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="px-4 py-3 border-b border-slate-200 flex items-center justify-between">
            <h2 class="font-semibold text-slate-800">Dimension Values</h2>
            <button type="button" onclick="document.getElementById('addValueForm').classList.toggle('hidden')" class="px-3 py-1.5 bg-indigo-50 text-indigo-700 rounded-lg text-sm hover:bg-indigo-100 transition">
                <i class="fas fa-plus mr-1"></i> Add Value
            </button>
        </div>

        {{-- Add Value Form --}}
        <div id="addValueForm" class="hidden border-b border-slate-200 bg-slate-50 p-4">
            <form method="POST" action="{{ route('admin.dimensions.store-value', $dimension) }}">
                @csrf
                <div class="flex flex-wrap gap-3 items-end">
                    <div>
                        <label class="block text-xs font-medium text-slate-600 mb-1">Code</label>
                        <input type="text" name="code" required class="border border-slate-300 rounded-lg px-3 py-2 text-sm w-28 focus:ring-2 focus:ring-indigo-500">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-600 mb-1">Name</label>
                        <input type="text" name="name" required class="border border-slate-300 rounded-lg px-3 py-2 text-sm w-48 focus:ring-2 focus:ring-indigo-500">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-600 mb-1">Branch</label>
                        <select name="branch_id" class="border border-slate-300 rounded-lg px-3 py-2 text-sm w-40 focus:ring-2 focus:ring-indigo-500">
                            <option value="">All Branches</option>
                            @foreach(\App\Models\Branch::active()->orderBy('branch_name')->get() as $branch)
                                <option value="{{ $branch->id }}">{{ $branch->branch_name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm hover:bg-indigo-700 transition">
                        <i class="fas fa-check mr-1"></i> Add
                    </button>
                </div>
            </form>
        </div>

        {{-- Values Table --}}
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-slate-50 border-b border-slate-200">
                        <th class="text-left px-4 py-2 font-semibold text-slate-600">Code</th>
                        <th class="text-left px-4 py-2 font-semibold text-slate-600">Name</th>
                        <th class="text-left px-4 py-2 font-semibold text-slate-600">Branch</th>
                        <th class="text-center px-4 py-2 font-semibold text-slate-600">Status</th>
                        <th class="text-center px-4 py-2 font-semibold text-slate-600">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($dimension->values as $value)
                    <tr class="border-b border-slate-100 hover:bg-slate-50">
                        <td class="px-4 py-2 font-mono text-slate-600">{{ $value->code }}</td>
                        <td class="px-4 py-2 text-slate-800">{{ $value->name }}</td>
                        <td class="px-4 py-2 text-slate-600">{{ $value->branch?->branch_name ?? 'All Branches' }}</td>
                        <td class="px-4 py-2 text-center">
                            @if($value->is_active)
                                <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">Active</span>
                            @else
                                <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-slate-100 text-slate-600">Inactive</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-center">
                            <form method="POST" action="{{ route('admin.dimensions.toggle-value', [$dimension, $value]) }}" class="inline">
                                @csrf @method('PATCH')
                                <button type="submit" class="p-1.5 text-slate-400 hover:text-{{ $value->is_active ? 'red' : 'green' }}-600 transition" title="{{ $value->is_active ? 'Deactivate' : 'Activate' }}">
                                    <i class="fas fa-{{ $value->is_active ? 'ban' : 'check' }}"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="5" class="px-4 py-8 text-center text-slate-400">No values yet. Add dimension values to enable segment reporting.</td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Usage Summary --}}
    @if($usageSummary && collect($usageSummary['summary'])->sum('line_count') > 0)
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="px-4 py-3 border-b border-slate-200">
            <h2 class="font-semibold text-slate-800">Usage Summary ({{ $fromDate }} to {{ $toDate }})</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-slate-50 border-b border-slate-200">
                        <th class="text-left px-4 py-2 font-semibold text-slate-600">Value</th>
                        <th class="text-center px-4 py-2 font-semibold text-slate-600">Journal Lines</th>
                        <th class="text-right px-4 py-2 font-semibold text-slate-600">Total Debit</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($usageSummary['summary'] as $item)
                    <tr class="border-b border-slate-100 hover:bg-slate-50">
                        <td class="px-4 py-2">
                            <span class="font-mono text-slate-400 mr-1">{{ $item['code'] }}</span>
                            {{ $item['name'] }}
                        </td>
                        <td class="px-4 py-2 text-center">{{ number_format($item['line_count']) }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ number_format($item['total_debit'], 2) }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif
</div>
@endsection
