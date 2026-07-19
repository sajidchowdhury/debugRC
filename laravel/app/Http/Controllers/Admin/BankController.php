<?php

namespace App\Http\Controllers\Admin;

use App\Models\Bank;
use App\Models\BankLedgerMapping;
use App\Models\Ledger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Bank controller — Phase 4 master-data CRUD for the `banks` table + Phase 13
 * hardening.
 *
 * Cash book bank accounts, each optionally linked to a GL ledger of
 * nature `cash_bank` via the `bank_ledger_mappings` join table.
 *
 * Inherits full CRUD (index/create/show/edit/update/destroy/restore/audit)
 * from BaseMasterDataController; only store() and update() are overridden
 * so the BankLedgerMapping row stays in sync with the form's `ledger_id`.
 *
 * Phase 13 hardening (mirrors Branch/Warehouse/Product/Customer/Supplier/
 * Employee):
 *  - store()/update(): pre-normalize bank_name (trim) + account_number
 *    (uppercase + trim) BEFORE validation for case-insensitive unique check.
 *  - validationRules(): default $id to 0 when null (matches the rest of the
 *    module suite — avoids "invalid input syntax for type integer" on store).
 *  - validationRules(): added unique rule on account_number (case-insensitive
 *    after normalization).
 *  - store()/update(): only set is_active when explicitly provided; otherwise
 *    let the DB default (true) apply on store, and preserve existing value
 *    on update.
 *  - update(): runs canDeactivate() safety check before flipping is_active=false.
 *  - canDeactivate(): 2 safety checks — non-zero balance AND active bank_ledger
 *    mapping (deactivating a bank with money or an active GL link would
 *    orphan funds / break reconciliation).
 *  - store(): set created_by from Auth::id() (matches BaseMasterDataController
 *    pattern).
 */
class BankController extends BaseMasterDataController
{
    public function __construct()
    {
        $this->modelClass  = Bank::class;
        $this->label       = 'Bank';
        $this->routePrefix = 'admin.banks';
        $this->viewDir     = 'admin.banks';
        $this->searchFields = ['bank_name', 'account_number', 'account_holder'];
    }

    /**
     * Hero stats for the index page.
     */
    protected function indexStats(): array
    {
        return [
            'active'        => Bank::active()->count(),
            'total_balance' => (float) Bank::active()->sum('balance'),
        ];
    }

    /**
     * Eager-load the linked GL ledger on the index listing.
     */
    protected function indexWith(): array
    {
        return ['ledger'];
    }

    /**
     * Eager-load ledger + mapping on the detail / edit screens.
     */
    protected function detailWith(): array
    {
        return ['ledger', 'ledgerMapping'];
    }

    /**
     * Form dropdown data: GL ledgers of nature `cash_bank`.
     */
    protected function formData(): array
    {
        return [
            'ledgers' => Ledger::active()
                ->where('ledger_nature', 'cash_bank')
                ->orderBy('ledger_name')
                ->get(),
        ];
    }

    /**
     * Validation rules for create/update.
     *
     * Phase 13: default $id to 0 when null (matches Branch/Warehouse/Customer/
     * Supplier/Employee pattern and avoids "invalid input syntax for type
     * integer" on store when validationRules() is called without an argument).
     *
     * Phase 13: added unique rule on account_number (case-insensitive after
     * pre-validation uppercase+trim normalization).
     */
    protected function validationRules(?int $id = null): array
    {
        $id = $id ?? 0;

        return [
            'bank_name'      => ['required', 'string', 'max:100'],
            'account_number' => ['nullable', 'string', 'max:50', "unique:banks,account_number,{$id}"],
            'account_holder' => ['nullable', 'string', 'max:100'],
            'branch_name'    => ['nullable', 'string', 'max:100'],
            'balance'        => ['nullable', 'numeric'],
            'is_active'      => ['boolean'],
            'ledger_id'      => ['nullable', 'exists:ledgers,id'],
        ];
    }

    /**
     * Store: create the Bank row, then sync the BankLedgerMapping when
     * a `ledger_id` is provided in the request.
     *
     * Phase 13 overrides:
     *  - pre-normalize bank_name (trim) + account_number (uppercase + trim)
     *  - only set is_active when explicitly provided (preserve DB default true)
     *  - set created_by from Auth::id()
     */
    public function store(Request $request)
    {
        // Phase 13: pre-normalize bank_name + account_number BEFORE validation.
        if ($request->has('bank_name')) {
            $request->merge(['bank_name' => trim((string) $request->input('bank_name'))]);
        }
        if ($request->has('account_number')) {
            $request->merge(['account_number' => strtoupper(trim((string) $request->input('account_number')))]);
        }

        $validated = $request->validate($this->validationRules());

        // Phase 13: only set is_active when the request explicitly provides it.
        if (!$request->has('is_active')) {
            unset($validated['is_active']);
        }

        // Set created_by from the authenticated user (banks has the column).
        if (!isset($validated['created_by'])) {
            $validated['created_by'] = Auth::id();
        }

        try {
            return DB::transaction(function () use ($request, $validated) {
                $bank = Bank::create($validated);
                $this->syncLedgerMapping($bank, $request->input('ledger_id'));

                return redirect()->route("{$this->routePrefix}.show", $bank)
                    ->with('success', "{$this->label} created successfully.");
            });
        } catch (\Throwable $e) {
            return back()->withInput()
                ->with('error', "Failed to create {$this->label}: {$e->getMessage()}");
        }
    }

    /**
     * Update: persist the Bank row, then sync the BankLedgerMapping when
     * a `ledger_id` is provided (or cleared) in the request.
     *
     * Phase 13 overrides:
     *  - pre-normalize bank_name (trim) + account_number (uppercase + trim)
     *  - only flip is_active when explicitly provided (preserve existing value)
     *  - run canDeactivate() safety check when is_active is being flipped
     *    from true → false
     */
    public function update(Request $request, int $id)
    {
        $bank = Bank::findOrFail($id);

        // Phase 13: pre-normalize bank_name + account_number BEFORE validation.
        if ($request->has('bank_name')) {
            $request->merge(['bank_name' => trim((string) $request->input('bank_name'))]);
        }
        if ($request->has('account_number')) {
            $request->merge(['account_number' => strtoupper(trim((string) $request->input('account_number')))]);
        }

        $validated = $request->validate($this->validationRules($id));

        // Phase 13: only flip is_active when explicitly provided.
        if (!$request->has('is_active')) {
            unset($validated['is_active']);
        }

        // Deactivation safety check — runs when is_active is being
        // explicitly set to false on an active bank.
        if (isset($validated['is_active']) && !$validated['is_active'] && $bank->is_active) {
            $deactivationCheck = $this->canDeactivate($bank);
            if (!$deactivationCheck['ok']) {
                return back()->withInput()->with('error', $deactivationCheck['message']);
            }
        }

        try {
            return DB::transaction(function () use ($bank, $request, $validated) {
                $bank->update($validated);
                $this->syncLedgerMapping($bank, $request->input('ledger_id'));

                return redirect()->route("{$this->routePrefix}.show", $bank)
                    ->with('success', "{$this->label} updated successfully.");
            });
        } catch (\Throwable $e) {
            return back()->withInput()
                ->with('error', "Failed to update {$this->label}: {$e->getMessage()}");
        }
    }

    /**
     * Phase 13: Can this bank be safely deactivated?
     *
     * Mirrors the legacy BankModel guards + the audit doc Phase 4 mandate
     * ("Toggle action — restore with deactivation safety (non-zero balance
     * check)"):
     *   1. Non-zero balance — deactivating a bank with money sitting in it
     *      would orphan funds (no way to reconcile back to GL).
     *   2. Active bank_ledger_mapping — bank is currently linked to a GL
     *      ledger of nature `cash_bank`. Deactivating would break cash-book
     *      reconciliation and any pending journal posts that reference it.
     *
     * Historical references (customer_payments, supplier_payments,
     * money_transfers, other_incomes, other_expenses) are NOT blockers —
     * they are FK-pointing rows whose existence doesn't prevent soft-delete
     * (the bank row stays in the table, only deleted_at is set).
     *
     * @param  Bank  $item
     * @return array{ok: bool, message: string}
     */
    protected function canDeactivate($item): array
    {
        $bankId = $item->id;

        // 1. Non-zero balance — funds would be orphaned.
        $balance = (float) ($item->balance ?? 0);

        // 2. Active bank_ledger_mapping — GL link would break.
        $mappingCount = DB::table('bank_ledger_mappings')
            ->where('bank_id', $bankId)
            ->count();

        $parts = [];
        if (abs($balance) > 0.005) {
            $parts[] = "outstanding balance of " . number_format($balance, 2);
        }
        if ($mappingCount > 0) {
            $parts[] = "{$mappingCount} active GL ledger mapping(s)";
        }

        if (!empty($parts)) {
            return [
                'ok' => false,
                'message' => "Cannot deactivate this bank. It has " . implode(', ', $parts)
                    . ". Please resolve them first.",
            ];
        }

        return ['ok' => true, 'message' => ''];
    }

    /**
     * Keep the bank_ledger_mappings row in sync with the form's ledger_id.
     * - Empty ledger_id → delete any existing mapping.
     * - Non-empty ledger_id → update-or-create so we never duplicate.
     */
    private function syncLedgerMapping(Bank $bank, ?string $ledgerId): void
    {
        $ledgerId = $ledgerId !== '' && $ledgerId !== null ? (int) $ledgerId : null;

        if ($ledgerId === null) {
            BankLedgerMapping::where('bank_id', $bank->id)->delete();
            return;
        }

        BankLedgerMapping::updateOrCreate(
            ['bank_id' => $bank->id],
            ['ledger_id' => $ledgerId],
        );
    }
}
