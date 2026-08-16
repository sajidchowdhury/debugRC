<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DatabaseBackup — a row in the `database_backups` table.
 *
 * Created in Session 3. Represents a single pg_dump -Fc backup file
 * produced by `php artisan db:backup-year-end`. The yearEndClose()
 * gate queries this model (via DatabaseBackupService) to check whether
 * a fresh, verified backup exists for the fiscal year being closed.
 *
 * Status lifecycle:
 *   verified   — file exists, SHA-256 matches, fresh
 *   failed     — pg_dump exited non-zero, no file written
 *   superseded — a newer verified backup exists for this FY
 *                (file NOT deleted — kept for manual recovery)
 *
 * Note: This model intentionally does NOT use the BelongsToFiscalYear
 * trait. The `database_backups` table is a control table (like
 * `fiscal_years` itself) — backups must remain queryable across FY
 * boundaries so an admin can list / verify / restore backups from
 * previous FYs. The trait's global scope would hide backups from
 * closed FYs, defeating the purpose.
 *
 * @property int $id
 * @property int $fiscal_year_id
 * @property string $file_path
 * @property int $file_size_bytes
 * @property string $sha256_hash
 * @property string|null $pg_dump_version
 * @property int|null $created_by_user_id
 * @property string $status
 * @property string|null $error_message
 * @property \Carbon\Carbon $created_at
 *
 * @see \App\Services\DatabaseBackupService
 * @see docs/IMPLEMENTATION_PLAN_FY_ISOLATION_AND_BRANCH_PNL.md Session 3
 */
class DatabaseBackup extends Model
{
    public $timestamps = false; // we use only created_at, managed manually

    protected $table = 'database_backups';

    protected $fillable = [
        'fiscal_year_id',
        'file_path',
        'file_size_bytes',
        'sha256_hash',
        'pg_dump_version',
        'created_by_user_id',
        'status',
        'error_message',
    ];

    protected $casts = [
        'fiscal_year_id'    => 'int',
        'file_size_bytes'   => 'int',
        'created_by_user_id'=> 'int',
        'created_at'        => 'datetime',
    ];

    // ── Relations ───────────────────────────────────────────────────

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    // ── Scopes ──────────────────────────────────────────────────────

    public function scopeVerified(\Illuminate\Database\Eloquent\Builder $q): \Illuminate\Database\Eloquent\Builder
    {
        return $q->where('status', 'verified');
    }

    public function scopeForFiscalYear(\Illuminate\Database\Eloquent\Builder $q, int $fyId): \Illuminate\Database\Eloquent\Builder
    {
        return $q->where('fiscal_year_id', $fyId);
    }

    // ── Helpers ─────────────────────────────────────────────────────

    public function isVerified(): bool
    {
        return $this->status === 'verified';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function isSuperseded(): bool
    {
        return $this->status === 'superseded';
    }
}
