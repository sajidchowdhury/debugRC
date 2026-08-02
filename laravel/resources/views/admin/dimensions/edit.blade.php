@extends('layouts.admin')

@section('title', 'Edit Dimension: ' . $dimension->name)

@section('content')
<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-slate-800">Edit Dimension: {{ $dimension->name }}</h1>
        </div>
        <a href="{{ route('admin.dimensions.show', $dimension) }}" class="text-slate-500 hover:text-slate-700 text-sm">
            <i class="fas fa-arrow-left mr-1"></i> Back
        </a>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6 max-w-lg">
        <form method="POST" action="{{ route('admin.dimensions.update', $dimension) }}">
            @csrf @method('PUT')
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Dimension Name</label>
                    <input type="text" name="name" value="{{ $dimension->name }}" required class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Type</label>
                    <select name="type" required class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500">
                        @foreach($typeOptions as $value => $label)
                            <option value="{{ $value }}" {{ $dimension->type == $value ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Description</label>
                    <textarea name="description" rows="3" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500">{{ $dimension->description }}</textarea>
                </div>
                <div class="flex items-center gap-2">
                    <input type="checkbox" name="is_active" id="is_active" value="1" {{ $dimension->is_active ? 'checked' : '' }} class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                    <label for="is_active" class="text-sm text-slate-700">Active</label>
                </div>
            </div>
            <div class="flex items-center justify-end gap-3 mt-6 pt-4 border-t border-slate-200">
                <a href="{{ route('admin.dimensions.show', $dimension) }}" class="px-4 py-2 text-slate-600 hover:text-slate-800 text-sm">Cancel</a>
                <button type="submit" class="px-6 py-2.5 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition font-medium">
                    <i class="fas fa-save mr-2"></i> Update
                </button>
            </div>
        </form>
    </div>
</div>
@endsection
