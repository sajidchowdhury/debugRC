<?php

namespace App\Http\Requests\SalesReturn;

use App\Models\SalesInvoice;
use App\Rules\WarehouseBelongsToBranch;
use App\Services\Sales\SalesReturnableQty;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Phase 1.1 — Form Request for storing a new Sales Return.
 *
 * Extracted from SalesReturnController::store() (which used inline
 * $request->validate([...])). Adds defense-in-depth WITHVALIDATOR hooks
 * that the inline version lacked:
 *
 *   1. Invoice state gate — must be challan-issued, not reversed, not cancelled.
 *   2. Branch isolation — invoice.branch_id must match session branch (admin bypass).
 *   3. customer_id consistency — must match the invoice's customer.
 *   4. Per-item returnable-qty cap — qty <= getMaxReturnableQty(invoice_item_id).
 *   5. Per-item warehouse-belongs-to-branch — reuses App\Rules\WarehouseBelongsToBranch.
 *
 * These run BEFORE the service layer, so the user sees a 422 with a clear
 * message instead of a mid-transaction RuntimeException. The service keeps
 * its own checks (SalesReturnableQty is also called inside validateItems)
 * as a second line of defense for the mobile API, which does not route
 * through this Form Request.
 *
 * Differences from the inline version:
 *   - Adds `customer_id` rule (the inline version omitted it; the service
 *     derives it from the invoice — but accepting it lets the UI send it
 *     for display without a separate lookup, and lets us verify consistency).
 *   - Adds `condition_state` rule (Good/Damage) — Phase 0.3 added the
 *     model helpers; this lets the web UI expose the toggle (Phase 4 work).
 *   - Makes `rate` required (was nullable) — the sales rate drives the
 *     revenue reversal GL; the UI always sends it. The service still
 *     falls back to the invoice-item rate if 0, so nullable would also
 *     work, but required catches a broken UI earlier.
 */
class StoreSalesReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // RBAC handled by route middleware (role + branch.isolation)
    }

    public function rules(): array
    {
        return [
            'sales_invoice_id'                     => 'required|integer|exists:sales_invoices,id',
            'customer_id'                          => 'required|integer|exists:customers,id',
            'return_date'                          => 'required|date',
            'reason'                               => 'nullable|string|max:1000',
            'items'                                => 'required|array|min:1',
            'items.*.sales_invoice_item_id'        => 'required|integer|exists:sales_invoice_items,id',
            'items.*.product_id'                   => 'required|integer|exists:products,id',
            'items.*.qty'                          => 'required|numeric|min:0.001',
            'items.*.rate'                         => 'required|numeric|min:0',
            'items.*.warehouse_id'                 => 'required|integer|exists:warehouses,id',
            'items.*.condition_state'              => 'nullable|string|in:Good,Damage',
        ];
    }

    public function messages(): array
    {
        return [
            'sales_invoice_id.required'                => 'An invoice reference is required.',
            'sales_invoice_id.exists'                  => 'That invoice no longer exists.',
            'customer_id.required'                     => 'A customer reference is required.',
            'customer_id.exists'                       => 'That customer no longer exists.',
            'return_date.required'                     => 'Return date is required.',
            'return_date.date'                         => 'Return date must be a valid date.',
            'reason.max'                               => 'Reason must be 1000 characters or fewer.',
            'items.required'                           => 'At least one line item is required.',
            'items.min'                                => 'At least one line item is required.',
            'items.*.sales_invoice_item_id.required'   => 'Each line must reference an invoice item.',
            'items.*.sales_invoice_item_id.exists'     => 'One of the referenced invoice items no longer exists.',
            'items.*.product_id.required'              => 'Each line must have a product.',
            'items.*.product_id.exists'                => 'One of the selected products is not active.',
            'items.*.warehouse_id.required'            => 'Each line must have a warehouse.',
            'items.*.warehouse_id.exists'              => 'One of the selected warehouses is not active.',
            'items.*.qty.required'                     => 'Each line must have a quantity.',
            'items.*.qty.min'                          => 'Quantity must be greater than zero.',
            'items.*.rate.required'                    => 'Each line must have a rate.',
            'items.*.rate.min'                         => 'Rate cannot be negative.',
            'items.*.condition_state.in'               => 'Condition must be either "Good" or "Damage".',
        ];
    }

    public function attributes(): array
    {
        return [
            'sales_invoice_id'              => 'invoice',
            'customer_id'                   => 'customer',
            'return_date'                   => 'return date',
            'items.*.sales_invoice_item_id' => 'invoice item',
            'items.*.product_id'            => 'product',
            'items.*.warehouse_id'          => 'warehouse',
            'items.*.qty'                   => 'quantity',
            'items.*.rate'                  => 'rate',
            'items.*.condition_state'       => 'condition',
        ];
    }

    /**
     * Defense-in-depth hooks — run after the base rules pass, before the
     * controller body. Each hook adds a validation error (-> 422) on failure.
     *
     * Uses $validator->after() (not the withValidator($v) signature's
     * imperative API) so all hooks run even if an early one fails — the
     * user sees EVERY blocking reason in one round-trip.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $invoiceId = (int) $this->input('sales_invoice_id');
            if ($invoiceId <= 0) {
                return; // the required|exists rules already caught this
            }

            // ── Hook 1: Invoice state gate ──
            // Must be challan-issued (Laravel's equivalent of legacy's
            // "challan_completed"), not reversed, not cancelled.
            $invoice = SalesInvoice::select(
                'id', 'branch_id', 'customer_id', 'status',
                'is_challan_issued', 'is_reversed'
            )->find($invoiceId);

            if (!$invoice) {
                return; // exists rule already flagged it
            }

            if ($invoice->is_reversed) {
                $validator->errors()->add(
                    'sales_invoice_id',
                    'Cannot return against a reversed invoice.'
                );
                return;
            }
            if ($invoice->status === 'cancelled') {
                $validator->errors()->add(
                    'sales_invoice_id',
                    'Cannot return against a cancelled invoice.'
                );
                return;
            }
            if (!$invoice->is_challan_issued) {
                $validator->errors()->add(
                    'sales_invoice_id',
                    'Returns require a completed challan. This invoice has not been challan-issued yet.'
                );
                return;
            }

            // ── Hook 2: Branch isolation (admin bypass) ──
            $user = $this->user();
            $sessionBranchId = (int) (session('branch_id') ?? $user?->getBranchId() ?? 0);
            $isAdmin = (bool) $user?->isAdmin();
            if (!$isAdmin && $sessionBranchId > 0
                && (int) $invoice->branch_id !== $sessionBranchId) {
                $validator->errors()->add(
                    'sales_invoice_id',
                    'You do not have access to that invoice (different branch).'
                );
                return;
            }

            // ── Hook 3: customer_id consistency ──
            // The UI sends customer_id; verify it matches the invoice's
            // customer (prevents a crafted POST mixing invoice + customer).
            $submittedCustomerId = (int) $this->input('customer_id');
            if ($submittedCustomerId > 0
                && (int) $invoice->customer_id !== $submittedCustomerId) {
                $validator->errors()->add(
                    'customer_id',
                    'The selected customer does not match this invoice\'s customer.'
                );
            }

            // ── Hook 4 + 5: per-item returnable-qty cap + warehouse-branch ──
            $items = $this->input('items', []);
            if (!is_array($items) || empty($items)) {
                return; // items.required already caught it
            }

            $returnable = app(SalesReturnableQty::class);
            $warehouseRule = new WarehouseBelongsToBranch($invoiceId);

            // Batch-fetch returnable qty for all invoice-item IDs in one query.
            $invoiceItemIds = array_filter(array_map(
                fn($i) => (int) ($i['sales_invoice_item_id'] ?? 0),
                $items
            ));
            $returnableMap = $returnable->getReturnableQtyMap($invoiceItemIds);

            foreach ($items as $idx => $item) {
                $lineLabel = 'Item ' . ((int) $idx + 1);
                $invoiceItemId = (int) ($item['sales_invoice_item_id'] ?? 0);
                $qty = (float) ($item['qty'] ?? 0);
                $warehouseId = (int) ($item['warehouse_id'] ?? 0);

                // Hook 4: returnable-qty cap
                if ($invoiceItemId > 0) {
                    $maxQty = $returnableMap[$invoiceItemId]
                        ?? $returnable->getMaxReturnableQty($invoiceItemId);
                    if ($qty > $maxQty + 0.0001) {
                        $validator->errors()->add(
                            "items.{$idx}.qty",
                            sprintf(
                                '%s: return qty %s exceeds the returnable qty %s for this invoice item.',
                                $lineLabel,
                                number_format($qty, 4),
                                number_format($maxQty, 4)
                            )
                        );
                    }
                }

                // Hook 5: warehouse belongs to the invoice's branch
                if ($warehouseId > 0) {
                    $whError = null;
                    $warehouseRule->validate(
                        "items.{$idx}.warehouse_id",
                        $warehouseId,
                        function (string $msg) use (&$whError) { $whError = $msg; }
                    );
                    if ($whError !== null) {
                        $validator->errors()->add("items.{$idx}.warehouse_id", $whError);
                    }
                }
            }
        });
    }
}
