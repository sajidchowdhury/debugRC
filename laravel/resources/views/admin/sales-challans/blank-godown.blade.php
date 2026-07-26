<x-layouts.erp :title="$title" :tabs="[]" :hero="true">
@php
    $invoiceDate = $invoice->invoice_date
        ? \Carbon\Carbon::parse($invoice->invoice_date)->format('d M Y')
        : '—';
    $customerName = $invoice->customer?->customer_name ?? '—';
    $customerMobile = $invoice->customer?->mobile ?? '';
    $branchName = $invoice->branch?->branch_name ?? 'Branch';
    $itemCount = (int) $invoice->items->count();
    $invoiceTotal = (float) ($invoice->total_amount ?? 0);
    $salesmanName = $invoice->sales_person ?? ($invoice->salesman?->name ?? '');

    $hasEligibleDispatchers = $eligibleDispatchers->isNotEmpty();
    $alreadyPrinted = (bool) $invoice->is_blank_godown_printed;
    $printedAt = $invoice->blank_godown_printed_at
        ? \Carbon\Carbon::parse($invoice->blank_godown_printed_at)->format('d M Y, h:i A')
        : null;
@endphp

{{--
  Blank Godown Copy — Step 1 of the 3-step godown workflow.

  The warehouse manager selects one or more dispatchers, then clicks
  "Print Blank Godown Copy". The POST handler (storeBlankGodown) syncs
  the dispatchers, stamps is_blank_godown_printed=true, and redirects
  to the read-only print view (print_blank_godown.blade.php) which
  opens the browser print dialog.

  Only AFTER this print is recorded can the godown prep form (Step 2)
  be opened — enforced by SalesChallanController::godown() guard.

  Visual parity with godown.blade.php: same orange gradient hero, same
  4-card summary grid, same section-card pattern, same fixed bottom
  action bar. The journey-stepper uses a custom 4-step array so the
  "Blank" sub-step is visible inside the Godown phase.
--}}
<div class="space-y-5 challan-scope pb-24">
    {{-- ===== HERO (pure orange gradient — parity with godown page) ===== --}}
    <div class="bg-gradient-to-r from-orange-400 to-orange-500 rounded-xl p-4 md:p-6 shadow-lg">
        <div class="flex items-start justify-between flex-wrap gap-4">
            <div class="flex items-start gap-4">
                <div class="bg-white/20 backdrop-blur-sm rounded-xl size-14 flex items-center justify-center text-white shrink-0">
                    <x-erp.icon name="file-text" class="size-7" />
                </div>
                <div>
                    <div class="flex items-center gap-3 flex-wrap mt-1">
                        <h3 class="text-2xl font-bold text-white m-0">Blank Godown Copy</h3>
                        <span class="bg-white/20 rounded-full px-3 py-1 text-sm font-mono text-white">{{ $invoice->invoice_code }}</span>
                    </div>
                    <p class="text-amber-100 text-sm mt-1.5 flex items-center gap-2 flex-wrap">
                        <span class="inline-flex items-center gap-1">
                            <x-erp.icon name="map-pin" class="size-3.5" />
                            {{ $branchName }}
                        </span>
                        <span class="text-amber-200">·</span>
                        <span>খালি গোডাউন কপি / Step 1 of 3</span>
                    </p>
                </div>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                <a href="{{ route('admin.sales-challans.index') }}"
                   class="inline-flex items-center gap-1.5 bg-white/20 backdrop-blur-sm hover:bg-white/30 text-white rounded-lg px-3 py-2 text-xs font-medium transition-colors">
                    <x-erp.icon name="arrow-left" class="size-3.5" /> Back to list
                </a>
                <a href="{{ route('admin.sales-invoices.show', $invoice) }}"
                   class="inline-flex items-center gap-1.5 bg-white text-amber-700 hover:bg-amber-50 rounded-lg px-3 py-2 text-xs font-semibold transition-colors shadow-sm">
                    <x-erp.icon name="file-text" class="size-3.5" /> View invoice
                </a>
            </div>
        </div>

        {{-- Custom 4-step stepper: Invoice → Blank → Godown → Challan --}}
        <x-erp.journey-stepper :current="2" :steps="[
            ['label' => 'Invoice', 'label_bn' => 'চালান',     'icon' => 'file-text'],
            ['label' => 'Blank',   'label_bn' => 'খালি কপি',   'icon' => 'file-text'],
            ['label' => 'Godown',  'label_bn' => 'গোডাউন',    'icon' => 'warehouse'],
            ['label' => 'Challan', 'label_bn' => 'চালানপত্র',  'icon' => 'truck'],
        ]" />
    </div>

    {{-- ===== Already-printed banner (only when re-entering after first print) ===== --}}
    @if ($alreadyPrinted && $printedAt)
        <div class="bg-emerald-50 border border-emerald-200 rounded-xl p-4 flex items-start gap-3">
            <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-emerald-100 text-emerald-600">
                <x-erp.icon name="check-circle" class="size-5" />
            </span>
            <div class="flex-1 min-w-0">
                <p class="text-sm font-semibold text-emerald-800 m-0">Blank godown copy already printed on {{ $printedAt }}</p>
                <p class="text-xs text-emerald-700 mt-1 m-0">
                    You can re-print or change the dispatcher(s) below. The original print timestamp is preserved.
                    @if (!$invoice->is_godown_prepared)
                        When ready, proceed to <strong>Prepare Godown Copy</strong> (Step 2).
                    @endif
                </p>
            </div>
            @if (!$invoice->is_godown_prepared)
                <a href="{{ route('admin.sales-challans.godown', $invoice) }}"
                   class="inline-flex items-center gap-1.5 bg-amber-500 hover:bg-amber-600 text-white rounded-md px-3 py-2 text-xs font-medium transition-colors shadow-sm shrink-0">
                    <x-erp.icon name="warehouse" class="size-3.5" /> Prepare Godown
                    <x-erp.icon name="chevron-right" class="size-3" />
                </a>
            @endif
        </div>
    @endif

    {{-- ===== SUMMARY (4-col grid — parity with godown page) ===== --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="group rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <div class="flex items-start justify-between gap-2">
                <div class="flex flex-col gap-1 min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-400 m-0">Customer</p>
                    <p class="text-base font-bold leading-snug break-words text-slate-800 m-0">{{ $customerName }}</p>
                </div>
                <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-indigo-50 text-indigo-600">
                    <x-erp.icon name="users" class="size-[18px]" />
                </span>
            </div>
            <div class="mt-2.5">
                @if ($customerMobile)
                    <p class="font-mono text-xs text-slate-500 m-0">{{ $customerMobile }}</p>
                @else
                    <p class="text-xs text-slate-400 m-0">— no mobile —</p>
                @endif
            </div>
        </div>
        <div class="group rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <div class="flex items-start justify-between gap-2">
                <div class="flex flex-col gap-1 min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-400 m-0">Invoice date</p>
                    <p class="text-base font-bold leading-snug break-words text-slate-800 m-0">{{ $invoiceDate }}</p>
                </div>
                <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-amber-50 text-amber-600">
                    <x-erp.icon name="clock" class="size-[18px]" />
                </span>
            </div>
            <div class="mt-2.5">
                @if ($salesmanName)
                    <p class="text-xs text-slate-500 m-0"><span class="text-slate-400">Salesman:</span> {{ $salesmanName }}</p>
                @else
                    <p class="text-xs text-slate-400 m-0">— no salesman —</p>
                @endif
            </div>
        </div>
        <div class="group rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <div class="flex items-start justify-between gap-2">
                <div class="flex flex-col gap-1 min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-400 m-0">Items</p>
                    <p class="text-base font-bold leading-snug break-words text-slate-800 m-0">{{ $itemCount }}</p>
                </div>
                <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600">
                    <x-erp.icon name="box" class="size-[18px]" />
                </span>
            </div>
            <div class="mt-2.5">
                <p class="text-xs text-slate-500 m-0">line{{ $itemCount === 1 ? '' : 's' }} to pick</p>
            </div>
        </div>
        <div class="group rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <div class="flex items-start justify-between gap-2">
                <div class="flex flex-col gap-1 min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-400 m-0">Invoice total</p>
                    <p class="text-base font-bold leading-snug break-words text-slate-800 m-0">Tk {{ number_format($invoiceTotal, 2) }}</p>
                </div>
                <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-rose-50 text-rose-600">
                    <x-erp.icon name="banknote" class="size-[18px]" />
                </span>
            </div>
            <div class="mt-2.5">
                <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium bg-amber-100 text-amber-800">Awaiting blank godown</span>
            </div>
        </div>
    </div>

    {{-- ===== MAIN FORM ===== --}}
    <form method="POST" action="{{ route('admin.sales-challans.store-blank-godown', $invoice) }}">
        @csrf

        {{-- ===== DISPATCHER SELECT SECTION ===== --}}
        <section class="rounded-xl border border-slate-200 bg-white shadow-sm overflow-hidden">
            <div class="bg-amber-50/60 border-b border-amber-100 px-5 py-3 flex items-center gap-2.5">
                <span class="flex size-8 items-center justify-center rounded-lg bg-amber-100 text-amber-600">
                    <x-erp.icon name="user-check" class="size-4" />
                </span>
                <div class="flex-1 min-w-0">
                    <h2 class="text-sm font-semibold text-amber-900 m-0">Dispatcher(s) <span class="text-red-500">*</span></h2>
                    <p class="text-xs text-amber-700 m-0">ডিসপ্যাচার নির্বাচন করুন — mandatory before printing</p>
                </div>
            </div>
            <div class="p-5">
                @if ($hasEligibleDispatchers)
                    <select id="dispatcher_id" name="dispatcher_id[]" multiple
                            class="form-select" required>
                        @foreach ($eligibleDispatchers as $d)
                            @php
                                $label = $d['name'];
                                if (!empty($d['employee_code'])) { $label .= ' (' . $d['employee_code'] . ')'; }
                                if (!empty($d['branch_name'])) { $label .= ' — ' . $d['branch_name']; }
                            @endphp
                            <option value="{{ $d['id'] }}" @if($d['selected']) selected @endif>{{ $label }}</option>
                        @endforeach
                    </select>
                    <p class="text-xs text-slate-500 mt-2 mb-0">
                        Select one or more warehouse dispatchers for this delivery. The selected dispatcher(s) will carry the blank godown copy to the warehouse floor.
                    </p>
                @else
                    <div class="bg-amber-50 border border-amber-200 rounded-lg p-4 text-center">
                        <x-erp.icon name="alert-triangle" class="size-8 text-amber-400 mx-auto mb-2" />
                        <p class="text-sm font-medium text-amber-800 m-0">No active dispatchers found for this branch.</p>
                        <p class="text-xs text-amber-700 mt-1 m-0">
                            Please ask an admin to add an active dispatcher-role employee to the
                            <strong>{{ $branchName }}</strong> branch before the blank godown copy can be printed.
                        </p>
                    </div>
                @endif
            </div>
        </section>

        {{-- ===== BOTTOM ACTION BAR (fixed — parity with godown page) ===== --}}
        <footer class="fixed bottom-0 inset-x-0 z-40 border-t border-slate-200 bg-white px-4 py-3 shadow-[0_-4px_16px_rgba(15,23,42,0.08)] no-print">
            <div class="mx-auto flex max-w-[1600px] flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <a href="{{ route('admin.sales-invoices.show', $invoice) }}"
                   class="inline-flex items-center justify-center gap-2 self-start rounded-md px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-900 transition-colors min-h-[40px]">
                    <x-erp.icon name="arrow-left" class="size-4" /> Back
                </a>
                <div class="flex flex-wrap items-center gap-2 sm:gap-3">
                    <button type="submit" id="btn-print-blank-godown"
                            class="inline-flex h-10 items-center gap-2 rounded-md bg-indigo-600 text-white shadow-md hover:bg-indigo-700 px-4 text-sm font-medium transition-colors disabled:opacity-50 disabled:pointer-events-none"
                            @if (!$hasEligibleDispatchers) disabled @endif>
                        <x-erp.icon name="printer" class="size-4" />
                        {{ $alreadyPrinted ? 'Re-print Blank Godown' : 'Print Blank Godown Copy' }}
                    </button>
                    @if ($alreadyPrinted && !$invoice->is_godown_prepared)
                        <a href="{{ route('admin.sales-challans.godown', $invoice) }}"
                           class="inline-flex h-10 items-center gap-2 rounded-md bg-amber-500 text-white shadow-md hover:bg-amber-600 px-4 text-sm font-medium transition-colors min-h-[40px]"
                           title="Proceed to godown prep (Step 2)">
                            <x-erp.icon name="warehouse" class="size-4" /> Prepare Godown
                            <x-erp.icon name="chevron-right" class="size-3" />
                        </a>
                    @endif
                </div>
            </div>
        </footer>
    </form>
</div>

</x-layouts.erp>

@push('scripts')
<script>
$(function () {
    // Select2 init for the dispatcher multi-select (client-side search only,
    // no AJAX — all eligible dispatchers are server-rendered as <option>s).
    var $dispatcherSelect = $('#dispatcher_id');
    if ($dispatcherSelect.length) {
        $dispatcherSelect.select2({
            theme: 'bootstrap-5',
            width: '100%',
            placeholder: '— select dispatcher(s) —',
            allowClear: false,
            minimumInputLength: 0,
            minimumResultsForSearch: 0
        });
    }

    // Pre-submit guard: ensure at least one dispatcher is selected before
    // the POST fires (defense-in-depth on top of the server-side required
    // rule in PrintBlankGodownWebRequest).
    $('form').on('submit', function (e) {
        var count = $dispatcherSelect.select2('data').length;
        if (count < 1) {
            e.preventDefault();
            Swal.fire({
                icon: 'warning',
                title: 'Dispatcher required',
                text: 'Please select at least one dispatcher before printing the blank godown copy.',
                confirmButtonColor: '#4f46e5'
            });
            return false;
        }
        // Swal2 confirmation — makes the print intent explicit.
        e.preventDefault();
        Swal.fire({
            icon: 'question',
            title: 'Print blank godown copy?',
            text: 'The selected dispatcher(s) will be saved and the print view will open in your browser.',
            showCancelButton: true,
            confirmButtonColor: '#4f46e5',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Yes, print it'
        }).then(function (result) {
            if (result.isConfirmed) {
                // Re-submit the form programmatically (bypass the jQuery
                // handler by setting a flag).
                $('form').off('submit').submit();
            }
        });
    });
});
</script>
@endpush
