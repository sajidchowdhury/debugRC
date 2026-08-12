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
                    @if(isset($userRole))
                        &middot; <span class="badge {{ $userRole === 'salesman' ? 'bg-success' : ($userRole === 'warehouse_manager' ? 'bg-info' : ($userRole === 'accountant' ? 'bg-warning' : 'bg-light')) }} bg-opacity-25 text-white">
                            <i class="fas fa-user-tag me-1"></i>{{ ucfirst($userRole) }} role
                        </span>
                    @endif
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

    {{-- Role conflict errors --}}
    @if($errors->has('role_conflict'))
    <div class="alert alert-danger alert-dismissible fade show">
        <h5 class="alert-heading"><i class="fas fa-exclamation-triangle me-2"></i>{{ $errors->first('role_conflict') }}</h5>
        <ul class="mb-0">
            @foreach($errors->get('role_details') as $detailArray)
                @foreach($detailArray as $detail)
                <li>{{ $detail }}</li>
                @endforeach
            @endforeach
        </ul>
        <hr>
        <p class="mb-0 small">
            <i class="fas fa-lightbulb me-1"></i>
            <strong>Tip:</strong> Either change the employee's role first, or only grant menus compatible with their current role (<strong>{{ ucfirst($userRole ?? 'user') }}</strong>).
        </p>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    @endif

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <p class="text-muted small mb-1">
                <i class="fas fa-info-circle me-1"></i>
                Control which sidebar menus this user can see. <strong>View</strong> = menu appears in sidebar.
                <strong>Edit</strong> = can create/edit records (used by the application for write access).
                Admin and superadmin users bypass these permissions — they see all menus by default.
            </p>
            @if(isset($userRole) && $userRole !== 'admin' && $userRole !== 'superadmin')
            <p class="text-muted small mb-0">
                <i class="fas fa-shield-alt me-1 text-warning"></i>
                <strong>Role-based protection active:</strong> Menus marked with
                <span class="badge bg-danger-subtle text-danger border border-danger-subtle" style="font-size:0.7em"><i class="fas fa-lock me-1"></i>Not for {{ ucfirst($userRole) }}</span>
                are restricted by route middleware. Even if granted here, the user will get <strong>403 Forbidden</strong> when clicking them.
                The system will <strong>block</strong> saving incompatible permissions.
            </p>
            @endif
        </div>
    </div>

    <form method="POST" action="{{ route('admin.users.menu-permissions.update', $item) }}" id="permissionForm">
        @csrf

        {{-- Quick actions --}}
        <div class="d-flex gap-2 mb-3">
            <button type="button" class="btn btn-outline-primary btn-sm" id="selectAll">
                <i class="fas fa-check-double me-1"></i> Select All View
            </button>
            <button type="button" class="btn btn-outline-secondary btn-sm" id="deselectAll">
                <i class="fas fa-times me-1"></i> Deselect All
            </button>
            <button type="button" class="btn btn-outline-success btn-sm" id="selectCompatible">
                <i class="fas fa-check me-1"></i> Select Role-Compatible Only
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
                                @if(isset($parent['role_compatible']) && !$parent['role_compatible'])
                                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle ms-1 role-badge" style="font-size:0.7em" title="This menu requires: {{ implode(', ', array_map('ucfirst', $parent['allowed_roles'] ?? [])) }}">
                                        <i class="fas fa-lock me-1"></i>Not for {{ ucfirst($userRole ?? 'user') }}
                                    </span>
                                @endif
                            </div>
                            <div class="col-2 text-center">
                                <input type="hidden" name="permissions[{{ $parent['id'] }}][menu_id]" value="{{ $parent['id'] }}">
                                <input type="hidden" name="permissions[{{ $parent['id'] }}][can_view]" value="0">
                                <input class="form-check-input perm-view {{ isset($parent['role_compatible']) && !$parent['role_compatible'] ? 'role-incompatible' : 'role-compatible' }}" type="checkbox"
                                       name="permissions[{{ $parent['id'] }}][can_view]" value="1"
                                       data-menu-id="{{ $parent['id'] }}"
                                       {{ isset($parent['role_compatible']) && !$parent['role_compatible'] ? 'data-incompatible="true"' : '' }}
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
                        <div class="row align-items-center py-2 px-3 ms-3 border-top {{ isset($child['role_compatible']) && !$child['role_compatible'] ? 'bg-danger-subtle bg-opacity-10' : '' }}">
                            <div class="col-5">
                                <i class="{{ $child['icon'] ?? 'fas fa-circle' }} me-2 text-muted"></i>
                                {{ $child['menu_label'] }}
                                @if(!$child['is_active'])
                                    <span class="badge bg-secondary-subtle text-secondary ms-1">inactive</span>
                                @endif
                                @if(isset($child['role_compatible']) && !$child['role_compatible'])
                                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle ms-1 role-badge" style="font-size:0.7em" title="This menu requires: {{ implode(', ', array_map('ucfirst', $child['allowed_roles'] ?? [])) }}">
                                        <i class="fas fa-lock me-1"></i>Not for {{ ucfirst($userRole ?? 'user') }}
                                    </span>
                                @endif
                            </div>
                            <div class="col-2 text-center">
                                <input type="hidden" name="permissions[{{ $child['id'] }}][menu_id]" value="{{ $child['id'] }}">
                                <input type="hidden" name="permissions[{{ $child['id'] }}][can_view]" value="0">
                                <input class="form-check-input perm-view {{ isset($child['role_compatible']) && !$child['role_compatible'] ? 'role-incompatible' : 'role-compatible' }}" type="checkbox"
                                       name="permissions[{{ $child['id'] }}][can_view]" value="1"
                                       data-parent-id="{{ $parent['id'] }}"
                                       {{ isset($child['role_compatible']) && !$child['role_compatible'] ? 'data-incompatible="true"' : '' }}
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
                        <div class="row align-items-center py-2 px-3 ms-5 border-top {{ isset($grandchild['role_compatible']) && !$grandchild['role_compatible'] ? 'bg-danger-subtle bg-opacity-10' : '' }}">
                            <div class="col-5">
                                <i class="{{ $grandchild['icon'] ?? 'fas fa-circle' }} me-2 text-muted small"></i>
                                <span class="small">{{ $grandchild['menu_label'] }}</span>
                                @if(isset($grandchild['role_compatible']) && !$grandchild['role_compatible'])
                                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle ms-1 role-badge" style="font-size:0.65em">
                                        <i class="fas fa-lock me-1"></i>Restricted
                                    </span>
                                @endif
                            </div>
                            <div class="col-2 text-center">
                                <input type="hidden" name="permissions[{{ $grandchild['id'] }}][menu_id]" value="{{ $grandchild['id'] }}">
                                <input type="hidden" name="permissions[{{ $grandchild['id'] }}][can_view]" value="0">
                                <input class="form-check-input perm-view {{ isset($grandchild['role_compatible']) && !$grandchild['role_compatible'] ? 'role-incompatible' : 'role-compatible' }}" type="checkbox"
                                       name="permissions[{{ $grandchild['id'] }}][can_view]" value="1"
                                       {{ isset($grandchild['role_compatible']) && !$grandchild['role_compatible'] ? 'data-incompatible="true"' : '' }}
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

    // Select Role-Compatible Only — skips incompatible menus
    $('#selectCompatible').on('click', function() {
        $('.perm-view, .perm-edit').prop('checked', false); // clear all first
        $('.perm-view.role-compatible').prop('checked', true);
    });

    // When a parent view is checked, also check all compatible children
    $('.perm-view').on('change', function() {
        if ($(this).data('menu-id')) {
            var parentId = $(this).data('menu-id');
            if ($(this).is(':checked')) {
                // Check all compatible children of this parent
                $('.perm-view[data-parent-id="' + parentId + '"].role-compatible').prop('checked', true);
            }
        }
    });

    // Alert when checking an incompatible menu
    $('.role-incompatible').on('change', function() {
        if ($(this).is(':checked')) {
            var badge = $(this).closest('.row').find('.role-badge');
            var roleName = badge.length ? badge.text().trim() : 'this role';
            var confirmed = confirm(
                '⚠️ WARNING: This menu is NOT compatible with the employee\'s role.\n\n' +
                'Even if you grant it here, the user will get 403 Forbidden when they try to access it ' +
                'because the route middleware blocks their role.\n\n' +
                'The system will BLOCK saving this permission.\n\n' +
                'Do you want to proceed anyway?'
            );
            if (!confirmed) {
                $(this).prop('checked', false);
            }
        }
    });

    // Intercept form submit to check for incompatible selections
    $('#permissionForm').on('submit', function(e) {
        var incompatible = $('.role-incompatible.perm-view:checked');
        if (incompatible.length > 0) {
            var menuNames = [];
            incompatible.each(function() {
                var label = $(this).closest('.row').find('strong, span').first().text().trim();
                menuNames.push(label || 'Unknown menu');
            });
            alert(
                '❌ Cannot save: ' + incompatible.length + ' incompatible menu(s) selected:\n\n' +
                menuNames.join('\n') +
                '\n\nThese menus are restricted by role middleware and will be blocked on save.\n' +
                'Please uncheck them and try again.'
            );
            e.preventDefault();
            return false;
        }
    });
});
</script>
@endpush
