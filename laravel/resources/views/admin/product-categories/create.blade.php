@extends('layouts.admin')

@section('content')
<div class="container-fluid py-2">

    {{-- Hero header --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white" style="background:linear-gradient(135deg,#6366f1,#818cf8);">
        <div>
            <h1 class="h3 mb-1"><i class="fas fa-plus-circle me-2"></i>New category</h1>
            <p class="mb-0 opacity-75">Used in product forms and catalog filters.</p>
        </div>
        <div class="d-flex flex-wrap gap-1">
            <a href="{{ route($routePrefix . '.index') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Categories
            </a>
        </div>
    </header>

    <div class="row justify-content-center">
        <div class="col-12 col-lg-8">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <form method="POST" action="{{ route($routePrefix . '.store') }}">
                        @csrf

                        <h6 class="text-uppercase text-muted border-bottom pb-2 mb-3">
                            <i class="fas fa-tag me-1"></i> Category details
                        </h6>
                        <div class="row g-3 mb-4">
                            <div class="col-12">
                                <label class="form-label" for="category_name">Category name <span class="text-danger">*</span></label>
                                <input type="text" id="category_name" name="category_name" class="form-control @error('category_name') is-invalid @enderror" required
                                       placeholder="e.g. Remote — Universal" value="{{ old('category_name') }}">
                                @error('category_name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                <div class="form-text">Shown on product create/edit and list filters.</div>
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="description">Description</label>
                                <textarea id="description" name="description" class="form-control @error('description') is-invalid @enderror" rows="3"
                                          placeholder="Optional description">{{ old('description') }}</textarea>
                                @error('description') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-12">
                                <div class="form-check form-switch">
                                    <input type="checkbox" class="form-check-input" role="switch" id="is_active" name="is_active" value="1" @checked(old('is_active', true))>
                                    <label class="form-check-label" for="is_active">Active</label>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex gap-2 pt-3 border-top">
                            <button type="submit" class="btn btn-primary px-4">
                                <i class="fas fa-check me-1"></i> Create category
                            </button>
                            <a href="{{ route($routePrefix . '.index') }}" class="btn btn-outline-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
