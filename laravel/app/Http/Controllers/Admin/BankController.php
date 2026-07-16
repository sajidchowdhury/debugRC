<?php

namespace App\Http\Controllers\Admin;

use App\Models\Bank;
use App\Models\BankLedgerMapping;
use App\Models\Ledger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Bank controller — Phase 4 master-data CRUD for the `banks` table.
 *
 * Cash book bank accounts, each optionally linked to a GL ledger of
 * nature `cash_bank` via the `bank_ledger_mappings` join table.
 *
 * Inherits full CRUD (index/create/show/edit/update/destroy/restore/audit)
 * from BaseMasterDataController; only store() and update() are overridden
 * so the BankLedgerMapping row stays in sync with the form's `ledger_id`.
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
     */
    protected function validationRules(?int $id = null): array
    {
        return [
            'bank_name'      => 'required|string|max:100',
            'account_number' => 'nullable|string|max:50',
            'account_holder' => 'nullable|string|max:100',
            'branch_name'    => 'nullable|string|max:100',
            'balance'        => 'nullable|numeric',
            'is_active'      => 'boolean',
            'ledger_id'      => 'nullable|exists:ledgers,id',
        ];
    }

    /**
     * Store: create the Bank row, then sync the BankLedgerMapping when
     * a `ledger_id` is provided in the request.
     */
    public function store(Request $request)
    {
        $validated = $request->validate($this->validationRules());

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
     */
    public function update(Request $request, int $id)
    {
        $bank = Bank::findOrFail($id);
        $validated = $request->validate($this->validationRules($id));

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
