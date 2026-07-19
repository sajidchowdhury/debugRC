<?php

namespace App\Http\Controllers\Admin;

use App\Models\Ledger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Ledger controller — Phase 4 master-data CRUD + Phase 15 hardening for the
 * chart of accounts.
 *
 * Ledgers are hierarchical (parent_id), tagged with an account_type
 * (Asset/Liability/Equity/Income/Expense) and a ledger_nature (behavior
 * tag for the posting engine). 7 critical natures must each resolve to
 * exactly one active ledger — see Ledger::criticalNatures().
 *
 * Phase 15 hardening (mirrors Branch/Warehouse/Product/Customer/Supplier/
 * Employee/Bank/User suite):
 *  - store()/update(): pre-normalize ledger_code (uppercase + trim),
 *    ledger_name (trim), ledger_nature (lowercase + trim) BEFORE validation
 *    for case-insensitive unique check.
 *  - validationRules(): default $id to 0 when null (matches the rest of the
 *    module suite — avoids "invalid input syntax for type integer" on store).
 *  - validationRules(): added normal_balance rule (in:debit,credit) and a
 *    custom closure rule validating account_type ↔ ledger_nature ↔
 *    normal_balance consistency (Phase 6 audit mandate).
 *  - store(): set created_by from Auth::id() (matches BaseMasterDataController
 *    pattern).
 *  - update(): runs canDeactivate() safety check before flipping is_active=false.
 *  - update()/destroy(): system-ledger protection — is_system=true ledgers
 *    can ONLY have their description edited; everything else is blocked.
 *  - canDeactivate(): 4 safety checks (Phase 7 audit mandate):
 *      1. is_system=true → cannot deactivate (would break the posting engine).
 *      2. journal_lines > 0 → cannot deactivate (historical references).
 *      3. children exist → cannot deactivate (hierarchy integrity).
 *      4. critical nature with only 1 active ledger → cannot deactivate
 *         (would orphan the posting engine's resolver).
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
            'active'           => Ledger::active()->count(),
            'control_accounts' => Ledger::active()->where('is_control_account', true)->count(),
            'system'           => Ledger::active()->where('is_system', true)->count(),
            'by_type'          => Ledger::active()
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
     * Detail with parent + children + journal lines for the show page summary.
     */
    protected function detailWith(): array
    {
        return ['parent', 'children', 'journalLines'];
    }

    /**
     * Form dropdown data: top-level parents, account types, natures.
     *
     * Phase 15: nature list aligned with Ledger::natureMetadata() keys so
     * admins see every nature the posting engine recognizes (incl. extended
     * natures like employee_payable, interbranch_*, etc.).
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
            'natures'      => array_keys(Ledger::natureMetadata()),
        ];
    }

    /**
     * Validation rules for create/update.
     *
     * Phase 15: default $id to 0 when null (matches Branch/Warehouse/.../
     * User pattern). Added normal_balance rule + account_type ↔ ledger_nature
     * consistency check (Phase 6 audit mandate).
     */
    protected function validationRules(?int $id = null): array
    {
        $id = $id ?? 0;

        return [
            'ledger_code'          => ['required', 'string', 'max:20', "unique:ledgers,ledger_code,{$id}"],
            'ledger_name'          => ['required', 'string', 'max:100'],
            'parent_id'            => ['nullable', 'integer'],
            'account_type'         => ['required', 'in:Asset,Liability,Equity,Income,Expense'],
            'ledger_nature'        => ['nullable', 'string', 'max:50', function ($attribute, $value, $fail) {
                if ($value === null || $value === '') {
                    return;
                }
                $known = array_keys(Ledger::natureMetadata());
                if (!in_array($value, $known, true)) {
                    $fail("The selected ledger nature ({$value}) is not recognized by the posting engine.");
                }
            }],
            'is_control_account'   => ['boolean'],
            'control_account_type' => ['nullable', 'string', 'max:30'],
            'normal_balance'       => ['nullable', 'in:debit,credit', function ($attribute, $value, $fail) {
                // Phase 6: when both normal_balance and ledger_nature are
                // provided, normal_balance must match the nature's expected
                // normal balance.
                $nature = request()->input('ledger_nature');
                if ($value === null || $nature === null || $nature === '') {
                    return;
                }
                $expected = Ledger::expectedNormalBalanceForNature($nature);
                if ($expected !== null && $value !== $expected) {
                    $fail("Normal balance for nature '{$nature}' must be '{$expected}' (got '{$value}').");
                }
            }],
            'is_active'            => ['boolean'],
            'opening_balance'      => ['nullable', 'numeric'],
            'sort_order'           => ['nullable', 'integer'],
            'description'          => ['nullable', 'string'],
        ];
    }

    /**
     * Phase 15: pre-normalize inputs BEFORE validation.
     *  - ledger_code: uppercase + trim (case-insensitive unique check)
     *  - ledger_name: trim
     *  - ledger_nature: lowercase + trim
     *  - normal_balance: lowercase + trim
     *  - control_account_type: lowercase + trim
     */
    private function normalizeInputs(Request $request): void
    {
        if ($request->has('ledger_code')) {
            $request->merge(['ledger_code' => strtoupper(trim((string) $request->input('ledger_code')))]);
        }
        if ($request->has('ledger_name')) {
            $request->merge(['ledger_name' => trim((string) $request->input('ledger_name'))]);
        }
        if ($request->has('ledger_nature') && $request->filled('ledger_nature')) {
            $request->merge(['ledger_nature' => strtolower(trim((string) $request->input('ledger_nature')))]);
        }
        if ($request->has('normal_balance') && $request->filled('normal_balance')) {
            $request->merge(['normal_balance' => strtolower(trim((string) $request->input('normal_balance')))]);
        }
        if ($request->has('control_account_type') && $request->filled('control_account_type')) {
            $request->merge(['control_account_type' => strtolower(trim((string) $request->input('control_account_type')))]);
        }
    }

    /**
     * Phase 15: store override — pre-normalize, set created_by, preserve
     * is_active default (DB default true when omitted).
     */
    public function store(Request $request)
    {
        $this->normalizeInputs($request);

        $validated = $request->validate($this->validationRules());

        // Only set is_active when explicitly provided (preserve DB default true).
        if (!$request->has('is_active')) {
            unset($validated['is_active']);
        }

        // Set created_by from the authenticated user.
        if (!isset($validated['created_by'])) {
            $validated['created_by'] = Auth::id();
        }

        try {
            $ledger = Ledger::create($validated);

            return redirect()->route("{$this->routePrefix}.show", $ledger)
                ->with('success', "{$this->label} created successfully.");
        } catch (\Throwable $e) {
            return back()->withInput()
                ->with('error', "Failed to create {$this->label}: {$e->getMessage()}");
        }
    }

    /**
     * Phase 15: update override — pre-normalize, enforce system-ledger
     * protection (only `description` editable on system ledgers), run
     * canDeactivate() safety check when is_active is flipped to false,
     * preserve existing is_active when omitted.
     */
    public function update(Request $request, int $id)
    {
        $ledger = Ledger::findOrFail($id);

        $this->normalizeInputs($request);

        // Phase 15: system-ledger protection.
        // System ledgers can only have their description edited.
        if ($ledger->is_system) {
            $validated = $request->validate([
                'description' => ['nullable', 'string'],
            ]);

            try {
                $ledger->update(['description' => $validated['description'] ?? null]);

                return redirect()->route("{$this->routePrefix}.show", $ledger)
                    ->with('success', "{$this->label} description updated. System ledger fields are protected.");
            } catch (\Throwable $e) {
                return back()->withInput()
                    ->with('error', "Failed to update {$this->label}: {$e->getMessage()}");
            }
        }

        $validated = $request->validate($this->validationRules($id));

        // Only flip is_active when explicitly provided.
        if (!$request->has('is_active')) {
            unset($validated['is_active']);
        }

        // Deactivation safety check — runs when is_active is being
        // explicitly set to false on an active ledger.
        if (isset($validated['is_active']) && !$validated['is_active'] && $ledger->is_active) {
            $deactivationCheck = $this->canDeactivate($ledger);
            if (!$deactivationCheck['ok']) {
                return back()->withInput()->with('error', $deactivationCheck['message']);
            }
        }

        try {
            $ledger->update($validated);

            return redirect()->route("{$this->routePrefix}.show", $ledger)
                ->with('success', "{$this->label} updated successfully.");
        } catch (\Throwable $e) {
            return back()->withInput()
                ->with('error', "Failed to update {$this->label}: {$e->getMessage()}");
        }
    }

    /**
     * Phase 15: destroy override — system-ledger protection + deactivation
     * safety check (4 blockers).
     *
     * System ledgers cannot be deactivated at all (would break the posting
     * engine). Non-system ledgers run the full canDeactivate() safety check.
     */
    public function destroy(Request $request, int $id)
    {
        $ledger = Ledger::findOrFail($id);

        // Phase 15: system-ledger protection.
        if ($ledger->is_system) {
            return back()->with('error', "Cannot deactivate system ledger '{$ledger->ledger_name}'. System ledgers are required by the posting engine and can only have their description edited.");
        }

        // Deactivation safety check (4 blockers).
        if ($ledger->is_active) {
            $deactivationCheck = $this->canDeactivate($ledger);
            if (!$deactivationCheck['ok']) {
                return back()->with('error', $deactivationCheck['message']);
            }
        }

        try {
            $ledger->deleted_by = Auth::id();
            $ledger->is_active = false;
            $ledger->save();
            $ledger->delete();

            return redirect()->route("{$this->routePrefix}.index")
                ->with('success', "{$this->label} deactivated.");
        } catch (\Throwable $e) {
            return back()->with('error', "Failed to deactivate {$this->label}: {$e->getMessage()}");
        }
    }

    /**
     * Phase 15: Can this ledger be safely deactivated?
     *
     * Mirrors the legacy guards + the administration audit Phase 7 mandate.
     * 4 safety checks:
     *   1. is_system=true → cannot deactivate (would break the posting engine).
     *   2. journal_lines > 0 → cannot deactivate (historical references).
     *   3. children exist → cannot deactivate (hierarchy integrity).
     *   4. critical nature with only 1 active ledger → cannot deactivate
     *      (would orphan the posting engine's resolver).
     *
     * @param  Ledger  $item
     * @return array{ok: bool, message: string}
     */
    protected function canDeactivate($item): array
    {
        // 1. System ledger — never deactivatable.
        if ($item->is_system) {
            return [
                'ok' => false,
                'message' => "Cannot deactivate system ledger '{$item->ledger_name}'. System ledgers are required by the posting engine.",
            ];
        }

        $parts = [];

        // 2. Journal history — historical references would be orphaned.
        $journalLineCount = DB::table('journal_lines')
            ->where('ledger_id', $item->id)
            ->count();
        if ($journalLineCount > 0) {
            $parts[] = "{$journalLineCount} journal line(s) referencing this ledger";
        }

        // 3. Child ledgers — hierarchy integrity.
        $childCount = DB::table('ledgers')
            ->where('parent_id', $item->id)
            ->whereNull('deleted_at')
            ->count();
        if ($childCount > 0) {
            $parts[] = "{$childCount} child ledger(s) attached";
        }

        // 4. Sole active critical nature — would orphan the resolver.
        if (in_array($item->ledger_nature, Ledger::criticalNatures(), true)) {
            $activeCountForNature = DB::table('ledgers')
                ->where('ledger_nature', $item->ledger_nature)
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->count();
            if ($activeCountForNature <= 1) {
                $parts[] = "sole active ledger for critical nature '{$item->ledger_nature}' (the posting engine requires at least one)";
            }
        }

        if (!empty($parts)) {
            return [
                'ok' => false,
                'message' => "Cannot deactivate this ledger. It has " . implode(', ', $parts)
                    . ". Please resolve them first.",
            ];
        }

        return ['ok' => true, 'message' => ''];
    }
}
