<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MoneyTransfer;
use App\Services\Accounting\MoneyTransferService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Money Transfer Controller — Phase 4 (Accounts Sub-Ledger).
 *
 * Handles money transfers between cash and bank accounts.
 *
 * Transfer types:
 *   - cash_to_bank:  Cash → Bank
 *   - bank_to_cash:  Bank → Cash
 *   - cash_to_cash:  Inter-branch cash transfer
 *   - bank_to_bank:  Bank → Bank
 */
class MoneyTransferController extends Controller
{
    public function __construct(
        private MoneyTransferService $service
    ) {}

    public function index(Request $request)
    {
        $filters = [
            'from_date'     => $request->input('from_date', ''),
            'to_date'       => $request->input('to_date', ''),
            'transfer_type' => $request->input('transfer_type', ''),
            'search'        => $request->input('search', ''),
        ];

        $listBranchId = $this->resolveListBranchId();
        $transfers = $this->service->getFilteredTransfers($filters, $listBranchId);
        $stats = $this->service->getStats($listBranchId);

        $branches = \App\Models\Branch::active()->orderBy('branch_name')->get();
        $banks = \App\Models\Bank::active()->orderBy('bank_name')->get();

        return view('admin.money-transfers.index', [
            'title'     => 'Money Transfers',
            'transfers' => $transfers,
            'stats'     => $stats,
            'branches'  => $branches,
            'banks'     => $banks,
            'filters'   => $filters,
        ]);
    }

    public function create(Request $request)
    {
        $branches = \App\Models\Branch::active()->orderBy('branch_name')->get();
        $banks = \App\Models\Bank::active()->orderBy('bank_name')->get();
        $transferType = $request->input('transfer_type', 'cash_to_bank');

        return view('admin.money-transfers.create', [
            'title'         => 'New Money Transfer',
            'branches'      => $branches,
            'banks'         => $banks,
            'transferType'  => $transferType,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'transfer_type'  => 'required|in:cash_to_bank,bank_to_cash,cash_to_cash,bank_to_bank',
            'from_branch_id' => 'required|integer|exists:branches,id',
            'to_branch_id'   => 'required|integer|exists:branches,id',
            'from_bank_id'   => 'nullable|integer|exists:banks,id',
            'to_bank_id'     => 'nullable|integer|exists:banks,id',
            'amount'         => 'required|numeric|min:0.01',
            'transfer_date'  => 'required|date',
            'notes'          => 'nullable|string|max:500',
        ]);

        try {
            $transfer = $this->service->createTransfer([
                'transfer_type'  => $validated['transfer_type'],
                'from_branch_id' => $validated['from_branch_id'],
                'to_branch_id'   => $validated['to_branch_id'],
                'from_bank_id'   => $validated['from_bank_id'] ?? null,
                'to_bank_id'     => $validated['to_bank_id'] ?? null,
                'amount'         => $validated['amount'],
                'transfer_date'  => $validated['transfer_date'],
                'notes'          => $validated['notes'] ?? '',
                'created_by'     => auth()->id(),
            ]);

            $typeLabels = [
                'cash_to_bank' => 'Cash to Bank',
                'bank_to_cash' => 'Bank to Cash',
                'cash_to_cash' => 'Cash to Cash',
                'bank_to_bank' => 'Bank to Bank',
            ];
            $typeLabel = $typeLabels[$validated['transfer_type']] ?? 'Transfer';

            $successMessage = "Transfer {$transfer->transfer_code} recorded ({$typeLabel}). GL posted + bank balance updated.";

            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'status'        => 'success',
                    'transfer_id'   => $transfer->id,
                    'transfer_code' => $transfer->transfer_code,
                    'message'       => $successMessage,
                ]);
            }

            return redirect()->route('admin.money-transfers.show', ['money_transfer' => $transfer->id])
                ->with('success', $successMessage);
        } catch (\Throwable $e) {
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'status'  => 'error',
                    'message' => $e->getMessage(),
                ], 400);
            }
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function show(int $id)
    {
        $transfer = MoneyTransfer::with([
            'fromBranch', 'toBranch', 'fromBank', 'toBank',
            'journalEntry.lines.ledger',
            'intercompanyJournalEntry.lines.ledger',
        ])->findOrFail($id);

        $cashLedgerEntries = DB::table('cash_ledger')
            ->where('reference_type', 'money_transfer')
            ->where('reference_id', $id)
            ->orderBy('id')
            ->get();

        return view('admin.money-transfers.show', [
            'title'             => 'Transfer — ' . $transfer->transfer_code,
            'transfer'          => $transfer,
            'cashLedgerEntries' => $cashLedgerEntries,
            'canReverse'        => !$transfer->is_reversed,
        ]);
    }

    public function reverse(Request $request, int $id)
    {
        $request->validate([
            'reverse_reason' => 'required|string|min:3|max:500',
        ]);

        try {
            $transfer = $this->service->reverseTransfer(
                $id,
                auth()->id(),
                $request->input('reverse_reason')
            );

            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'status'        => 'success',
                    'message'       => "Transfer {$transfer->transfer_code} reversed. GL + bank balance restored.",
                    'transfer_id'   => $transfer->id,
                    'transfer_code' => $transfer->transfer_code,
                    'is_reversed'   => true,
                ]);
            }

            return redirect()->route('admin.money-transfers.show', ['money_transfer' => $transfer->id])
                ->with('success', "Transfer {$transfer->transfer_code} reversed. GL + bank balance restored.");
        } catch (\Throwable $e) {
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'status'  => 'error',
                    'message' => $e->getMessage(),
                ], 400);
            }
            return back()->with('error', $e->getMessage());
        }
    }

    public function slip(int $id)
    {
        $transfer = MoneyTransfer::with([
            'fromBranch', 'toBranch', 'fromBank', 'toBank',
        ])->findOrFail($id);

        return view('admin.money-transfers.slip', [
            'title'    => 'Money Transfer Slip',
            'transfer' => $transfer,
        ]);
    }

    public function audit()
    {
        $logs = DB::table('user_audit_log')
            ->where('action', 'LIKE', 'money_transfer_%')
            ->orderBy('created_at', 'desc')
            ->limit(300)
            ->get();

        return view('admin.money-transfers.audit', [
            'title' => 'Money Transfer Audit Logs',
            'logs'  => $logs,
        ]);
    }

    /**
     * Resolve the list branch ID for non-admin users.
     */
    private function resolveListBranchId(): ?int
    {
        $user = auth()->user();
        if (!$user) return null;

        if (in_array($user->role, ['admin', 'superadmin'], true)) {
            return null; // See all branches
        }

        return (int) session('branch_id', $user->branch_id ?? 0) ?: null;
    }
}
