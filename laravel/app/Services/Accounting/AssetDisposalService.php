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
            $salvageValue = (float) $asset->salvage_value;

            // G-112 (FINANCE-1): salvage-value loss guard.
            // For a fully-depreciated asset (NBV == salvage), a ৳0 scrap
            // computes loss = 0 − salvage = −salvage → loss_amount = salvage.
            // This records a P&L loss equal to the salvage value, even though
            // the asset was already fully depreciated. The accounting
            // treatment is correct (the estimated residual value didn't
            // materialize → recognize the loss), but it's a noisy entry
            // that accountants may expect to route through retained earnings
            // instead of P&L. We log a warning so the accountant can review
            // + confirm the treatment before the JE posts. The check is
            // informational only — the JE still posts through loss_on_disposal
            // (the registered nature). A future config flag could route it
            // to a retained-earnings ledger instead.
            if ($bookValueAtDisposal <= $salvageValue + 0.01
                && $disposalProceeds < $salvageValue
                && $salvageValue > 0
            ) {
                Log::warning('AssetDisposal: fully-depreciated asset scrapped below salvage', [
                    'asset_id' => $asset->id,
                    'asset_code' => $asset->asset_code,
                    'net_book_value' => $bookValueAtDisposal,
                    'salvage_value' => $salvageValue,
                    'disposal_proceeds' => $disposalProceeds,
                    'expected_loss_amount' => round($disposalProceeds - $bookValueAtDisposal, 2),
                    'treatment' => 'P&L loss_on_disposal (default); accountant may reclassify to retained earnings',
                ]);
            }

            // Calculate gain/loss
            $gainLossAmount = round($disposalProceeds - $bookValueAtDisposal, 2);
            $gainLossType = 'none';
            if ($gainLossAmount > 0.01) {
                $gainLossType = 'gain';
            } elseif ($gainLossAmount < -0.01) {
                $gainLossType = 'loss';
                $gainLossAmount = abs($gainLossAmount); // Store as positive number
            }

            // G-323 (FINANCE-1): generate disposal_code atomically via
            // DocumentSequenceService (advisory-locked) instead of the
            // race-prone LIKE + ORDER BY DESC + 1 pattern. Format is
            // unchanged: DSP-YYYY-NNNNN. The legacy generateDisposalCode()
            // is retained for backward compatibility but is no longer the
            // primary path.
            $disposalCode = $this->generateDisposalCodeAtomic($disposalDate);

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

            // G-109 (FINANCE-1): this service calls
            // JournalPostingService::createJournalEntry + reverseJournalEntry
            // DIRECTLY (not JournalReversalService::reverseByJournalEntry).
            // Rationale: depreciation/disposal JEs have NO sub-ledger
            // entries (no customer/supplier/employee ledger rows reference
            // them), so the cascade that JournalReversalService performs is
            // unnecessary. This is an intentional, documented deviation from
            // the canonical reversal pattern in
            // `accounting/reversal-vs-cancellation.md` — see BR30.
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

            // Create the disposal record (status='posted' — JE is in the GL)
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
                'status' => 'posted',
                'reason' => $data['reason'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $userId,
            ]);

            // Update the asset status
            $asset->update([
                'status' => 'disposed',
            ]);

            // G-341 (FINANCE-1): reverse all pending depreciation schedules
            // for the asset (BR15). Previously this bulk update set only
            // `status='reversed'` — no `reversed_by` / `reversed_at` /
            // `reverse_reason`. That broke the audit trail (the schedule
            // showed "reversed" with no provenance) AND made it impossible
            // for reverseDisposal to find + restore the right schedules
            // (G-113). Now we stamp the full reversal metadata, including a
            // `reverse_reason` that cites the disposal_code so G-113's
            // restoration query can find them by exact string match.
            $pendingSchedules = AssetDepreciationSchedule::where('fixed_asset_id', $asset->id)
                ->where('status', 'pending')
                ->get();

            $forceReverseReason = "Force reversed by disposal {$disposalCode}";
            foreach ($pendingSchedules as $schedule) {
                $schedule->update([
                    'status' => 'reversed',
                    'reversed_by' => $userId,
                    'reversed_at' => now(),
                    'reverse_reason' => $forceReverseReason,
                ]);
            }

            return $disposal;
        });
    }

    /**
     * Reverse a disposal — restore the asset, reverse the journal entry,
     * restore force-reversed pending schedules, and soft-delete the disposal
     * record (mark status='reversed' — do NOT hard-delete).
     *
     * G-103 (FINANCE-1): the previous implementation called `$disposal->delete()`
     * which fired `DELETE FROM asset_disposals` — destroying the audit trail.
     * The GL reversal JE's `reference_id` was left pointing at nothing. Now
     * we set `status='reversed'` + `reversed_by` + `reversed_at` +
     * `reverse_reason` and leave the row in place. The disposal history is
     * preserved; auditors can see the full disposal → reversal chain.
     *
     * G-113 (FINANCE-1): the previous implementation did NOT restore the
     * pending schedules that `disposeAsset` force-reversed (BR18). The
     * accountant had to manually re-generate them. Now we find the
     * force-reversed schedules by their `reverse_reason` (stamped with the
     * disposal_code by G-341's fix) and restore them to `pending`.
     *
     * G-109 (FINANCE-1): calls `JournalPostingService::reverseJournalEntry`
     * directly (not `JournalReversalService::reverseByJournalEntry`). See
     * the documented deviation in `disposeAsset` + BR30.
     *
     * @param AssetDisposal $disposal
     * @param int $userId
     * @param string $reason
     * @return void
     */
    public function reverseDisposal(AssetDisposal $disposal, int $userId, string $reason): void
    {
        DB::transaction(function () use ($disposal, $userId, $reason) {
            // Reverse the journal entry (G-109: direct call, no cascade)
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

            // G-113 (FINANCE-1): restore the pending schedules that
            // disposeAsset force-reversed. We find them by the exact
            // `reverse_reason` string that G-341's fix stamps on them
            // ("Force reversed by disposal {disposal_code}"). This is
            // precise — it will NOT restore schedules that were reversed
            // by a real DepreciationService::reverseDepreciation call
            // (those carry the accountant-supplied reason string).
            $forceReverseReason = "Force reversed by disposal {$disposal->disposal_code}";
            $restoredSchedules = AssetDepreciationSchedule::where('fixed_asset_id', $disposal->fixed_asset_id)
                ->where('status', 'reversed')
                ->where('reverse_reason', $forceReverseReason)
                ->get();

            foreach ($restoredSchedules as $schedule) {
                $schedule->update([
                    'status' => 'pending',
                    'reversed_by' => null,
                    'reversed_at' => null,
                    'reverse_reason' => null,
                ]);
            }

            // G-103 (FINANCE-1): soft-delete the disposal record. Set
            // status='reversed' + stamp reversal metadata. The row stays
            // in the table so the GL reversal JE's reference_id resolves
            // + the audit trail is preserved.
            $disposal->update([
                'status' => 'reversed',
                'reversed_by' => $userId,
                'reversed_at' => now(),
                'reverse_reason' => $reason,
            ]);
        });
    }

    /**
     * Generate a unique disposal code atomically via advisory lock.
     *
     * G-323 (FINANCE-1): the legacy `generateDisposalCode()` used a
     * race-prone `LIKE + ORDER BY DESC + 1` pattern — two concurrent
     * disposals in the same year could read the same `lastCode`, both
     * compute `nextSeq = lastSeq + 1`, and both INSERT the same
     * `disposal_code`, hitting the UNIQUE constraint with a 50% failure
     * rate. This method delegates to `DocumentSequenceService::nextCode()`
     * which acquires a transaction-scoped `pg_advisory_xact_lock` keyed on
     * (doc_type, branch_id, period_key), so only one disposal per
     * year-per-branch can allocate a sequence number at a time.
     *
     * Format is unchanged: `DSP-YYYY-NNNNN` (5-digit zero-padded sequence,
     * branch-scoped, yearly reset). The `branch_id=0` default makes the
     * sequence global (disposals are rare + cross-branch in multi-entity
     * consolidation); pass an explicit branch_id if per-branch isolation
     * is later required.
     */
    private function generateDisposalCodeAtomic(string $disposalDate): string
    {
        $year = substr($disposalDate, 0, 4);

        return DocumentSequenceService::nextCode(
            docType: 'asset_disposal',
            prefix: 'DSP',
            datePart: $year,
            padLength: 5,
            periodKey: $year,         // yearly sequence reset
            branchId: 0,              // global sequence (disposals are rare)
        );
    }

    /**
     * Generate a unique disposal code.
     *
     * @deprecated G-323 (FINANCE-1): use `generateDisposalCodeAtomic()`
     *     instead. This legacy method is retained for backward
     *     compatibility (e.g., data-migration scripts that pre-date the
     *     DocumentSequenceService adoption). It is NOT called by the
     *     primary `disposeAsset` path.
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
