@extends('layouts.admin')

@section('content')
<div class="container-fluid py-2">
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#6366f1,#4f46e5);">
        <div>
            <h1 class="h4 mb-1"><i class="fas fa-history me-2"></i>{{ $title }}</h1>
            <p class="mb-0 small opacity-75">Audit trail for manual journal create + reverse actions.</p>
        </div>
        <a href="{{ route('admin.manual-journals.index') }}" class="btn btn-outline-light btn-sm">
            <i class="fas fa-arrow-left me-1"></i> Back to list
        </a>
    </header>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm table-striped align-middle mb-0" id="dataTable">
                    <thead class="table-light">
                        <tr>
                            <th>When</th>
                            <th>User</th>
                            <th>Action</th>
                            <th>Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($logs as $log)
                            <tr>
                                <td class="text-nowrap small">{{ \Carbon\Carbon::parse($log->created_at)->format('d M Y H:i') }}</td>
                                <td>User #{{ $log->user_id }}</td>
                                <td>
                                    @if (str_contains($log->action, 'created'))
                                        <span class="badge bg-success"><i class="fas fa-plus me-1"></i>{{ $log->action }}</span>
                                    @elseif (str_contains($log->action, 'reversed'))
                                        <span class="badge bg-danger"><i class="fas fa-rotate-left me-1"></i>{{ $log->action }}</span>
                                    @else
                                        <span class="badge bg-secondary">{{ $log->action }}</span>
                                    @endif
                                </td>
                                <td class="small text-muted">
                                    @php $details = json_decode($log->details ?? '{}', true); @endphp
                                    @if (!empty($details['journal_code']))
                                        <span class="fw-semibold">{{ $details['journal_code'] }}</span>
                                    @endif
                                    @if (!empty($details['status']))
                                        · status: {{ $details['status'] }}
                                    @endif
                                    @if (!empty($details['total_debit']))
                                        · Tk {{ number_format((float) $details['total_debit'], 2) }} / Tk {{ number_format((float) ($details['total_credit'] ?? 0), 2) }}
                                    @endif
                                    @if (!empty($details['reason']))
                                        · reason: {{ $details['reason'] }}
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="text-center text-muted py-5">
                                    <i class="fas fa-inbox fa-2x mb-2 d-block opacity-50"></i>
                                    No audit logs found.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
$(function () {
    var $dataTable = $('#dataTable');
    var hasDataRows = $dataTable.find('tbody tr').filter(function () {
        return $(this).find('td[colspan]').length === 0;
    }).length > 0;
    if (hasDataRows) {
        $dataTable.DataTable({
            paging: false, info: false, ordering: true,
            dom: '<"row mb-2"<"col-md-6"f><"col-md-6 text-end"l>>rt',
            language: { search: 'Filter:', emptyTable: 'No audit logs.' }
        });
    }
});
</script>
@endpush
@endsection
