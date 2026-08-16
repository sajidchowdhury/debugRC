@extends('layouts.app')

<?php
/** @var string $message */
$title = 'No Active Fiscal Year';
?>

@section('content')
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-8 col-lg-6">
            <div class="card border-danger">
                <div class="card-header bg-danger text-white">
                    <h4 class="mb-0">
                        <i class="fa-solid fa-triangle-exclamation me-2"></i>
                        No Active Fiscal Year
                    </h4>
                </div>
                <div class="card-body">
                    <p class="lead text-danger fw-semibold">
                        The system cannot process this request because no fiscal year is currently active.
                    </p>
                    <p class="text-muted">
                        {{ $message ?? 'No active fiscal year was found in the database.' }}
                    </p>
                    <hr>
                    <p class="mb-2">
                        <strong>What this means:</strong>
                    </p>
                    <ul class="text-muted small">
                        <li>All transactional data (sales, purchases, stock, journals) is filtered by the active fiscal year for isolation and audit compliance.</li>
                        <li>Until a fiscal year is activated, no operational transactions can be viewed or created.</li>
                        <li>This state typically occurs immediately after year-end close, before the next fiscal year has been activated.</li>
                    </ul>
                    <hr>
                    @can('create', \App\Models\FiscalYear::class)
                        <p class="mb-3">
                            <strong>As an administrator,</strong> you can activate a fiscal year now:
                        </p>
                        <a href="{{ url('/admin/fiscal-years') }}" class="btn btn-primary">
                            <i class="fa-solid fa-calendar-range me-1"></i>
                            Go to Fiscal Year Management
                        </a>
                    @else
                        <p class="mb-0 text-muted">
                            Please contact your system administrator to activate a fiscal year.
                        </p>
                    @endcan
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
