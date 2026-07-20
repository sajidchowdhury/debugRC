@extends('layouts.admin')

@php
    /** @var \Illuminate\Support\Collection $branches */
    /** @var array $roles */
    /** @var string $routePrefix */
    $roleLabels = collect($roles)->mapWithKeys(fn ($r, $k) => [$k => $r['label']])->toArray();
    // Suggested next employee code (displayed as placeholder; auto-generated on save if blank)
    $lastEmp = \App\Models\Employee::withTrashed()
        ->where('employee_code', 'LIKE', 'EMP-%')
        ->orderByRaw("LENGTH(employee_code) DESC")
        ->orderBy('employee_code', 'desc')
        ->first();
    $nextNum = 1;
    if ($lastEmp && preg_match('/^EMP-(\d+)$/', $lastEmp->employee_code, $m)) {
        $nextNum = ((int) $m[1]) + 1;
    }
    $suggestedCode = 'EMP-' . str_pad((string) $nextNum, 6, '0', STR_PAD_LEFT);
@endphp

@section('content')

<div class="container-fluid py-2">

    {{-- ==================== HERO HEADER ==================== --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#4f46e5,#7c3aed);">
        <div>
            <h1 class="h4 mb-1"><i class="fas fa-user-plus me-2"></i>New employee</h1>
            <p class="mb-0 small opacity-75">Add staff for branches, sales, warehouse, and system user accounts.</p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="{{ route($routePrefix . '.index') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to directory
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
                    <form method="POST" action="{{ route($routePrefix . '.store') }}"
                          enctype="multipart/form-data" id="employeeForm">
                        @csrf

                        {{-- Personal section --}}
                        <h6 class="text-muted text-uppercase small mb-3">
                            <i class="fas fa-user me-1"></i>Personal
                        </h6>
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label" for="employee_code">Employee code</label>
                                <input type="text" id="employee_code" name="employee_code"
                                       class="form-control @error('employee_code') is-invalid @enderror"
                                       placeholder="{{ $suggestedCode }} (auto-assigned if left blank)"
                                       value="{{ old('employee_code') }}">
                                <div class="form-text">Leave blank to auto-generate (format: EMP-NNNNNN).</div>
                                @error('employee_code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="name">Full name <span class="text-danger">*</span></label>
                                <input type="text" id="name" name="name"
                                       class="form-control @error('name') is-invalid @enderror" required
                                       value="{{ old('name') }}">
                                @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="role">Role <span class="text-danger">*</span></label>
                                <select id="role" name="role" class="form-select select2 @error('role') is-invalid @enderror" required>
                                    <option value="">Select role</option>
                                    @foreach ($roles as $roleKey => $roleMeta)
                                        <option value="{{ $roleKey }}"
                                            {{ old('role') === $roleKey ? 'selected' : '' }}>
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
                                            {{ (int) old('branch_id') === (int) $branch->id ? 'selected' : '' }}>
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
                                       value="{{ old('phone') }}">
                                @error('phone')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="email">Email</label>
                                <input type="email" id="email" name="email"
                                       class="form-control @error('email') is-invalid @enderror"
                                       inputmode="email"
                                       value="{{ old('email') }}">
                                @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="address">Address</label>
                                <textarea id="address" name="address" rows="2"
                                          class="form-control @error('address') is-invalid @enderror">{{ old('address') }}</textarea>
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
                                           value="{{ old('salary') }}">
                                    @error('salary')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="joining_date">Joining date</label>
                                <input type="date" id="joining_date" name="joining_date"
                                       class="form-control @error('joining_date') is-invalid @enderror"
                                       value="{{ old('joining_date') }}">
                                @error('joining_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-4 d-flex align-items-end">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" role="switch"
                                           name="is_active" value="1" id="is_active"
                                           {{ old('is_active', '1') === '1' || is_null(old('is_active')) ? 'checked' : '' }}>
                                    <label class="form-check-label" for="is_active">Active</label>
                                </div>
                            </div>
                        </div>

                        {{-- Photo upload --}}
                        <h6 class="text-muted text-uppercase small mb-3 mt-4">
                            <i class="fas fa-camera me-1"></i>Photo (optional)
                        </h6>
                        <div class="d-flex align-items-start gap-3 flex-wrap mb-2">
                            <div id="photoPreview"
                                 class="rounded-3 bg-light d-flex align-items-center justify-content-center"
                                 style="width:96px;height:96px;object-fit:cover;overflow:hidden;">
                                <i class="fas fa-user fa-2x text-secondary"></i>
                            </div>
                            <div>
                                <input type="file" id="photoInput" name="photo" accept="image/jpeg,image/png,image/gif,image/webp" class="d-none">
                                <button type="button" class="btn btn-outline-primary btn-sm"
                                        onclick="document.getElementById('photoInput').click()">
                                    <i class="fas fa-upload me-1"></i> Choose photo
                                </button>
                                <button type="button" id="clearPhoto" class="btn btn-outline-secondary btn-sm ms-1" style="display:none;">
                                    <i class="fas fa-times me-1"></i> Clear
                                </button>
                                <div class="small text-muted mt-1">JPG, PNG, GIF, WebP · max 2MB</div>
                                @error('photo')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                            </div>
                        </div>

                        {{-- Footer actions --}}
                        <hr class="my-4">
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary px-4">
                                <i class="fas fa-check me-1"></i> Create employee
                            </button>
                            <a href="{{ route($routePrefix . '.index') }}" class="btn btn-outline-secondary">
                                Cancel
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        {{-- ==================== LIVE PREVIEW ASIDE ==================== --}}
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm sticky-top" style="top:80px;">
                <div class="card-header bg-white border-bottom fw-semibold">
                    <i class="fas fa-eye me-2 text-primary"></i>Live preview
                </div>
                <div class="card-body text-center">
                    <div id="previewAvatar"
                         class="rounded-circle bg-primary text-white d-inline-flex align-items-center justify-content-center mb-3"
                         style="width:96px;height:96px;font-size:2.4rem;font-weight:bold;">
                        ?
                    </div>
                    <h5 id="previewName" class="mb-1">Full name</h5>
                    <div id="previewRole" class="text-muted small mb-1">Role</div>
                    <div id="previewBranch" class="text-muted small mb-3">
                        <i class="fas fa-sitemap me-1"></i>Branch
                    </div>
                    <div class="d-flex justify-content-center gap-2">
                        <span id="previewCode" class="badge bg-light text-dark border">EMP-NNNNNN</span>
                        <span id="previewStatus" class="badge bg-success">Active</span>
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

    // --- Live preview ---
    var $name   = $('#name');
    var $role   = $('#role');
    var $branch = $('#branch_id');
    var $code   = $('#employee_code');
    var $active = $('#is_active');

    function updPreview() {
        var n = ($name.val() || '').trim();
        $('#previewName').text(n || 'Full name');
        $('#previewAvatar').text(n ? n.charAt(0).toUpperCase() : '?');
        var roleTxt = $role.find('option:selected').text().trim();
        $('#previewRole').text(roleTxt && roleTxt !== 'Select role' ? roleTxt : 'Role');
        var branchTxt = $branch.find('option:selected').text().trim();
        $('#previewBranch').html('<i class="fas fa-sitemap me-1"></i>' + (branchTxt && branchTxt !== 'Select branch' ? branchTxt : 'Branch'));
        $('#previewCode').text($code.val() || 'EMP-NNNNNN');
        $('#previewStatus')
            .toggleClass('bg-success', $active.is(':checked'))
            .toggleClass('bg-secondary', !$active.is(':checked'))
            .text($active.is(':checked') ? 'Active' : 'Inactive');
    }

    $name.add($code).on('input', updPreview);
    $role.add($branch).on('change', updPreview);
    $active.on('change', updPreview);
    updPreview();

    // --- Photo upload preview ---
    var $input  = $('#photoInput');
    var $prev   = $('#photoPreview');
    var $clear  = $('#clearPhoto');
    var orig    = $prev.html();

    $input.on('change', function () {
        if (!this.files || !this.files[0]) return;
        var reader = new FileReader();
        reader.onload = function (e) {
            $prev.html('<img src="' + e.target.result + '" alt="Preview" style="width:100%;height:100%;object-fit:cover;">');
            $clear.show();
            // Also reflect in live-preview avatar
            $('#previewAvatar').css({
                'background-image'    : 'url(' + e.target.result + ')',
                'background-size'     : 'cover',
                'background-position' : 'center',
                'color'               : 'transparent'
            });
        };
        reader.readAsDataURL(this.files[0]);
    });

    $clear.on('click', function () {
        $input.val('');
        $prev.html(orig);
        $clear.hide();
        $('#previewAvatar').css({
            'background-image': 'none',
            'color'           : '#fff'
        });
    });
});
</script>
@endpush
@endsection
