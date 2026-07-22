@extends('layouts.admin')

@section('content')
@include('admin.purchase.partials.audit-log-table', [
    'logs' => $logs,
    'module' => $module,
    'moduleLabel' => $moduleLabel,
    'indexRoute' => $indexRoute,
    'filters' => $filters,
])
@endsection
