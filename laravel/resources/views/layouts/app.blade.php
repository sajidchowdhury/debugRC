<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Remote Center ERP' }}</title>

    {{-- Bootstrap 5 + Font Awesome (served locally — no CDN dependency) --}}
    <link href="/assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="/assets/css/all.min.css" rel="stylesheet">

    {{-- Link to the legacy app's shared assets (on the VPS, these are served from /assets/) --}}
    <link href="/assets/css/custom.css" rel="stylesheet">

    <style>
        body { background-color: #f8f9fa; }
        .erp-login-card { max-width: 420px; margin: 0 auto; }
    </style>
</head>
<body>
    @yield('content')

    <script src="/assets/js/bootstrep/bootstrap.bundle.min.js"></script>
    @yield('scripts')
</body>
</html>
