@extends('layouts.admin')

@php
    /** @var \App\Models\Employee $item */
    /** @var \Illuminate\Support\Collection $branches */
    /** @var array $roles */
    /** @var string $routePrefix */
    $roleLabels = collect($roles)->mapWithKeys(fn ($r, $k) => [$k => $r['label']])->toArray();
    $photoUrl = $item->photo
        ? Illuminate\Support\Facades\Storage::disk('public')->url($item->photo)
        : null;
@endphp

@section('content')

<div class="container-fluid py-2">

    {{-- ==================== HERO HEADER ==================== --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#4f46e5,#7c3aed);">
        <div>
            <h1 class="h4 mb-1"><i class="fas fa-pen-to-square me-2"></i>Edit employee</h1>
            <p class="mb-1 small opacity-75">Update <strong>{{ $item->name }}</strong>.</p>
            <span class="badge bg-light text-dark">
                @if ($item->is_active)
                    <i class="fas fa-circle-check text-success me-1"></i> Active
                @else
                    <i class="fas fa-circle-xmark text-secondary me-1"></i> Inactive
                @endif
                · {{ $item->employee_code }}
            </span>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="{{ route($routePrefix . '.show', $item) }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-eye me-1"></i> View
            </a>
            <a href="{{ route($routePrefix . '.index') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back
            </a>
        </div>
    </header>

    <div class="row g-3">
        {{-- ==================== FORM PANEL ==================== --}}
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white border-bottom fw-semibold">
                    <i class="fas fa-user me-2 text-primary"></i>Employee details
                </div>
                <div class="card-body">
                    <form method="POST" action="{{ route($routePrefix . '.update', $item) }}"
                          enctype="multipart/form-data" id="employeeForm">
                        @csrf
                        @method('PUT')

                        {{-- Personal section --}}
                        <h6 class="text-muted text-uppercase small mb-3">
                            <i class="fas fa-user me-1"></i>Personal
                        </h6>
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label" for="employee_code">Employee code</label>
                                <input type="text" id="employee_code" name="employee_code"
                                       class="form-control @error('employee_code') is-invalid @enderror"
                                       value="{{ old('employee_code', $item->employee_code) }}">
                                @error('employee_code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="name">Full name <span class="text-danger">*</span></label>
                                <input type="text" id="name" name="name"
                                       class="form-control @error('name') is-invalid @enderror" required
                                       value="{{ old('name', $item->name) }}">
                                @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="role">Role <span class="text-danger">*</span></label>
                                <select id="role" name="role" class="form-select select2 @error('role') is-invalid @enderror" required>
                                    <option value="">Select role</option>
                                    @foreach ($roles as $roleKey => $roleMeta)
                                        <option value="{{ $roleKey }}"
                                            {{ old('role', $item->role) === $roleKey ? 'selected' : '' }}>
                                            {{ $roleMeta['label'] }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('role')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="branch_id">Branch <span class="text-danger">*</span></label>
                                <select id="branch_id" name="branch_id"
                                        class="form-select select2 @error('branch_id') is-invalid @enderror" required>
                                    <option value="">Select branch</option>
                                    @foreach ($branches as $branch)
                                        <option value="{{ $branch->id }}"
                                            {{ (int) old('branch_id', $item->branch_id) === (int) $branch->id ? 'selected' : '' }}>
                                            {{ $branch->branch_name }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('branch_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>

                        {{-- Contact section --}}
                        <h6 class="text-muted text-uppercase small mb-3 mt-4">
                            <i class="fas fa-phone me-1"></i>Contact
                        </h6>
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label" for="phone">Phone</label>
                                <input type="tel" id="phone" name="phone"
                                       class="form-control @error('phone') is-invalid @enderror"
                                       inputmode="tel"
                                       value="{{ old('phone', $item->phone) }}">
                                @error('phone')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="email">Email</label>
                                <input type="email" id="email" name="email"
                                       class="form-control @error('email') is-invalid @enderror"
                                       inputmode="email"
                                       value="{{ old('email', $item->email) }}">
                                @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="address">Address</label>
                                <textarea id="address" name="address" rows="2"
                                          class="form-control @error('address') is-invalid @enderror">{{ old('address', $item->address) }}</textarea>
                                @error('address')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>

                        {{-- Employment section --}}
                        <h6 class="text-muted text-uppercase small mb-3 mt-4">
                            <i class="fas fa-briefcase me-1"></i>Employment
                        </h6>
                        <div class="row g-3 mb-3">
                            <div class="col-md-4">
                                <label class="form-label" for="salary">Salary (Tk)</label>
                                <div class="input-group">
                                    <span class="input-group-text">৳</span>
                                    <input type="number" step="0.01" min="0" id="salary" name="salary"
                                           class="form-control @error('salary') is-invalid @enderror"
                                           value="{{ old('salary', $item->salary) }}">
                                    @error('salary')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="joining_date">Joining date</label>
                                <input type="date" id="joining_date" name="joining_date"
                                       class="form-control @error('joining_date') is-invalid @enderror"
                                       value="{{ old('joining_date', optional($item->joining_date)->format('Y-m-d')) }}">
                                @error('joining_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-4 d-flex align-items-end">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" role="switch"
                                           name="is_active" value="1" id="is_active"
                                           {{ old('is_active', $item->is_active ? '1' : '0') === '1' ? 'checked' : '' }}>
                                    <label class="form-check-label" for="is_active">Active</label>
                                </div>
                            </div>
                        </div>

                        {{-- Photo upload (edit) --}}
                        <h6 class="text-muted text-uppercase small mb-3 mt-4">
                            <i class="fas fa-camera me-1"></i>Photo
                        </h6>
                        <div class="d-flex align-items-start gap-3 flex-wrap mb-2">
                            <div id="photoPreview"
                                 class="rounded-3 bg-light d-flex align-items-center justify-content-center"
                                 style="width:96px;height:96px;object-fit:cover;overflow:hidden;">
                                @if ($photoUrl)
                                    <img src="{{ $photoUrl }}" alt="Photo"
                                         style="width:100%;height:100%;object-fit:cover;">
                                @else
                                    <i class="fas fa-user fa-2x text-secondary"></i>
                                @endif
                            </div>
                            <div>
                                <input type="file" id="photoInput" name="photo" accept="image/jpeg,image/png,image/gif,image/webp" class="d-none">
                                <button type="button" class="btn btn-outline-primary btn-sm"
                                        onclick="document.getElementById('photoInput').click()">
                                    <i class="fas fa-upload me-1"></i> {{ $photoUrl ? 'Replace photo' : 'Choose photo' }}
                                </button>
                                <button type="button" id="clearPhoto" class="btn btn-outline-secondary btn-sm ms-1" style="display:none;">
                                    <i class="fas fa-times me-1"></i> Clear
                                </button>
                                @if ($photoUrl)
                                <div class="form-check mt-2">
                                    <input class="form-check-input" type="checkbox" name="remove_photo" id="removePhoto" value="1">
                                    <label class="form-check-label small text-danger" for="removePhoto">Remove current photo</label>
                                </div>
                                @endif
                                <div class="small text-muted mt-1">JPG, PNG, GIF, WebP · max 2MB</div>
                                @error('photo')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                            </div>
                        </div>

                        <hr class="my-4">
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary px-4">
                                <i class="fas fa-save me-1"></i> Save changes
                            </button>
                            <a href="{{ route($routePrefix . '.show', $item) }}" class="btn btn-outline-secondary">
                                Cancel
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        {{-- ==================== SNAPSHOT ASIDE ==================== --}}
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm sticky-top" style="top:80px;">
                <div class="card-header bg-white border-bottom fw-semibold">
                    <i class="fas fa-camera me-2 text-primary"></i>Snapshot
                </div>
                <div class="card-body text-center">
                    @if ($photoUrl)
                        <img src="{{ $photoUrl }}" alt="Photo"
                             class="rounded-circle mb-3" style="width:96px;height:96px;object-fit:cover;">
                    @else
                        <div class="rounded-circle bg-primary text-white d-inline-flex align-items-center justify-content-center mb-3"
                             style="width:96px;height:96px;font-size:2.4rem;font-weight:bold;">
                            {{ strtoupper(substr($item->name ?? '?', 0, 1)) }}
                        </div>
                    @endif
                    <h5 class="mb-1">{{ $item->name }}</h5>
                    <div class="text-muted small mb-1">
                        {{ $roleLabels[$item->role] ?? ucfirst(str_replace('_', ' ', $item->role)) }}
                    </div>
                    <div class="text-muted small mb-3">
                        <i class="fas fa-sitemap me-1"></i>{{ $item->branch?->branch_name ?? '—' }}
                    </div>

                    <div class="text-start mt-3">
                        <div class="d-flex justify-content-between border-bottom py-2">
                            <span class="text-muted small"><i class="fas fa-barcode me-1"></i> Code</span>
                            <strong class="small">{{ $item->employee_code }}</strong>
                        </div>
                        <div class="d-flex justify-content-between border-bottom py-2">
                            <span class="text-muted small"><i class="fas fa-phone me-1"></i> Phone</span>
                            <strong class="small">{{ $item->phone ?: '—' }}</strong>
                        </div>
                        <div class="d-flex justify-content-between border-bottom py-2">
                            <span class="text-muted small"><i class="fas fa-envelope me-1"></i> Email</span>
                            <strong class="small text-truncate" style="max-width:160px;">{{ $item->email ?: '—' }}</strong>
                        </div>
                        <div class="d-flex justify-content-between border-bottom py-2">
                            <span class="text-muted small"><i class="fas fa-wallet me-1"></i> Salary</span>
                            <strong class="small">৳ {{ number_format((float) ($item->salary ?? 0), 2) }}</strong>
                        </div>
                        <div class="d-flex justify-content-between py-2">
                            <span class="text-muted small"><i class="fas fa-user-shield me-1"></i> User account</span>
                            @if ($item->user)
                                <span class="badge bg-success-subtle text-success">
                                    {{ $item->user->is_active ? 'Active' : 'Inactive' }}
                                </span>
                            @else
                                <span class="badge bg-secondary-subtle text-secondary">None</span>
                            @endif
                        </div>
                    </div>

                    <div class="d-grid gap-2 mt-3">
                        <a href="{{ route($routePrefix . '.account', $item) }}" class="btn btn-sm btn-outline-primary">
                            <i class="fas fa-id-card-clip me-1"></i> Account hub
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
$(function () {
    // --- Select2 ---
    $('.select2').select2({ theme: 'bootstrap-5', width: '100%' });

    // --- Photo upload preview + remove-photo wiring ---
    var $input    = $('#photoInput');
    var $prev     = $('#photoPreview');
    var $clear    = $('#clearPhoto');
    var $remove   = $('#removePhoto');
    var original  = $prev.html();

    $input.on('change', function () {
        if (!this.files || !this.files[0]) return;
        var reader = new FileReader();
        reader.onload = function (e) {
            $prev.html('<img src="' + e.target.result + '" alt="Preview" style="width:100%;height:100%;object-fit:cover;">');
            $clear.show();
            if ($remove.length) $remove.prop('checked', false);
            $prev.css('opacity', '1');
        };
        reader.readAsDataURL(this.files[0]);
    });

    $clear.on('click', function () {
        $input.val('');
        $prev.html(original);
        $clear.hide();
    });

    if ($remove.length) {
        $remove.on('change', function () {
            if (this.checked) {
                $input.val('');
                $prev.css('opacity', '0.4');
                $clear.hide();
            } else {
                $prev.css('opacity', '1');
            }
        });
    }
});
</script>
@endpush
@endsection
