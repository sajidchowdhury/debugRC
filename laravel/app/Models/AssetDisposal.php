<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * AssetDisposal — Phase 9.4: Fixed Asset & Depreciation
 *
 * Records the disposal of a fixed asset (sale, write-off, scrap, donation).
 * Calculates gain/loss on disposal and posts the corresponding journal entry.
 *
 * Disposal Types:
 *   - sale:      Asset sold for proceeds; gain/loss = proceeds - book_value
 *   - write_off: Asset written off (no proceeds); loss = remaining book_value
 *   - scrap:     Asset scrapped for nominal proceeds
 *   - donation:  Asset donated (no proceeds); loss = remaining book_value
 *
 * Journal Entry for Sale:
 *   Dr Cash/Bank              (proceeds)
 *   Dr Accumulated Depreciation (total accumulated dep)
 *   Cr Fixed Asset             (original cost)
 *   Dr/Cr Gain/Loss on Disposal (difference)
 *
 * Journal Entry for Write-off:
 *   Dr Accumulated Depreciation (total accumulated dep)
 *   Dr Loss on Disposal        (remaining book value)
 *   Cr Fixed Asset             (original cost)
 *
 * @property int $id
 * @property string $disposal_code
 * @property int $fixed_asset_id
 * @property string $disposal_type
 * @property string $disposal_date
 * @property float $disposal_proceeds
 * @property float $book_value_at_disposal
 * @property float $accumulated_depreciation_at_disposal
 * @property float $gain_loss_amount
 * @property string $gain_loss_type
 * @property int|null $proceeds_ledger_id
 * @property int|null $gain_loss_ledger_id
 * @property int|null $journal_entry_id
 * @property string|null $reason
 * @property string|null $notes
 * @property int $created_by
 */
class AssetDisposal extends Model
{
    protected $table = 'asset_disposals';

    public $timestamps = true;

    protected $fillable = [
        'disposal_code',
        'fixed_asset_id',
        'disposal_type',
        'disposal_date',
        'disposal_proceeds',
        'book_value_at_disposal',
        'accumulated_depreciation_at_disposal',
        'gain_loss_amount',
        'gain_loss_type',
        'proceeds_ledger_id',
        'gain_loss_ledger_id',
        'journal_entry_id',
        'reason',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'disposal_date' => 'date',
        'disposal_proceeds' => 'decimal:2',
        'book_value_at_disposal' => 'decimal:2',
        'accumulated_depreciation_at_disposal' => 'decimal:2',
        'gain_loss_amount' => 'decimal:2',
        'created_by' => 'integer',
    ];

    // ── Relationships ───────────────────────────────────────────────

    public function fixedAsset(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'fixed_asset_id');
    }

    public function proceedsLedger(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Ledger::class, 'proceeds_ledger_id');
    }

    public function gainLossLedger(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Ledger::class, 'gain_loss_ledger_id');
    }

    public function journalEntry(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\Accounting\JournalEntry::class, 'journal_entry_id');
    }

    public function creator(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ── Helpers ─────────────────────────────────────────────────────

    public function isSale(): bool
    {
        return $this->disposal_type === 'sale';
    }

    public function isWriteOff(): bool
    {
        return $this->disposal_type === 'write_off';
    }

    public function isGain(): bool
    {
        return $this->gain_loss_type === 'gain';
    }

    public function isLoss(): bool
    {
        return $this->gain_loss_type === 'loss';
    }

    public function getDisposalTypeLabel(): string
    {
        return [
            'sale' => 'Sale',
            'write_off' => 'Write-Off',
            'scrap' => 'Scrap',
            'donation' => 'Donation',
        ][$this->disposal_type] ?? ucfirst(str_replace('_', ' ', $this->disposal_type));
    }

    public function getGainLossBadge(): string
    {
        return [
            'gain' => '<span class="badge bg-success"><i class="fas fa-arrow-up me-1"></i>Gain</span>',
            'loss' => '<span class="badge bg-danger"><i class="fas fa-arrow-down me-1"></i>Loss</span>',
            'none' => '<span class="badge bg-secondary">Break Even</span>',
        ][$this->gain_loss_type] ?? '<span class="badge bg-light text-dark">' . e($this->gain_loss_type) . '</span>';
    }

    public static function disposalTypeOptions(): array
    {
        return [
            'sale' => 'Sale',
            'write_off' => 'Write-Off',
            'scrap' => 'Scrap',
            'donation' => 'Donation',
        ];
    }
}
