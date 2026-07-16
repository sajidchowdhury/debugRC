<?php

namespace App\Http\Controllers\Admin;

use App\Models\Ledger;
use Illuminate\Http\Request;

/**
 * Ledger controller — Phase 4 master-data CRUD for the chart of accounts.
 *
 * Ledgers are hierarchical (parent_id), tagged with an account_type
 * (Asset/Liability/Equity/Income/Expense) and a ledger_nature (behavior
 * tag for the posting engine). 7 critical natures must each resolve to
 * exactly one active ledger — see Ledger::criticalNatures().
 *
 * Inherits full CRUD from BaseMasterDataController.
 */
class LedgerController extends BaseMasterDataController
{
    public function __construct()
    {
        $this->modelClass  = Ledger::class;
        $this->label       = 'Ledger';
        $this->routePrefix = 'admin.ledgers';
        $this->viewDir     = 'admin.ledgers';
        $this->searchFields = ['ledger_code', 'ledger_name'];
    }

    /**
     * Hero stats: active count, control accounts, by-type breakdown.
     */
    protected function indexStats(): array
    {
        return [
            'active'          => Ledger::active()->count(),
            'control_accounts' => Ledger::active()->where('is_control_account', true)->count(),
            'by_type'         => Ledger::active()
                ->selectRaw('account_type, count(*) as count')
                ->groupBy('account_type')
                ->pluck('count', 'account_type'),
        ];
    }

    /**
     * Eager-load the parent ledger on the index listing.
     */
    protected function indexWith(): array
    {
        return ['parent'];
    }

    /**
     * Detail with parent + journal lines for the show page summary.
     */
    protected function detailWith(): array
    {
        return ['parent', 'children', 'journalLines'];
    }

    /**
     * Form dropdown data: top-level parents, account types, natures.
     */
    protected function formData(): array
    {
        return [
            'parents' => Ledger::active()
                ->where(function ($q) {
                    $q->whereNull('parent_id')->orWhere('parent_id', 0);
                })
                ->orderBy('ledger_name')
                ->get(),
            'accountTypes' => ['Asset', 'Liability', 'Equity', 'Income', 'Expense'],
            'natures'      => [
                'cash_bank',
                'ar',
                'ap',
                'inventory',
                'sales',
                'cogs',
                'retained_earnings',
                'sales_return',
                'purchase_return',
                'damage_loss',
                'adjustment_gain',
                'adjustment_loss',
                'interbranch_receivable',
                'interbranch_payable',
                'salary_expense',
                'other_income',
                'other_expense',
                'transfer',
            ],
        ];
    }

    /**
     * Validation rules for create/update.
     */
    protected function validationRules(?int $id = null): array
    {
        return [
            'ledger_code'          => 'required|string|max:20|unique:ledgers,ledger_code,' . $id,
            'ledger_name'          => 'required|string|max:100',
            'parent_id'            => 'nullable|integer',
            'account_type'         => 'required|in:Asset,Liability,Equity,Income,Expense',
            'ledger_nature'        => 'nullable|string|max:50',
            'is_control_account'   => 'boolean',
            'control_account_type' => 'nullable|string|max:30',
            'is_active'            => 'boolean',
            'opening_balance'      => 'nullable|numeric',
            'sort_order'           => 'nullable|integer',
        ];
    }
}
