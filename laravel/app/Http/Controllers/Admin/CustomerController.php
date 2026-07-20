<?php

namespace App\Http\Controllers\Admin;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerLedger;
use App\Models\CustomerPayment;
use App\Models\Employee;
use App\Models\SalesInvoice;
use App\Models\SalesReturn;
use App\Services\MasterData\CodeGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Customer master-data controller — Phase 4 + Phase 10 hardening + Customer 360 Hub.
 *
 * Replicates the legacy /customer/* PHP UI in Blade, riding on the shared
 * BaseMasterDataController CRUD skeleton. Behaviour overrides:
 *   - show(): Customer 360 Hub — loads summary KPIs, AR balance, credit
 *     utilization, recent transactions, and tab data (invoices, payments,
 *     ledger, returns) in a single page. Legacy only had a basic detail card.
 *   - store(): auto-generate customer_code (CUS-YYYY-NNNNNN) when blank
 *     via the centralized CodeGenerator service (Phase 17 refactor — the
 *     per-controller generateCustomerCode() method was removed).
 *   - store()/update(): pre-normalize customer_code (uppercase + trim)
 *     BEFORE validation so the unique rule is case-insensitive.
 *   - update(): runs canDeactivate() when is_active is being flipped to
 *     false (so admins can't accidentally lock out customers with AR).
 *   - canDeactivate(): 2 safety checks — outstanding AR balance in
 *     customer_ledger + open (non-cancelled, non-reversed) sales invoices.
 *   - AJAX endpoints: ledgerData(), invoicesData(), paymentsData(), returnsData()
 *     for server-side DataTables pagination on each 360 hub tab.
 */
class CustomerController extends BaseMasterDataController
{
    protected string $modelClass = Customer::class;
    protected string $label      = 'Customer';
    protected string $routePrefix = 'admin.customers';
    protected string $viewDir     = 'admin.customers';

    protected array $searchFields = [
        'customer_code',
        'customer_name',
        'mobile',
        'phone',
    ];

    /** Enable PostgreSQL full-text search (tsvector + GIN) for customer search. */
    protected bool $useFullTextSearch = true;

    /**
     * Stats cards shown on the index hero.
     */
    protected function indexStats(): array
    {
        return [
            'active'   => Customer::active()->count(),
            'inactive' => Customer::onlyTrashed()->count(),
            'total'    => Customer::withTrashed()->count(),
        ];
    }

    /**
     * Eager-load branch + salesPerson for the index listing.
     */
    protected function indexWith(): array
    {
        return ['branch', 'salesPerson'];
    }

    /**
     * Eager-load branch + salesPerson for the detail/show page.
     */
    protected function detailWith(): array
    {
        return ['branch', 'salesPerson'];
    }

    /**
     * Phase 18: Columns to export for the CSV download.
     */
    protected function exportColumns(): array
    {
        return [
            'customer_code'      => 'Code',
            'customer_name'      => 'Customer Name',
            'phone'              => 'Phone',
            'mobile'             => 'Mobile',
            'email'              => 'Email',
            'branch.branch_name' => 'Branch Name',
            'is_active'          => 'Active',
        ];
    }

    /**
     * Select-dropdown data for create/edit views.
     */
    protected function formData(): array
    {
        return [
            'branches' => Branch::active()->orderBy('branch_name')->get(),
            'salesPersons' => Employee::active()
                ->whereIn('role', ['salesman', 'manager'])
                ->orderBy('name')
                ->get(),
        ];
    }

    /**
     * Validation rules — used for both store and update.
     * The $id parameter is forwarded by the base controller on update so the
     * unique rule excludes the current row.
     *
     * Phase 10: default $id to 0 when null (matches Branch/Warehouse pattern
     * and avoids "invalid input syntax for type integer" on store).
     */
    protected function validationRules(?int $id = null): array
    {
        $id = $id ?? 0;

        return [
            'customer_code'   => ['nullable', 'string', 'max:30', "unique:customers,customer_code,{$id}"],
            'customer_name'   => ['required', 'string', 'max:200'],
            'phone'           => ['nullable', 'string', 'max:30'],
            'mobile'          => ['nullable', 'string', 'max:30'],
            'email'           => ['nullable', 'email', 'max:100'],
            'address'         => ['nullable', 'string'],
            'branch_id'       => ['nullable', 'exists:branches,id'],
            'sales_person_id' => ['nullable', 'exists:employees,id'],
            'credit_limit'    => ['nullable', 'numeric', 'min:0'],
            'opening_balance' => ['nullable', 'numeric'],
            'balance_type'    => ['nullable', 'in:debit,credit'],
            'is_active'       => ['boolean'],
        ];
    }

    // ───────── Customer 360 Hub ─────────

    /**
     * Override show(): Customer 360 Hub view.
     *
     * Replaces the static master-data card with a full 360° view:
     *   - Hero header (name, code, status, branch)
     *   - KPI cards (AR balance, credit utilization, total invoiced, total paid,
     *     open invoices, last payment date)
     *   - Tabbed interface: Overview | Ledger | Invoices | Payments | Returns
     *   - Each tab loads via AJAX for server-side DataTables pagination
     *
     * The legacy CustomerController::show() only showed master-data fields.
     * This matches the legacy "customer details" page which had summary,
     * ledger, invoices, and payments all in one view.
     */
    public function show(int $id)
    {
        $customer = Customer::with(['branch', 'salesPerson'])
            ->withTrashed()
            ->findOrFail($id);

        // ── KPI calculations ──
        $arBalance = CustomerLedger::getBalance($customer->id);
        $creditLimit = (float) ($customer->credit_limit ?? 0);
        $creditUtilization = $creditLimit > 0
            ? round(($arBalance / $creditLimit) * 100, 1)
            : ($arBalance > 0 ? 999.9 : 0);

        $totalInvoiced = (float) SalesInvoice::where('customer_id', $customer->id)
            ->where('is_reversed', false)
            ->whereNotIn('status', ['cancelled', 'reversed'])
            ->sum('total_amount');

        $totalPaid = (float) CustomerPayment::where('customer_id', $customer->id)
            ->where('is_reversed', false)
            ->whereNotIn('status', ['cancelled'])
            ->sum('amount');

        $openInvoices = SalesInvoice::where('customer_id', $customer->id)
            ->where('is_reversed', false)
            ->whereNotIn('status', ['cancelled', 'reversed'])
            ->whereRaw('due_amount > 0')
            ->count();

        $lastPayment = CustomerPayment::where('customer_id', $customer->id)
            ->where('is_reversed', false)
            ->whereNotIn('status', ['cancelled'])
            ->orderBy('payment_date', 'desc')
            ->first();

        $totalReturns = (float) SalesReturn::where('customer_id', $customer->id)
            ->where('is_reversed', false)
            ->whereNotIn('status', ['cancelled'])
            ->sum('total_amount');

        return view("{$this->viewDir}.show", [
            'title'            => "{$this->label} 360° Hub",
            'item'             => $customer,
            'routePrefix'      => $this->routePrefix,
            'label'            => $this->label,
            'arBalance'        => $arBalance,
            'creditUtilization'=> $creditUtilization,
            'totalInvoiced'    => $totalInvoiced,
            'totalPaid'        => $totalPaid,
            'openInvoices'     => $openInvoices,
            'lastPayment'      => $lastPayment,
            'totalReturns'     => $totalReturns,
        ]);
    }

    /**
     * AJAX: Customer ledger entries (DataTables server-side).
     * Route: GET admin/customers/{id}/ledger-data
     */
    public function ledgerData(Request $request, int $id)
    {
        $customer = Customer::withTrashed()->findOrFail($id);

        $query = CustomerLedger::where('customer_id', $customer->id)
            ->orderBy('transaction_date', 'desc')
            ->orderBy('id', 'desc');

        // Optional date range filter
        if ($request->filled('from')) {
            $query->where('transaction_date', '>=', $request->input('from'));
        }
        if ($request->filled('to')) {
            $query->where('transaction_date', '<=', $request->input('to'));
        }

        $draw    = (int) $request->input('draw', 1);
        $start   = (int) $request->input('start', 0);
        $length  = (int) $request->input('length', 25);
        $search  = trim((string) $request->input('search.value', ''));

        $total = $query->count();

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->orWhere('transaction_type', 'ILIKE', "%{$search}%")
                  ->orWhere('description', 'ILIKE', "%{$search}%")
                  ->orWhere('reference_type', 'ILIKE', "%{$search}%");
            });
        }

        $filtered = $query->count();
        $entries  = $query->skip($start)->take($length)->get();

        $data = $entries->map(function ($entry) {
            return [
                'id'               => $entry->id,
                'transaction_date' => $entry->transaction_date ? $entry->transaction_date->format('d M Y') : '—',
                'transaction_type' => ucfirst(str_replace('_', ' ', $entry->transaction_type ?? '')),
                'reference_type'   => $entry->reference_type ? ucfirst(str_replace('_', ' ', $entry->reference_type)) : '—',
                'reference_id'     => $entry->reference_id ?? '—',
                'debit'            => (float) $entry->debit > 0 ? number_format((float) $entry->debit, 2) : '—',
                'credit'           => (float) $entry->credit > 0 ? number_format((float) $entry->credit, 2) : '—',
                'balance'          => number_format((float) $entry->balance, 2),
                'is_reversed'      => $entry->is_reversed,
                'description'      => $entry->description ?? '—',
            ];
        });

        return response()->json([
            'draw'            => $draw,
            'recordsTotal'    => $total,
            'recordsFiltered' => $filtered,
            'data'            => $data,
        ]);
    }

    /**
     * AJAX: Customer sales invoices (DataTables server-side).
     * Route: GET admin/customers/{id}/invoices-data
     */
    public function invoicesData(Request $request, int $id)
    {
        $customer = Customer::withTrashed()->findOrFail($id);

        $query = SalesInvoice::where('customer_id', $customer->id)
            ->with(['salesman'])
            ->orderBy('invoice_date', 'desc')
            ->orderBy('id', 'desc');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $draw    = (int) $request->input('draw', 1);
        $start   = (int) $request->input('start', 0);
        $length  = (int) $request->input('length', 25);
        $search  = trim((string) $request->input('search.value', ''));

        $total = $query->count();

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->orWhere('invoice_code', 'ILIKE', "%{$search}%")
                  ->orWhere('status', 'ILIKE', "%{$search}%");
            });
        }

        $filtered = $query->count();
        $invoices = $query->skip($start)->take($length)->get();

        $data = $invoices->map(function ($inv) {
            $statusClass = match($inv->status) {
                'confirmed'  => 'bg-success',
                'draft'      => 'bg-warning',
                'cancelled'  => 'bg-danger',
                default      => 'bg-secondary',
            };
            return [
                'id'           => $inv->id,
                'invoice_code' => $inv->invoice_code ?? '—',
                'invoice_date' => $inv->invoice_date ? \Carbon\Carbon::parse($inv->invoice_date)->format('d M Y') : '—',
                'total_amount' => number_format((float) $inv->total_amount, 2),
                'paid_amount'  => number_format((float) $inv->paid_amount, 2),
                'due_amount'   => number_format((float) ($inv->due_amount ?? 0), 2),
                'status'       => $inv->status ?? '—',
                'status_class' => $statusClass,
                'is_reversed'  => $inv->is_reversed,
                'salesman'     => $inv->salesman?->name ?? '—',
                'show_url'     => route('admin.sales-invoices.show', $inv->id),
            ];
        });

        return response()->json([
            'draw'            => $draw,
            'recordsTotal'    => $total,
            'recordsFiltered' => $filtered,
            'data'            => $data,
        ]);
    }

    /**
     * AJAX: Customer payments (DataTables server-side).
     * Route: GET admin/customers/{id}/payments-data
     */
    public function paymentsData(Request $request, int $id)
    {
        $customer = Customer::withTrashed()->findOrFail($id);

        $query = CustomerPayment::where('customer_id', $customer->id)
            ->with(['bank'])
            ->orderBy('payment_date', 'desc')
            ->orderBy('id', 'desc');

        if ($request->filled('type')) {
            $query->where('transaction_type', $request->input('type'));
        }

        $draw    = (int) $request->input('draw', 1);
        $start   = (int) $request->input('start', 0);
        $length  = (int) $request->input('length', 25);
        $search  = trim((string) $request->input('search.value', ''));

        $total = $query->count();

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->orWhere('payment_code', 'ILIKE', "%{$search}%")
                  ->orWhere('reference_no', 'ILIKE', "%{$search}%");
            });
        }

        $filtered = $query->count();
        $payments = $query->skip($start)->take($length)->get();

        $data = $payments->map(function ($pay) {
            $typeLabel = match($pay->transaction_type ?? 'receive') {
                'receive'   => 'Receive',
                'discount'  => 'Discount',
                'write_off' => 'Write-Off',
                'payment'   => 'Refund',
                default     => ucfirst($pay->transaction_type ?? ''),
            };
            $typeClass = match($pay->transaction_type ?? 'receive') {
                'receive'   => 'bg-success',
                'discount'  => 'bg-info',
                'write_off' => 'bg-warning',
                'payment'   => 'bg-danger',
                default     => 'bg-secondary',
            };
            $statusClass = match($pay->status ?? 'confirmed') {
                'confirmed' => 'bg-success',
                'draft'     => 'bg-warning',
                'cancelled' => 'bg-danger',
                default     => 'bg-secondary',
            };
            return [
                'id'               => $pay->id,
                'payment_code'     => $pay->payment_code ?? '—',
                'payment_date'     => $pay->payment_date ? \Carbon\Carbon::parse($pay->payment_date)->format('d M Y') : '—',
                'amount'           => number_format((float) $pay->amount, 2),
                'discount_amount'  => (float) ($pay->discount_amount ?? 0) > 0 ? number_format((float) $pay->discount_amount, 2) : '—',
                'payment_mode'     => ucfirst(str_replace('_', ' ', $pay->payment_mode ?? '')),
                'transaction_type' => $typeLabel,
                'type_class'       => $typeClass,
                'status'           => $pay->status ?? '—',
                'status_class'     => $statusClass,
                'is_reversed'      => $pay->is_reversed,
                'reference_no'     => $pay->reference_no ?? '—',
                'show_url'         => route('admin.customer-payments.show', $pay->id),
            ];
        });

        return response()->json([
            'draw'            => $draw,
            'recordsTotal'    => $total,
            'recordsFiltered' => $filtered,
            'data'            => $data,
        ]);
    }

    /**
     * AJAX: Customer sales returns (DataTables server-side).
     * Route: GET admin/customers/{id}/returns-data
     */
    public function returnsData(Request $request, int $id)
    {
        $customer = Customer::withTrashed()->findOrFail($id);

        $query = SalesReturn::where('customer_id', $customer->id)
            ->orderBy('return_date', 'desc')
            ->orderBy('id', 'desc');

        $draw    = (int) $request->input('draw', 1);
        $start   = (int) $request->input('start', 0);
        $length  = (int) $request->input('length', 25);
        $search  = trim((string) $request->input('search.value', ''));

        $total = $query->count();

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->orWhere('return_code', 'ILIKE', "%{$search}%")
                  ->orWhere('status', 'ILIKE', "%{$search}%");
            });
        }

        $filtered = $query->count();
        $returns  = $query->skip($start)->take($length)->get();

        $data = $returns->map(function ($ret) {
            $statusClass = match($ret->status ?? '') {
                'confirmed'  => 'bg-success',
                'draft'      => 'bg-warning',
                'cancelled'  => 'bg-danger',
                default      => 'bg-secondary',
            };
            return [
                'id'          => $ret->id,
                'return_code' => $ret->return_code ?? '—',
                'return_date' => $ret->return_date ? \Carbon\Carbon::parse($ret->return_date)->format('d M Y') : '—',
                'total_amount'=> number_format((float) $ret->total_amount, 2),
                'cogs_amount' => number_format((float) ($ret->cogs_amount ?? 0), 2),
                'status'      => $ret->status ?? '—',
                'status_class'=> $statusClass,
                'is_reversed' => $ret->is_reversed,
                'reason'      => $ret->reason ?? '—',
            ];
        });

        return response()->json([
            'draw'            => $draw,
            'recordsTotal'    => $total,
            'recordsFiltered' => $filtered,
            'data'            => $data,
        ]);
    }

    // ───────── CRUD Overrides ─────────

    /**
     * Override store(): auto-generate customer_code (CUS-YYYY-NNNNNN) when
     * the user didn't supply one. Format mirrors the legacy helper.
     *
     * Phase 17 refactor: now uses the centralized CodeGenerator service
     * instead of a per-controller generateCustomerCode() method.
     *
     * Phase 10: pre-normalize customer_code (uppercase + trim) BEFORE
     * validation so the unique rule is case-insensitive. Also normalize
     * free-text fields and only set is_active when explicitly provided
     * (matches Branch/Warehouse pattern — preserves DB default of true).
     */
    public function store(Request $request)
    {
        // Pre-fill the code so the unique rule sees it. We generate the next
        // sequence before validation so a collision can't sneak in.
        if (empty(trim((string) $request->input('customer_code')))) {
            $request->merge(['customer_code' => CodeGenerator::customerCode()]);
        }

        // Phase 10: normalize customer_code BEFORE validation for
        // case-insensitive unique check (same fix as Phase 8/9).
        if ($request->has('customer_code')) {
            $request->merge(['customer_code' => strtoupper(trim((string) $request->input('customer_code')))]);
        }
        if ($request->has('customer_name')) {
            $request->merge(['customer_name' => trim((string) $request->input('customer_name'))]);
        }

        $rules = $this->validationRules();
        $validated = $request->validate($rules);

        // Normalize remaining free-text fields (legacy behavior).
        if (isset($validated['phone']))   $validated['phone']   = trim($validated['phone']);
        if (isset($validated['mobile']))  $validated['mobile']  = trim($validated['mobile']);
        if (isset($validated['email']))   $validated['email']   = trim($validated['email']);
        if (isset($validated['address'])) $validated['address'] = trim($validated['address']);

        // Phase 10: only set is_active when the request explicitly provides
        // it. Otherwise let the DB default (true) apply — matches the
        // Branch/Warehouse pattern.
        if (!$request->has('is_active')) {
            unset($validated['is_active']);
        }

        try {
            $customer = Customer::create($validated);

            return redirect()
                ->route("{$this->routePrefix}.show", $customer)
                ->with('success', "{$this->label} created successfully.");
        } catch (\Throwable $e) {
            return back()
                ->withInput()
                ->with('error', "Failed to create {$this->label}: {$e->getMessage()}");
        }
    }

    /**
     * Override update(): pre-normalize customer_code BEFORE validation,
     * run deactivation safety check if is_active is being flipped false,
     * and avoid silently flipping is_active when the checkbox is omitted.
     */
    public function update(Request $request, int $id)
    {
        $item = Customer::findOrFail($id);

        // Phase 10: normalize customer_code BEFORE validation.
        if ($request->has('customer_code')) {
            $request->merge(['customer_code' => strtoupper(trim((string) $request->input('customer_code')))]);
        }
        if ($request->has('customer_name')) {
            $request->merge(['customer_name' => trim((string) $request->input('customer_name'))]);
        }

        $validated = $request->validate($this->validationRules($id));

        // Normalize remaining free-text fields.
        if (isset($validated['phone']))   $validated['phone']   = trim($validated['phone']);
        if (isset($validated['mobile']))  $validated['mobile']  = trim($validated['mobile']);
        if (isset($validated['email']))   $validated['email']   = trim($validated['email']);
        if (isset($validated['address'])) $validated['address'] = trim($validated['address']);

        // Phase 10: only flip is_active when explicitly provided.
        if (!$request->has('is_active')) {
            unset($validated['is_active']);
        }

        // Deactivation safety check — runs when is_active is being
        // explicitly set to false on an active customer.
        if (isset($validated['is_active']) && !$validated['is_active'] && $item->is_active) {
            $deactivationCheck = $this->canDeactivate($item);
            if (!$deactivationCheck['ok']) {
                return back()->withInput()->with('error', $deactivationCheck['message']);
            }
        }

        try {
            $item->update($validated);

            return redirect()
                ->route("{$this->routePrefix}.show", $item)
                ->with('success', "{$this->label} updated successfully.");
        } catch (\Throwable $e) {
            return back()
                ->withInput()
                ->with('error', "Failed to update {$this->label}: {$e->getMessage()}");
        }
    }

    /**
     * Phase 10: Can this customer be safely deactivated?
     * Mirrors the legacy canDeactivateCustomer() checks:
     *   1. Outstanding AR balance in customer_ledger (sum of debit - credit).
     *   2. Open (non-cancelled, non-reversed) sales invoices referencing
     *      this customer.
     *
     * @return array{ok: bool, message: string}
     */
    protected function canDeactivate($item): array
    {
        $customerId = $item->id;

        // 1. Outstanding AR balance — sum of debit minus sum of credit.
        // Any non-zero balance means there's an unsettled customer debt.
        $balance = DB::table('customer_ledger')
            ->where('customer_id', $customerId)
            ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) AS balance')
            ->value('balance');

        $balance = (float) $balance;

        // 2. Open sales invoices — not reversed, not cancelled.
        $openInvoices = DB::table('sales_invoices')
            ->where('customer_id', $customerId)
            ->where('is_reversed', false)
            ->whereNotIn('status', ['cancelled', 'reversed'])
            ->count();

        $parts = [];
        if (abs($balance) > 0.005) {
            $parts[] = "outstanding AR balance of " . number_format($balance, 2);
        }
        if ($openInvoices > 0) {
            $parts[] = "{$openInvoices} open sales invoice(s)";
        }

        if (!empty($parts)) {
            return [
                'ok' => false,
                'message' => "Cannot deactivate this customer. It has " . implode(', ', $parts)
                    . ". Please resolve them first.",
            ];
        }

        return ['ok' => true, 'message' => ''];
    }
}
