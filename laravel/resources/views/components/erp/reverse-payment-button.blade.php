{{--
  x-erp.reverse-payment-button — danger-outline button that opens a SweetAlert2
  reason prompt and AJAX-POSTs to admin.customer-payments.cancel.

  Usage (role-gated by the caller):
    @if (auth()->user()->hasRole('accountant', 'manager', 'admin', 'superadmin'))
        <x-erp.reverse-payment-button :payment-id="$p['payment_id']" :payment-code="$p['payment_code']" />
    @endif

  Phase 3 (UI/UX plan — Inline Reverse): rendered once per existing payment
  inside the receive modal's "Payments on this invoice" list. The button
  carries data-payment-id + data-payment-code so a single delegated JS
  handler (in sales-invoices/index.blade.php) can open the SweetAlert2
  reason prompt and POST the reversal.

  Styling: red-outline icon button (rotate-ccw icon). Full-width on mobile
  (w-full) so it sits cleanly under each payment in the narrow modal list;
  auto-width on ≥576px (sm:w-auto). The caller is responsible for role-
  gating — this component does NOT check permissions (keeps it a pure
  presentation component, consistent with <x-erp.action-button>).
--}}
@props([
    'paymentId' => 0,
    'paymentCode' => '',
])

@php
    $pid = (int) $paymentId;
    $code = (string) $paymentCode;
@endphp

<button type="button"
        class="btn-reverse-payment inline-flex items-center justify-center gap-1 rounded-md border border-red-300 bg-white px-2 py-1 text-xs font-medium text-red-600 hover:bg-red-50 hover:border-red-400 transition-colors w-full sm:w-auto focus:outline-none focus-visible:ring-2 focus-visible:ring-red-400 focus-visible:ring-offset-1"
        data-payment-id="{{ $pid }}"
        data-payment-code="{{ $code }}"
        aria-label="Reverse payment {{ $code }}"
        title="Reverse payment {{ $code }}">
    <x-erp.icon name="rotate-ccw" class="size-3" />
    <span>Reverse</span>
</button>
