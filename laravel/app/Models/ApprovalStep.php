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

    public $timestamps = false;

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
