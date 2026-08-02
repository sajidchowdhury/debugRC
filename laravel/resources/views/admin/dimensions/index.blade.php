@extends('layouts.admin')

@section('title', 'Dimensions & Cost Centers')

@section('content')
<div class="space-y-6">
    {{-- Header --}}
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-slate-800">Dimensions & Cost Centers</h1>
            <p class="text-sm text-slate-500 mt-1">Manage reporting dimensions for segment analysis and cost tracking</p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('admin.dimensions.segment-pnl') }}" class="px-4 py-2 bg-emerald-50 text-emerald-700 rounded-lg text-sm hover:bg-emerald-100 transition border border-emerald-200">
                <i class="fas fa-chart-line mr-1"></i> Segment P&L
            </a>
            <a href="{{ route('admin.dimensions.create') }}" class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm hover:bg-indigo-700 transition">
                <i class="fas fa-plus mr-1"></i> New Dimension
            </a>
        </div>
    </div>

    {{-- Dimension Cards --}}
    @forelse($dimensions as $dimension)
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="px-4 py-3 border-b border-slate-200 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <span class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-indigo-50 text-indigo-600">
                    <i class="fas fa-{{ match($dimension->type) { 'cost_center' => 'building', 'profit_center' => 'chart-line', 'department' => 'users', 'project' => 'project-diagram', 'location' => 'map-marker-alt', default => 'layer-group' } }}"></i>
                </span>
                <div>
                    <a href="{{ route('admin.dimensions.show', $dimension) }}" class="font-semibold text-slate-800 hover:text-indigo-600">{{ $dimension->name }}</a>
                    <div class="text-xs text-slate-500">{{ $dimension->typeLabel() }} &middot; Code: {{ $dimension->code }}</div>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <span class="text-xs text-slate-500">{{ $dimension->values->count() }} values</span>
                @if($dimension->is_active)
                    <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">Active</span>
                @else
                    <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-slate-100 text-slate-600">Inactive</span>
                @endif
            </div>
        </div>
        <div class="px-4 py-3">
            <div class="flex flex-wrap gap-2">
                @foreach($dimension->values as $value)
                    <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg text-xs {{ $value->is_active ? 'bg-slate-100 text-slate-700' : 'bg-slate-50 text-slate-400 line-through' }}">
                        <span class="font-mono text-slate-400">{{ $value->code }}</span>
                        {{ $value->name }}
                    </span>
                @endforeach
                @if($dimension->values->isEmpty())
                    <span class="text-xs text-slate-400">No values yet</span>
                @endif
            </div>
        </div>
    </div>
    @empty
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-12 text-center text-slate-400">
        <i class="fas fa-layer-group text-3xl mb-2"></i>
        <p>No dimensions found. Create your first dimension to enable segment reporting.</p>
    </div>
    @endforelse
</div>
@endsection
