{{--
  layouts/erp-preview.blade.php — minimal showcase layout (no admin sidebar).

  Used by the /ui-preview route to display the design-system component library
  in isolation. Loads the same Bootstrap + rc-erp.css stylesheets as the admin
  layout so Tailwind utilities are available, but skips the sidebar, nav, and
  flash-message chrome.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name') }} — UI Preview</title>

    {{-- Same stylesheets as layouts/admin.blade.php so Tailwind utilities load --}}
    <link href="/assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/all.min.css">
    <link href="/assets/css/sweetalert2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/custom.css">
    <link rel="stylesheet" href="/assets/css/footer-dropup.css">

    {{-- RC ERP design-system (Tailwind v4, no preflight — coexists with Bootstrap) --}}
    <link rel="stylesheet" href="/assets/css/rc-erp.css">

    @stack('css')
</head>
<body class="bg-gradient-to-b from-amber-50/30 to-white min-h-screen font-sans text-gray-900">

    {{ $slot }}

    <script src="/assets/js/bootstrep/jquery-3.6.0.min.js"></script>
    <script src="/assets/js/bootstrep/bootstrap.bundle.min.js"></script>
    @stack('scripts')
</body>
</html>
