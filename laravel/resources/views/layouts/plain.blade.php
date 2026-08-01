{{--
    Plain layout — Phase 6 (Polish, Performance & Post-Launch Gaps).

    Used by UserPerformanceDashboardController::fragmentAjax() to render
    the dashboard view with NO surrounding chrome (no <html>, <head>,
    sidebar, navbar, footer). The AJAX caller parses the response and
    swaps just the #perf-dashboard container into the live page.

    This layout outputs:
      1. @yield('content')      — the dashboard body
      2. @stack('css')          — page-scoped <style> blocks (so the
                                  fragment includes the same CSS as the
                                  full page; the browser de-dupes identical
                                  <style> tags after swap, so no harm)
      3. @stack('scripts')      — the chart-init <script> block. The AJAX
                                  caller extracts <script> elements from
                                  the fragment response and re-executes
                                  them via window.initPerfDashboard().

    The @stack directives are emitted ONLY so the Blade compiler has a
    place to dump @push'd content. They produce no chrome of their own.
--}}
@yield('content')
@stack('css')
@stack('scripts')
