<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Damage Attachment — Phase 3 (Photo / Evidence Attachments).
 *
 * Stores photographic / documentary evidence against a damage invoice.
 * Critical for insurance claims, audit defense, and deterring fake "damage"
 * write-offs (the core accountability gap: an employee declares stock as
 * damaged and walks out with it — a photo requirement makes that far harder).
 *
 * Storage:
 *   Files live on the `local` disk (storage/app/private) and are streamed
 *   through an authorized controller route (DamageController::viewAttachment).
 *   They are NOT on the `public` disk — evidence is sensitive (theft scenes,
 *   damaged inventory, possibly identifying employees) and must NOT be
 *   web-accessible via a guessable /storage/... URL. RLS is meaningless if
 *   the file is served publicly. The `file_path` column is disk-relative.
 *
 * Lifecycle:
 *   - Upload: only while the parent damage is in `draft` (locked once
 *     confirmed — evidence integrity for audit).
 *   - Delete: same — draft only.
 *   - Cascade: a HARD delete of the damage cascades to attachment rows (FK
 *     ON DELETE CASCADE). The controller deletes the physical files first.
 *   - Soft delete / cancel of the damage: KEEPS attachments (audit trail
 *     must survive a cancel/reverse — Phase 3 risk note).
 *
 * @property int $id
 * @property int $damage_invoice_id
 * @property string $file_path        Disk-relative path on the `local` disk.
 * @property string $file_name        Original user-supplied filename (display).
 * @property string $mime_type
 * @property int $file_size           Bytes.
 * @property string|null $caption
 * @property int $uploaded_by
 * @property string $created_at
 */
class DamageAttachment extends Model
{
    public $timestamps = false; // only created_at (no updated_at) — see migration

    protected $table = 'damage_attachments';

    protected $fillable = [
        'damage_invoice_id',
        'file_path',
        'file_name',
        'mime_type',
        'file_size',
        'caption',
        'uploaded_by',
        'created_at',
    ];

    protected $casts = [
        'damage_invoice_id' => 'integer',
        'file_size'         => 'integer',
        'uploaded_by'       => 'integer',
        'created_at'        => 'datetime',
    ];

    /*
    |--------------------------------------------------------------------------
    | Validation constants — single source of truth for controller + UI.
    |--------------------------------------------------------------------------
    */

    /** Max attachments per damage invoice. */
    public const MAX_PER_DAMAGE = 10;

    /** Max file size in kilobytes (5 MB). */
    public const MAX_FILE_SIZE_KB = 5120;

    /** Allowed MIME types (images + PDF for supporting documents). */
    public const ALLOWED_MIMES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'application/pdf',
    ];

    /** Allowed file extensions (mirrors ALLOWED_MIMES for the UI accept attr). */
    public const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    public function damageInvoice(): BelongsTo
    {
        return $this->belongsTo(DamageInvoice::class, 'damage_invoice_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }

    public function isPdf(): bool
    {
        return $this->mime_type === 'application/pdf';
    }

    /**
     * Human-readable file size (e.g. "1.2 MB").
     */
    public function formattedSize(): string
    {
        $bytes = (int) $this->file_size;
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1048576) {
            return round($bytes / 1024, 1) . ' KB';
        }
        return round($bytes / 1048576, 2) . ' MB';
    }
}
