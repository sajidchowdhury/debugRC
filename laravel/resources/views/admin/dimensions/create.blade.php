@extends('layouts.admin')

@section('title', 'Create Dimension')

@section('content')
<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-slate-800">Create Dimension</h1>
            <p class="text-sm text-slate-500 mt-1">Define a new reporting dimension for segment analysis</p>
        </div>
        <a href="{{ route('admin.dimensions.index') }}" class="text-slate-500 hover:text-slate-700 text-sm">
            <i class="fas fa-arrow-left mr-1"></i> Back
        </a>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6 max-w-lg">
        <form method="POST" action="{{ route('admin.dimensions.store') }}">
            @csrf
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Dimension Name <span class="text-red-500">*</span></label>
                    <input type="text" name="name" required class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" placeholder="e.g. Department, Project, Region">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Dimension Code <span class="text-red-500">*</span></label>
                    <input type="text" name="code" required class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" placeholder="e.g. DEPT, PROJ, REG">
                    <p class="text-xs text-slate-500 mt-1">Short unique code for this dimension (uppercase recommended)</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Type <span class="text-red-500">*</span></label>
                    <select name="type" required class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                        @foreach($typeOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Description</label>
                    <textarea name="description" rows="3" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" placeholder="Optional description of this dimension"></textarea>
                </div>
            </div>
            <div class="flex items-center justify-end gap-3 mt-6 pt-4 border-t border-slate-200">
                <a href="{{ route('admin.dimensions.index') }}" class="px-4 py-2 text-slate-600 hover:text-slate-800 text-sm">Cancel</a>
                <button type="submit" class="px-6 py-2.5 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition font-medium">
                    <i class="fas fa-save mr-2"></i> Create Dimension
                </button>
            </div>
        </form>
    </div>
</div>
@endsection
