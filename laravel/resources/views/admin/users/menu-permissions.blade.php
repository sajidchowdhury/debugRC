@extends('layouts.admin')

@section('title', $title)

@section('content')
<div class="container-fluid py-2">
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#7c3aed,#a855f7);">
        <div>
            <h1 class="h4 mb-1"><i class="fas fa-shield-halved me-2"></i>Menu Permissions</h1>
            <p class="mb-0 small opacity-75">
                <strong>{{ $item->username }}</strong>
                @if ($item->employee)
                    &middot; {{ $item->employee->name }}
                    &middot; <span class="badge bg-white bg-opacity-20">{{ $item->employee->role }}</span>
                    &middot; {{ $item->employee->branch?->branch_name ?? '' }}
                @endif
            </p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.users.show', $item) }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to User
            </a>
        </div>
    </header>

    @if(session('success'))
    <div class="alert alert-success alert-dismissible fade show">
        <i class="fas fa-check-circle me-2"></i>{{ session('success') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    @endif

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <p class="text-muted small mb-0">
                <i class="fas fa-info-circle me-1"></i>
                Control which sidebar menus this user can see. <strong>View</strong> = menu appears in sidebar.
                <strong>Edit</strong> = can create/edit records (used by the application for write access).
                Admin and superadmin users bypass these permissions — they see all menus by default.
            </p>
        </div>
    </div>

    <form method="POST" action="{{ route('admin.users.menu-permissions.update', $item) }}">
        @csrf

        {{-- Quick actions --}}
        <div class="d-flex gap-2 mb-3">
            <button type="button" class="btn btn-outline-primary btn-sm" id="selectAll">
                <i class="fas fa-check-double me-1"></i> Select All View
            </button>
            <button type="button" class="btn btn-outline-secondary btn-sm" id="deselectAll">
                <i class="fas fa-times me-1"></i> Deselect All
            </button>
            <button type="submit" class="btn btn-primary btn-sm ms-auto">
                <i class="fas fa-save me-1"></i> Save Permissions
            </button>
        </div>

        {{-- Menu tree --}}
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white">
                <div class="row align-items-center">
                    <div class="col-5"><strong>Menu</strong></div>
                    <div class="col-2 text-center"><strong>View</strong></div>
                    <div class="col-2 text-center"><strong>Edit</strong></div>
                    <div class="col-3"><strong>Controller</strong></div>
                </div>
            </div>
            <div class="card-body p-0">
                @foreach($menuTree as $parent)
                    {{-- Parent menu --}}
                    <div class="border-bottom">
                        <div class="row align-items-center py-2 px-3 bg-light">
                            <div class="col-5">
                                <i class="{{ $parent['icon'] ?? 'fas fa-circle' }} me-2 text-primary"></i>
                                <strong>{{ $parent['menu_label'] }}</strong>
                                @if(!$parent['is_active'])
                                    <span class="badge bg-secondary-subtle text-secondary ms-1">inactive</span>
                                @endif
                            </div>
                            <div class="col-2 text-center">
                                <input type="hidden" name="permissions[{{ $parent['id'] }}][menu_id]" value="{{ $parent['id'] }}">
                                <input type="hidden" name="permissions[{{ $parent['id'] }}][can_view]" value="0">
                                <input class="form-check-input perm-view" type="checkbox"
                                       name="permissions[{{ $parent['id'] }}][can_view]" value="1"
                                       data-menu-id="{{ $parent['id'] }}"
                                       {{ $parent['can_view'] ? 'checked' : '' }}>
                            </div>
                            <div class="col-2 text-center">
                                <input type="hidden" name="permissions[{{ $parent['id'] }}][can_edit]" value="0">
                                <input class="form-check-input perm-edit" type="checkbox"
                                       name="permissions[{{ $parent['id'] }}][can_edit]" value="1"
                                       {{ $parent['can_edit'] ? 'checked' : '' }}>
                            </div>
                            <div class="col-3">
                                <code class="small text-muted">{{ $parent['controller'] ?? '—' }}</code>
                            </div>
                        </div>

                        {{-- Children --}}
                        @foreach($parent['children'] as $child)
                        <div class="row align-items-center py-2 px-3 ms-3 border-top">
                            <div class="col-5">
                                <i class="{{ $child['icon'] ?? 'fas fa-circle' }} me-2 text-muted"></i>
                                {{ $child['menu_label'] }}
                                @if(!$child['is_active'])
                                    <span class="badge bg-secondary-subtle text-secondary ms-1">inactive</span>
                                @endif
                            </div>
                            <div class="col-2 text-center">
                                <input type="hidden" name="permissions[{{ $child['id'] }}][menu_id]" value="{{ $child['id'] }}">
                                <input type="hidden" name="permissions[{{ $child['id'] }}][can_view]" value="0">
                                <input class="form-check-input perm-view" type="checkbox"
                                       name="permissions[{{ $child['id'] }}][can_view]" value="1"
                                       data-parent-id="{{ $parent['id'] }}"
                                       {{ $child['can_view'] ? 'checked' : '' }}>
                            </div>
                            <div class="col-2 text-center">
                                <input type="hidden" name="permissions[{{ $child['id'] }}][can_edit]" value="0">
                                <input class="form-check-input perm-edit" type="checkbox"
                                       name="permissions[{{ $child['id'] }}][can_edit]" value="1"
                                       {{ $child['can_edit'] ? 'checked' : '' }}>
                            </div>
                            <div class="col-3">
                                <code class="small text-muted">{{ $child['controller'] ?? '—' }}{{ $child['action'] ? '::'.$child['action'] : '' }}</code>
                            </div>
                        </div>

                        {{-- Grandchildren --}}
                        @foreach($child['children'] as $grandchild)
                        <div class="row align-items-center py-2 px-3 ms-5 border-top">
                            <div class="col-5">
                                <i class="{{ $grandchild['icon'] ?? 'fas fa-circle' }} me-2 text-muted small"></i>
                                <span class="small">{{ $grandchild['menu_label'] }}</span>
                            </div>
                            <div class="col-2 text-center">
                                <input type="hidden" name="permissions[{{ $grandchild['id'] }}][menu_id]" value="{{ $grandchild['id'] }}">
                                <input type="hidden" name="permissions[{{ $grandchild['id'] }}][can_view]" value="0">
                                <input class="form-check-input perm-view" type="checkbox"
                                       name="permissions[{{ $grandchild['id'] }}][can_view]" value="1"
                                       {{ $grandchild['can_view'] ? 'checked' : '' }}>
                            </div>
                            <div class="col-2 text-center">
                                <input type="hidden" name="permissions[{{ $grandchild['id'] }}][can_edit]" value="0">
                                <input class="form-check-input perm-edit" type="checkbox"
                                       name="permissions[{{ $grandchild['id'] }}][can_edit]" value="1"
                                       {{ $grandchild['can_edit'] ? 'checked' : '' }}>
                            </div>
                            <div class="col-3">
                                <code class="small text-muted">{{ $grandchild['controller'] ?? '—' }}{{ $grandchild['action'] ? '::'.$grandchild['action'] : '' }}</code>
                            </div>
                        </div>
                        @endforeach
                        @endforeach
                    </div>
                @endforeach
            </div>
        </div>

        <div class="d-flex gap-2 mt-3">
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save me-1"></i> Save Permissions
            </button>
            <a href="{{ route('admin.users.show', $item) }}" class="btn btn-outline-secondary">Cancel</a>
        </div>
    </form>
</div>
@endsection

@push('scripts')
<script>
$(function() {
    // Select All View
    $('#selectAll').on('click', function() {
        $('.perm-view').prop('checked', true);
    });
    // Deselect All
    $('#deselectAll').on('click', function() {
        $('.perm-view, .perm-edit').prop('checked', false);
    });
    // When a parent view is checked, also check all children
    // (but don't uncheck children when parent is unchecked — granular control)
    $('.perm-view').on('change', function() {
        if ($(this).data('menu-id')) {
            var parentId = $(this).data('menu-id');
            if ($(this).is(':checked')) {
                // Check all children of this parent
                $('.perm-view[data-parent-id="' + parentId + '"]').prop('checked', true);
            }
        }
    });
});
</script>
@endpush
