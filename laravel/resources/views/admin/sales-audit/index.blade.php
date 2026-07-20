@extends('layouts.admin')

@section('content')
@php
    $actionLabels = $actionLabels ?? [];
    $users = $users ?? collect();
@endphp

<div class="container-fluid py-2">

    {{-- Header --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background:linear-gradient(135deg,#7c3aed,#4f46e5);">
        <div>
            <h1 class="h3 mb-1">
                <i class="fas fa-clipboard-list me-2"></i>{{ $title ?? 'Sales Audit Trail' }}
            </h1>
            <p class="mb-0 opacity-75">
                <i class="fas fa-shield-halved me-1"></i> Business event log for compliance &amp; forensics
            </p>
        </div>
        <div class="d-flex gap-1">
            <a href="{{ route('admin.sales-invoices.index') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to Invoices
            </a>
        </div>
    </header>

    {{-- Filters --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <form method="GET" action="{{ route('admin.sales.audit') }}" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small fw-semibold mb-1">Action</label>
                    <select name="action" class="form-select form-select-sm">
                        <option value="">All actions</option>
                        @foreach ($actionLabels as $key => $meta)
                            <option value="{{ $key }}" @if (($filters['action'] ?? '') === $key) selected @endif>
                                {{ $meta['label'] }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold mb-1">Branch</label>
                    <select name="branch_id" class="form-select form-select-sm">
                        <option value="">All branches</option>
                        @foreach ($branches as $branch)
                            <option value="{{ $branch->id }}" @if ((int)($filters['branch_id'] ?? 0) === (int)$branch->id) selected @endif>
                                {{ $branch->branch_name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold mb-1">Limit</label>
                    <select name="limit" class="form-select form-select-sm">
                        @foreach ([100, 300, 500] as $lim)
                            <option value="{{ $lim }}" @if ((int)($filters['limit'] ?? 300) === $lim) selected @endif>{{ $lim }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary btn-sm w-100">
                        <i class="fas fa-filter me-1"></i> Filter
                    </button>
                </div>
                <div class="col-md-2">
                    <a href="{{ route('admin.sales.audit') }}" class="btn btn-outline-secondary btn-sm w-100">
                        <i class="fas fa-times me-1"></i> Clear
                    </a>
                </div>
            </form>
        </div>
    </div>

    {{-- Events table --}}
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <h2 class="h6 mb-0">
                <i class="fas fa-list me-1" style="color:#7c3aed;"></i>
                {{ number_format($events->count()) }} Event(s)
            </h2>
            <small class="text-muted">Most recent first</small>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th style="width:5%;">#</th>
                            <th style="width:15%;">When</th>
                            <th style="width:18%;">Event</th>
                            <th style="width:12%;">User</th>
                            <th style="width:10%;">Branch</th>
                            <th style="width:10%;">IP</th>
                            <th>Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($events as $event)
                            @php
                                $meta = $actionLabels[$event->action] ?? ['label' => $event->action, 'icon' => 'fa-circle-info', 'color' => 'secondary'];
                                $details = json_decode($event->details ?? '{}', true) ?: [];
                                $userName = $users[$event->user_id] ?? ('User #' . $event->user_id);
                            @endphp
                            <tr>
                                <td class="text-muted small">{{ $event->id }}</td>
                                <td class="small">
                                    @if ($event->created_at)
                                        {{ \Carbon\Carbon::parse($event->created_at)->format('d M Y, H:i') }}
                                    @endif
                                </td>
                                <td>
                                    <span class="badge bg-{{ $meta['color'] }}-subtle text-{{ $meta['color'] }}">
                                        <i class="fas {{ $meta['icon'] }} me-1"></i>{{ $meta['label'] }}
                                    </span>
                                    <br><code class="small text-muted">{{ $event->action }}</code>
                                </td>
                                <td class="small">{{ $userName }}</td>
                                <td class="small text-muted">{{ $event->branch_id ?? '—' }}</td>
                                <td class="small text-muted">{{ $event->ip_address ?? '—' }}</td>
                                <td class="small">
                                    @if (!empty($details))
                                        <details>
                                            <summary class="cursor-pointer text-muted">
                                                @if (isset($details['invoice_code']))
                                                    <strong>{{ $details['invoice_code'] }}</strong>
                                                @elseif (isset($details['challan_code']))
                                                    <strong>{{ $details['challan_code'] }}</strong>
                                                @elseif (isset($details['return_code']))
                                                    <strong>{{ $details['return_code'] }}</strong>
                                                @elseif (isset($details['payment_code']))
                                                    <strong>{{ $details['payment_code'] }}</strong>
                                                @else
                                                    View details
                                                @endif
                                            </summary>
                                            <pre class="small mt-1 mb-0 p-2 bg-light rounded"><code>{{ json_encode($details, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</code></pre>
                                        </details>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center text-muted py-4">
                                    <i class="fas fa-inbox fa-2x mb-2 d-block"></i>
                                    No sales audit events found.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Info note --}}
    <div class="alert alert-info small mt-3 mb-0">
        <i class="fas fa-circle-info me-1"></i>
        This audit trail captures all sales business events (invoice create/edit/cancel, payments, returns, godown/challan operations, stale-draft cleanup). Events are dual-written to the database and <code>logs/user_audit.log</code> file.
    </div>
</div>
