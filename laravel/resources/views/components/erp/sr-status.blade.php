{{--
  x-erp.sr-status — visually-hidden aria-live region for screen-reader
  announcements. The JS helper `announceSR(msg)` (defined inline on the
  sales-invoices index page) writes to this region so screen readers
  announce filter changes, selection counts, payment events, etc.

  Usage:
    <x-erp.sr-status id="srStatus" />
    // JS: window.announceSR = (m) => { document.getElementById('srStatus').textContent = m; };

  Marked role="status" + aria-live="polite" + aria-atomic="true" so the
  whole message is announced (not just the diff) and only when the user
  is idle (polite, not assertive).
--}}
@props([
    'id' => 'srStatus',
])

<div id="{{ $id }}"
     role="status"
     aria-live="polite"
     aria-atomic="true"
     {{ $attributes->merge(['class' => 'sr-only']) }}></div>
