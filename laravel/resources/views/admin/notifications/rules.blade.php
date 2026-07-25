@extends('layouts.admin')

@section('content')
@php
    // Event → Bootstrap color mapping (per task spec)
    // F-18b: added the 4 new events (user_logout, damage_invoice_created,
    // branch_demand_created, customer_limit_increased) + return sub-flows.
    $eventColors = [
        'sales_finalize'           => 'success',
        'challan_create'           => 'info',
        'godown_create'            => 'primary',
        'payment_receive'          => 'success',
        'soft_delete'              => 'warning',
        'accounts_entry'           => 'primary',
        'user_login'               => 'secondary',
        'user_logout'              => 'secondary',
        'damage_invoice_created'   => 'danger',
        'branch_demand_created'    => 'info',
        'customer_limit_increased' => 'success',
        'return_created'           => 'info',
        'return_confirmed'         => 'primary',
        'return_reversed'          => 'danger',
    ];
    // Channel → Bootstrap color mapping (F-18b: database-only)
    $channelColors = [
        'database'  => 'secondary',
        'broadcast' => 'info',
        'both'      => 'primary',
    ];

    $contextAware = $contextAware ?? [];
    $filters = $filters ?? [];
    $stats   = $stats   ?? [
        'total_rules'         => 0,
        'active_rules'        => 0,
        'total_sent'          => 0,
        'total_notifications' => 0,
        'unread_notifications'=> 0,
        'rules_by_event'      => [],
    ];
@endphp

<div class="container-fluid py-2">

    {{-- =================== HERO HEADER =================== --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#6366f1,#8b5cf6);">
        <div>
            <h1 class="h4 mb-1"><i class="fas fa-bell-concierge me-2"></i>{{ $title }}</h1>
            <p class="mb-0 small opacity-75">
                Define <em>who</em> gets notified <em>when</em> key events happen across RC&nbsp;ERP — sales, challans, payments, logins, returns and more. Multi-select recipients per event.
            </p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="{{ route('admin.notifications.inbox') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-inbox me-1"></i> Inbox
            </a>
            <button type="button" class="btn btn-light btn-sm" data-bs-toggle="collapse" data-bs-target="#createRuleCard" aria-expanded="true">
                <i class="fas fa-plus me-1"></i> Create Rule
            </button>
        </div>
    </header>

    {{-- =================== STAT CARDS (5) =================== --}}
    <div class="row g-3 mb-3">
        <div class="col-sm-6 col-lg">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#6366f1;">
                        <i class="fas fa-list-check"></i>
                    </div>
                    <div>
                        <div class="h4 mb-0">{{ number_format((int) ($stats['total_rules'] ?? 0)) }}</div>
                        <div class="text-muted small">Total Rules</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#10b981;">
                        <i class="fas fa-circle-check"></i>
                    </div>
                    <div>
                        <div class="h4 mb-0">{{ number_format((int) ($stats['active_rules'] ?? 0)) }}</div>
                        <div class="text-muted small">Active Rules</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#8b5cf6;">
                        <i class="fas fa-paper-plane"></i>
                    </div>
                    <div>
                        <div class="h4 mb-0">{{ number_format((int) ($stats['total_sent'] ?? 0)) }}</div>
                        <div class="text-muted small">Total Sent</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#0ea5e9;">
                        <i class="fas fa-bell"></i>
                    </div>
                    <div>
                        <div class="h4 mb-0">{{ number_format((int) ($stats['total_notifications'] ?? 0)) }}</div>
                        <div class="text-muted small">Total Notifications</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#ef4444;">
                        <i class="fas fa-bell-on"></i>
                    </div>
                    <div>
                        <div class="h4 mb-0">{{ number_format((int) ($stats['unread_notifications'] ?? 0)) }}</div>
                        <div class="text-muted small">Unread Notifications</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- =================== CREATE RULE FORM =================== --}}
    <div class="collapse show mb-3" id="createRuleCard">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white d-flex align-items-center py-3">
                <i class="fas fa-circle-plus me-2 text-indigo"></i>
                <strong>Create Notification Rule</strong>
                <button type="button" class="btn btn-sm btn-link ms-auto" data-bs-toggle="collapse" data-bs-target="#createRuleCard">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('admin.notifications.storeRule') }}">
                    @csrf
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Rule Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control" maxlength="100"
                                   placeholder="e.g. Notify Admin + Warehouse on every sale" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Event <span class="text-danger">*</span></label>
                            <select name="event" class="form-select" required>
                                <option value="">— Send notification on… —</option>
                                @foreach ($events as $key => $label)
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>

                        {{-- F-18b: multi-select recipient types --}}
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Recipient Types <span class="text-danger">*</span></label>
                            <select name="recipient_types[]" id="recipientTypes" class="form-select" size="8" multiple required>
                                @foreach ($recipients as $key => $label)
                                    @php
                                        $isContextAware = in_array($key, $contextAware, true);
                                    @endphp
                                    <option value="{{ $key }}">{{ $label }}{{ $isContextAware ? ' ★' : '' }}</option>
                                @endforeach
                            </select>
                            <small class="text-muted">
                                Hold Ctrl/Cmd to pick multiple. ★ = resolved from event context (branch / invoice).
                            </small>
                        </div>
                        <div class="col-md-4" id="specificUserWrap" style="display:none;">
                            <label class="form-label fw-semibold">Specific User <span class="text-danger">*</span></label>
                            <select name="recipient_user_id" id="recipientUser" class="form-select">
                                <option value="">— Select user —</option>
                                @foreach ($users as $u)
                                    <option value="{{ $u->id }}">{{ $u->username }} ({{ $u->employee?->name ?? '—' }})</option>
                                @endforeach
                            </select>
                            <small class="text-muted">Only required when "Specific User" is selected.</small>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Delivery Channel</label>
                            <div class="form-control bg-light text-muted d-flex align-items-center" style="min-height:38px;">
                                <i class="fas fa-database me-2 text-secondary"></i>
                                <span>Database (In-App) + real-time toast</span>
                            </div>
                            <input type="hidden" name="channel" value="database">
                            <small class="text-muted">Real-time push via SSE — no database polling.</small>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-semibold">Description</label>
                            <textarea name="description" class="form-control" rows="2" maxlength="500"
                                      placeholder="Optional notes about why this rule exists."></textarea>
                        </div>

                        <div class="col-md-6 d-flex align-items-center">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="is_active" value="1" id="isActive" checked>
                                <label class="form-check-label fw-semibold" for="isActive">Active immediately</label>
                            </div>
                        </div>
                        <div class="col-md-6 d-flex justify-content-md-end align-items-center">
                            <button type="submit" class="btn text-white" style="background:linear-gradient(135deg,#6366f1,#8b5cf6);">
                                <i class="fas fa-floppy-disk me-1"></i> Create Rule
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    {{-- =================== FILTER FORM =================== --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body py-3">
            <form method="GET" action="{{ route('admin.notifications.rules') }}" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small mb-1 text-muted">Event</label>
                    <select name="event" class="form-select form-select-sm">
                        <option value="">All events</option>
                        @foreach ($events as $key => $label)
                            <option value="{{ $key }}" @selected(($filters['event'] ?? '') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small mb-1 text-muted">Recipient Type</label>
                    <select name="recipient_type" class="form-select form-select-sm">
                        <option value="">All recipients</option>
                        @foreach ($recipients as $key => $label)
                            <option value="{{ $key }}" @selected(($filters['recipient_type'] ?? '') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3 d-flex align-items-center pt-3">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="active_only" value="1" id="activeOnly"
                               @checked(($filters['active_only'] ?? false))>
                        <label class="form-check-label small" for="activeOnly">Active rules only</label>
                    </div>
                </div>
                <div class="col-md-3 d-flex gap-2 justify-content-md-end">
                    <a href="{{ route('admin.notifications.rules') }}" class="btn btn-sm btn-outline-secondary">
                        <i class="fas fa-rotate-left"></i> Reset
                    </a>
                    <button type="submit" class="btn btn-sm text-white" style="background:linear-gradient(135deg,#6366f1,#8b5cf6);">
                        <i class="fas fa-filter me-1"></i> Filter
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- =================== RULES TABLE =================== --}}
    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="rulesTable">
                    <thead class="table-light">
                        <tr>
                            <th>Name</th>
                            <th>Event</th>
                            <th>Recipients</th>
                            <th class="text-center">Active</th>
                            <th class="text-center">Times Fired</th>
                            <th>Created By</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rules as $rule)
                            @php
                                $evtColor = $eventColors[$rule->event] ?? 'secondary';
                            @endphp
                            <tr>
                                <td>
                                    <div class="fw-semibold">{{ $rule->name }}</div>
                                    @if ($rule->description)
                                        <div class="small text-muted">{{ \Illuminate\Support\Str::limit($rule->description, 80) }}</div>
                                    @endif
                                </td>
                                <td>
                                    <span class="badge bg-{{ $evtColor }}-subtle text-{{ $evtColor }}">
                                        <i class="fas fa-bolt me-1"></i>{{ $rule->event_label }}
                                    </span>
                                </td>
                                {{-- F-18b: render every recipient-type selection as its own badge --}}
                                <td>
                                    <div class="d-flex flex-wrap gap-1">
                                        @forelse ($rule->recipientTypes as $sel)
                                            <span class="badge bg-light text-dark border">
                                                @if ($sel->recipient_type === 'specific_user' && $sel->recipientUser)
                                                    Specific: {{ $sel->recipientUser->username }}
                                                @else
                                                    {{ $recipients[$sel->recipient_type] ?? $sel->recipient_type }}
                                                @endif
                                            </span>
                                        @empty
                                            <span class="badge bg-warning-subtle text-warning">No recipients</span>
                                        @endforelse
                                    </div>
                                </td>
                                <td class="text-center">
                                    @if ($rule->is_active)
                                        <span class="badge rounded-pill bg-success-subtle text-success" title="Active">
                                            <i class="fas fa-circle-dot"></i> Active
                                        </span>
                                    @else
                                        <span class="badge rounded-pill bg-secondary-subtle text-secondary" title="Inactive">
                                            <i class="far fa-circle"></i> Inactive
                                        </span>
                                    @endif
                                </td>
                                <td class="text-center">
                                    <span class="fw-semibold">{{ number_format((int) $rule->times_fired) }}</span>
                                </td>
                                <td class="small">
                                    @if ($rule->creator)
                                        <i class="fas fa-user-circle text-muted me-1"></i>{{ $rule->creator->username }}
                                        <div class="text-muted">{{ optional($rule->created_at)->format('M d, Y') }}</div>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td class="text-center">
                                    <div class="btn-group btn-group-sm">
                                        <form method="POST" action="{{ route('admin.notifications.toggleRule', $rule->id) }}" class="d-inline">
                                            @csrf
                                            <button type="submit" class="btn btn-outline-{{ $rule->is_active ? 'warning' : 'success' }}"
                                                    title="{{ $rule->is_active ? 'Deactivate' : 'Activate' }}">
                                                <i class="fas fa-{{ $rule->is_active ? 'pause' : 'play' }}"></i>
                                            </button>
                                        </form>
                                        <button type="button" class="btn btn-outline-danger btn-delete-rule"
                                                data-id="{{ $rule->id }}"
                                                data-name="{{ e($rule->name) }}"
                                                data-url="{{ route('admin.notifications.destroyRule', $rule->id) }}"
                                                title="Delete">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center text-muted py-5">
                                    <i class="fas fa-bell-slash fa-2x mb-2 d-block opacity-50"></i>
                                    No notification rules found. Create your first rule above.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Pagination --}}
            <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
                <div class="text-muted small">
                    Showing {{ $rules->firstItem() ?? 0 }}–{{ $rules->lastItem() ?? 0 }} of {{ $rules->total() }} rules
                </div>
                {{ $rules->withQueryString()->links() }}
            </div>
        </div>
    </div>

</div>

@push('scripts')
<script>
    // F-18b: Show/hide Specific User field when 'specific_user' is among
    // the multi-select recipient_types selections.
    (function () {
        var sel = document.getElementById('recipientTypes');
        var wrap = document.getElementById('specificUserWrap');
        var userSel = document.getElementById('recipientUser');
        function sync() {
            if (!sel || !wrap) return;
            var selected = Array.prototype.map.call(sel.selectedOptions, function (o) { return o.value; });
            var show = (selected.indexOf('specific_user') !== -1);
            wrap.style.display = show ? '' : 'none';
            if (userSel) userSel.required = show;
        }
        if (sel) { sel.addEventListener('change', sync); sync(); }
    })();

    // Select2 for the user dropdown (nicer search)
    $(function () {
        $('#recipientUser').select2({
            placeholder: '— Select user —',
            allowClear: true,
            width: '100%',
            theme: 'bootstrap-5'
        });
    });

    // DataTables
    $(function () {
        $('#rulesTable').DataTable({
            paging: false,
            info: false,
            searching: true,
            order: [[4, 'desc']],
            dom: '<"d-flex justify-content-end mb-2"f>t'
        });
    });

    // SweetAlert2 delete confirmation
    $(function () {
        $(document).on('click', '.btn-delete-rule', function () {
            var name = $(this).data('name');
            var url  = $(this).data('url');
            Swal.fire({
                title: 'Delete rule?',
                html: 'Are you sure you want to delete <strong>"' + name + '"</strong>?<br>This cannot be undone.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                cancelButtonColor: '#6c757d',
                confirmButtonText: '<i class="fas fa-trash"></i> Yes, delete it',
                cancelButtonText: 'Cancel'
            }).then(function (res) {
                if (!res.isConfirmed) return;
                var csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
                var form = document.createElement('form');
                form.method = 'POST';
                form.action = url;
                form.innerHTML =
                    '<input type="hidden" name="_token" value="' + csrfToken + '">' +
                    '<input type="hidden" name="_method" value="DELETE">';
                document.body.appendChild(form);
                form.submit();
            });
        });
    });
</script>
@endpush
@endsection
