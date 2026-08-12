<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApprovalStep extends Model
{
    protected $fillable = [
        'approval_workflow_id',
        'level',
        'role',
        'is_parallel',
        'description',
    ];

    protected $casts = [
        'level' => 'integer',
        'is_parallel' => 'boolean',
    ];

    // G-246 (MEDIUM-WAVE-2-B / DDL drift H2): the approval_steps migration
    // `2026_08_10_000001_create_approval_workflow_engine.php` (L50) declares
    // `$table->timestamps()` — both `created_at` + `updated_at` columns exist
    // on the table. The previous `$timestamps = false` override here caused
    // Eloquent to silently skip populating those columns on create/update, so
    // every approval-step row had NULL `created_at` / `updated_at`. Aligning
    // the model with the migration (default `$timestamps = true`) restores
    // the audit trail for workflow-step edits. (ApprovalAction.php's
    // `$timestamps = false` IS correct — its migration deliberately omits
    // `timestamps()` and uses `acted_at DEFAULT CURRENT_TIMESTAMP` instead.
    // See approval-workflow.md §G10 for the full analysis.)
    public $timestamps = true;

    /* ── Relationships ─────────────────────────────────────── */

    public function workflow()
    {
        return $this->belongsTo(ApprovalWorkflow::class, 'approval_workflow_id');
    }

    /* ── Helpers ───────────────────────────────────────────── */

    /**
     * Check if a user with the given role can act on this step.
     */
    public function canBeActedBy(string $role): bool
    {
        return $this->role === $role || $role === 'admin' || $role === 'superadmin';
    }
}
