@extends('layouts.admin')

@section('content')
@php
    $action = (string) ($entry->action ?? '');
    $cls = str_contains($action, 'created') ? 'bg-success-subtle text-success'
         : (str_contains($action, 'updated') ? 'bg-primary-subtle text-primary'
         : (str_contains($action, 'deleted') ? 'bg-danger-subtle text-danger'
         : (str_contains($action, 'restored') ? 'bg-info-subtle text-info'
         : 'bg-warning-subtle text-warning')));
    $performerName = $entry->performed_by_name ?? ($entry->username ?? ('#' . ($entry->user_id ?? 0)));
    $old = is_array($details['old'] ?? null) ? $details['old'] : [];
    $new = is_array($details['new'] ?? null) ? $details['new'] : [];
@endphp

<div class="container-fluid py-2">
    {{-- Hero header --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#7c3aed,#a855f7);">
        <div>
            <h1 class="h4 mb-1"><i class="fas fa-clock-rotate-left me-2"></i>Audit entry #{{ $entry->id }}</h1>
            <p class="mb-0 small opacity-75">
                {{ ucfirst(str_replace('master_data_', '', $action ?: 'unknown')) }} on
                <code>{{ $details['table'] ?? 'unknown' }}</code> record
                <code>#{{ $details['record_id'] ?? 'unknown' }}</code>
            </p>
        </div>
        <a href="{{ route('admin.audit.index', request()->only(['table','action','user_id','from','to','record_id','search','page'])) }}"
           class="btn btn-light btn-sm">
            <i class="fas fa-arrow-left me-1"></i> Back to list
        </a>
    </header>

    <div class="row g-3">
        {{-- Left column: entry metadata --}}
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white">
                    <h5 class="mb-0"><i class="fas fa-circle-info me-2 text-primary"></i>Entry details</h5>
                </div>
                <div class="card-body">
                    <dl class="row mb-0 small">
                        <dt class="col-sm-4 text-muted">ID</dt>
                        <dd class="col-sm-8">#{{ $entry->id }}</dd>

                        <dt class="col-sm-4 text-muted">Action</dt>
                        <dd class="col-sm-8"><span class="badge {{ $cls }}">{{ $action }}</span></dd>

                        <dt class="col-sm-4 text-muted">Table</dt>
                        <dd class="col-sm-8"><code>{{ $details['table'] ?? '—' }}</code></dd>

                        <dt class="col-sm-4 text-muted">Record ID</dt>
                        <dd class="col-sm-8"><code>{{ $details['record_id'] ?? '—' }}</code></dd>

                        <dt class="col-sm-4 text-muted">Performed by</dt>
                        <dd class="col-sm-8">
                            @if ($entry->user_id)
                                <a href="{{ route('admin.users.show', $entry->user_id) }}" class="text-decoration-none">
                                    {{ $performerName }}
                                </a>
                            @else
                                <span class="text-muted">system / unauthenticated</span>
                            @endif
                        </dd>

                        <dt class="col-sm-4 text-muted">Timestamp</dt>
                        <dd class="col-sm-8">
                            @if ($entry->created_at)
                                {{ \Carbon\Carbon::parse($entry->created_at)->format('d M Y, H:i:s') }}
                            @else
                                —
                            @endif
                        </dd>

                        <dt class="col-sm-4 text-muted">IP address</dt>
                        <dd class="col-sm-8">{{ $entry->ip_address ?? '—' }}</dd>

                        <dt class="col-sm-4 text-muted">User agent</dt>
                        <dd class="col-sm-8"><span class="text-break">{{ $entry->user_agent ?? '—' }}</span></dd>

                        @if (!empty($entry->branch_id))
                        <dt class="col-sm-4 text-muted">Branch</dt>
                        <dd class="col-sm-8">#{{ $entry->branch_id }}</dd>
                        @endif

                        @if (!empty($entry->target_user_id))
                        <dt class="col-sm-4 text-muted">Target user</dt>
                        <dd class="col-sm-8">#{{ $entry->target_user_id }}</dd>
                        @endif
                    </dl>
                </div>
            </div>
        </div>

        {{-- Right column: JSON diff --}}
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white d-flex align-items-center justify-content-between">
                    <h5 class="mb-0"><i class="fas fa-code-compare me-2 text-primary"></i>Field-level diff</h5>
                    <div>
                        <span class="badge bg-success-subtle text-success">+ new value</span>
                        <span class="badge bg-danger-subtle text-danger">- old value</span>
                    </div>
                </div>
                <div class="card-body">
                    @if (empty($old) && empty($new))
                        <p class="text-muted text-center py-4 mb-0">
                            <i class="fas fa-inbox fa-2x mb-2 d-block opacity-50"></i>
                            No old/new payload recorded for this entry.
                        </p>
                    @else
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered mb-0 align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width: 30%;">Field</th>
                                        <th style="width: 35%;">Old value</th>
                                        <th style="width: 35%;">New value</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($diff as $row)
                                        @php
                                            $rowCls = match($row['state']) {
                                                'added'     => 'table-success',
                                                'removed'   => 'table-danger',
                                                'changed'   => 'table-warning',
                                                default     => '',
                                            };
                                        @endphp
                                        <tr class="{{ $rowCls }}">
                                            <td><code>{{ $row['field'] }}</code></td>
                                            <td class="text-danger-emphasis small">
                                                @if ($row['has_old'])
                                                    <span class="text-danger">- {{ $row['old'] }}</span>
                                                @else
                                                    <span class="text-muted">—</span>
                                                @endif
                                            </td>
                                            <td class="text-success-emphasis small">
                                                @if ($row['has_new'])
                                                    <span class="text-success">+ {{ $row['new'] }}</span>
                                                @else
                                                    <span class="text-muted">—</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="3" class="text-center text-muted py-3">No fields to display.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>

            {{-- Raw JSON payload (collapsible) --}}
            <div class="card border-0 shadow-sm mt-3">
                <div class="card-header bg-white">
                    <a href="#rawJson" data-bs-toggle="collapse" class="text-decoration-none d-flex align-items-center">
                        <i class="fas fa-code me-2 text-muted"></i>
                        <span class="fw-semibold">Raw JSON details</span>
                        <i class="fas fa-chevron-down ms-auto small"></i>
                    </a>
                </div>
                <div class="collapse" id="rawJson">
                    <pre class="card-body bg-light p-3 mb-0 small"><code>{{ json_encode($details, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</code></pre>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
