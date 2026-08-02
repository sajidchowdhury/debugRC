<?php

namespace App\Services\Accounting;

use App\Models\FixedAsset;
use App\Models\AssetDisposal;
use App\Models\AssetDepreciationSchedule;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * AssetDisposalService — Phase 9.4: Fixed Asset & Depreciation
 *
 * Handles the disposal of fixed assets (sale, write-off, scrap, donation).
 * Calculates gain/loss on disposal and posts the corresponding journal entry.
 *
 * Disposal Types:
 *   - sale:      Asset sold for proceeds; gain/loss = proceeds - book_value
 *   - write_off: Asset written off (no proceeds); loss = remaining book_value
 *   - scrap:     Asset scrapped for nominal proceeds
 *   - donation:  Asset donated (no proceeds); loss = remaining book_value
 *
 * Journal Entry for Sale:
 *   Dr Cash/Bank              (proceeds_ledger_id — proceeds amount)
 *   Dr Accumulated Depreciation (dep_ledger_id — total accumulated)
 *   Cr Fixed Asset             (asset_ledger_id — original cost)
 *   Dr/Cr Gain/Loss on Disposal (gain_loss_ledger_id — the difference)
 *
 * Journal Entry for Write-off (no proceeds):
 *   Dr Accumulated Depreciation (dep_ledger_id — total accumulated)
 *   Dr Loss on Disposal        (gain_loss_ledger_id — remaining book value)
 *   Cr Fixed Asset             (asset_ledger_id — original cost)
 */
class AssetDisposalService
{
    public function __construct(
        private JournalPostingService $journalService,
        private LedgerNatureService $natureService,
    ) {}

    /**
     * Dispose of a fixed asset.
     *
     * @param array $data {
     *     fixed_asset_id: int,
     *     disposal_type: string ('sale','write_off','scrap','donation'),
     *     disposal_date: string (Y-m-d),
     *     disposal_proceeds: float,
     *     proceeds_ledger_id: int|null (cash/bank ledger for sale proceeds),
     *     gain_loss_ledger_id: int|null (gain/loss ledger, auto-resolved if null),
     *     reason: string|null,
     *     notes: string|null,
     * }
     * @return AssetDisposal
     * @throws \RuntimeException If asset is not eligible for disposal
     */
    public function disposeAsset(array $data): AssetDisposal
    {
        return DB::transaction(function () use ($data) {
            $asset = FixedAsset::findOrFail($data['fixed_asset_id']);

            if (!$asset->canBeDisposed()) {
                throw new \RuntimeException("Asset {$asset->asset_code} cannot be disposed (status: {$asset->status}).");
            }

            $disposalDate = $data['disposal_date'];
            $disposalType = $data['disposal_type'];
            $disposalProceeds = (float) ($data['disposal_proceeds'] ?? 0);
            $bookValueAtDisposal = (float) $asset->net_book_value;
            $accumulatedDepAtDisposal = (float) $asset->accumulated_depreciation;
            $acquisitionCost = (float) $asset->acquisition_cost;

            // Calculate gain/loss
            $gainLossAmount = round($disposalProceeds - $bookValueAtDisposal, 2);
            $gainLossType = 'none';
            if ($gainLossAmount > 0.01) {
                $gainLossType = 'gain';
            } elseif ($gainLossAmount < -0.01) {
                $gainLossType = 'loss';
                $gainLossAmount = abs($gainLossAmount); // Store as positive number
            }

            // Generate disposal code
            $disposalCode = $this->generateDisposalCode($disposalDate);

            // Resolve gain/loss ledger
            $gainLossLedgerId = $data['gain_loss_ledger_id'] ?? null;
            if (!$gainLossLedgerId) {
                if ($gainLossType === 'gain') {
                    $gainLossLedgerId = $this->natureService->resolveLedgerByNature('gain_on_disposal');
                } else {
                    $gainLossLedgerId = $this->natureService->resolveLedgerByNature('loss_on_disposal');
                }
            }

            // Resolve proceeds ledger (cash/bank)
            $proceedsLedgerId = $data['proceeds_ledger_id'] ?? null;
            if (!$proceedsLedgerId && $disposalProceeds > 0) {
                $proceedsLedgerId = $this->natureService->resolveLedgerByNature('cash_bank');
            }

            // Build journal lines
            $journalLines = [];
            $totalDebit = 0;
            $totalCredit = 0;

            // Dr Accumulated Depreciation (remove accumulated depreciation)
            if ($accumulatedDepAtDisposal > 0) {
                $journalLines[] = [
                    'ledger_id' => $asset->dep_ledger_id,
                    'debit' => $accumulatedDepAtDisposal,
                    'credit' => 0,
                    'memo' => "Remove accumulated depreciation - {$asset->asset_code}",
                ];
                $totalDebit += $accumulatedDepAtDisposal;
            }

            // Dr Cash/Bank (proceeds from sale)
            if ($disposalProceeds > 0 && $proceedsLedgerId) {
                $journalLines[] = [
                    'ledger_id' => $proceedsLedgerId,
                    'debit' => $disposalProceeds,
                    'credit' => 0,
                    'memo' => "Sale proceeds - {$asset->asset_code}",
                ];
                $totalDebit += $disposalProceeds;
            }

            // Cr Fixed Asset (remove original cost)
            $journalLines[] = [
                'ledger_id' => $asset->asset_ledger_id,
                'debit' => 0,
                'credit' => $acquisitionCost,
                'memo' => "Remove asset from books - {$asset->asset_code}",
            ];
            $totalCredit += $acquisitionCost;

            // Dr/Cr Gain/Loss on Disposal
            if ($gainLossType === 'gain' && $gainLossLedgerId) {
                $journalLines[] = [
                    'ledger_id' => $gainLossLedgerId,
                    'debit' => 0,
                    'credit' => $gainLossAmount,
                    'memo' => "Gain on disposal - {$asset->asset_code}",
                ];
                $totalCredit += $gainLossAmount;
            } elseif ($gainLossType === 'loss' && $gainLossLedgerId) {
                $journalLines[] = [
                    'ledger_id' => $gainLossLedgerId,
                    'debit' => $gainLossAmount,
                    'credit' => 0,
                    'memo' => "Loss on disposal - {$asset->asset_code}",
                ];
                $totalDebit += $gainLossAmount;
            }

            // Verify balance (Dr must equal Cr)
            $diff = abs($totalDebit - $totalCredit);
            if ($diff > 0.01) {
                // This shouldn't happen, but let's catch it
                throw new \RuntimeException("Disposal entry not balanced: debits={$totalDebit} credits={$totalCredit} (diff={$diff}). Asset: {$asset->asset_code}");
            }

            // Post the journal entry
            $userId = Auth::id();
            $journalEntryId = $this->journalService->createJournalEntry(
                [
                    'entry_date' => $disposalDate,
                    'reference_type' => 'asset_disposal',
                    'reference_id' => $asset->id,
                    'branch_id' => $asset->branch_id,
                    'description' => "Asset disposal - {$asset->asset_code} ({$disposalType})",
                    'source' => 'asset_disposal',
                    'created_by' => $userId,
                ],
                $journalLines
            );

            // Create the disposal record
            $disposal = AssetDisposal::create([
                'disposal_code' => $disposalCode,
                'fixed_asset_id' => $asset->id,
                'disposal_type' => $disposalType,
                'disposal_date' => $disposalDate,
                'disposal_proceeds' => $disposalProceeds,
                'book_value_at_disposal' => $bookValueAtDisposal,
                'accumulated_depreciation_at_disposal' => $accumulatedDepAtDisposal,
                'gain_loss_amount' => $gainLossAmount,
                'gain_loss_type' => $gainLossType,
                'proceeds_ledger_id' => $proceedsLedgerId,
                'gain_loss_ledger_id' => $gainLossLedgerId,
                'journal_entry_id' => $journalEntryId,
                'reason' => $data['reason'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $userId,
            ]);

            // Update the asset status
            $asset->update([
                'status' => 'disposed',
            ]);

            // Reverse any pending depreciation schedules for future periods
            $pendingSchedules = AssetDepreciationSchedule::where('fixed_asset_id', $asset->id)
                ->where('status', 'pending')
                ->get();

            foreach ($pendingSchedules as $schedule) {
                $schedule->update(['status' => 'reversed']);
            }

            return $disposal;
        });
    }

    /**
     * Reverse a disposal — restore the asset and reverse the journal entry.
     *
     * @param AssetDisposal $disposal
     * @param int $userId
     * @param string $reason
     * @return void
     */
    public function reverseDisposal(AssetDisposal $disposal, int $userId, string $reason): void
    {
        DB::transaction(function () use ($disposal, $userId, $reason) {
            // Reverse the journal entry
            if ($disposal->journal_entry_id) {
                $this->journalService->reverseJournalEntry(
                    $disposal->journal_entry_id,
                    $userId,
                    "Reversal of asset disposal: {$reason}"
                );
            }

            // Restore the asset status
            $asset = $disposal->fixedAsset;
            if ($asset) {
                // Determine the correct status based on book value
                $newStatus = 'active';
                if ($asset->net_book_value <= (float) $asset->salvage_value + 0.01) {
                    $newStatus = 'fully_depreciated';
                }

                $asset->update([
                    'status' => $newStatus,
                ]);
            }

            // Delete the disposal record (or mark as reversed)
            $disposal->delete();
        });
    }

    /**
     * Generate a unique disposal code.
     */
    private function generateDisposalCode(string $disposalDate): string
    {
        $year = substr($disposalDate, 0, 4);

        $lastCode = DB::table('asset_disposals')
            ->where('disposal_code', 'LIKE', "DSP-{$year}-%")
            ->orderByDesc('disposal_code')
            ->value('disposal_code');

        $nextSeq = 1;
        if ($lastCode) {
            $parts = explode('-', $lastCode);
            $nextSeq = ((int) end($parts)) + 1;
        }

        return "DSP-{$year}-" . str_pad($nextSeq, 5, '0', STR_PAD_LEFT);
    }
}
