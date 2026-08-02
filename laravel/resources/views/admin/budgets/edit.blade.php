@extends('layouts.admin')

@section('title', 'Edit Budget: ' . $budget->name)

@section('content')
<div class="space-y-6">
    {{-- Header --}}
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-slate-800">Edit Budget: {{ $budget->name }}</h1>
            <p class="text-sm text-slate-500 mt-1">Only draft budgets can be edited</p>
        </div>
        <a href="{{ route('admin.budgets.show', $budget) }}" class="text-slate-500 hover:text-slate-700 text-sm">
            <i class="fas fa-arrow-left mr-1"></i> Back to Budget
        </a>
    </div>

    @include('admin.budgets.create', ['fiscalYear' => $budget->fiscal_year, 'branchId' => $budget->branch_id, 'periodType' => $budget->period_type])
</div>
@endsection
