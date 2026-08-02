<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApprovalAction extends Model
{
    protected $fillable = [
        'approval_request_id',
        'level',
        'action',
        'acted_by',
        'acted_at',
        'comments',
        'role_at_time',
    ];

    protected $casts = [
        'level' => 'integer',
        'acted_at' => 'datetime',
    ];

    public $timestamps = false;

    /* ── Relationships ─────────────────────────────────────── */

    public function request()
    {
        return $this->belongsTo(ApprovalRequest::class, 'approval_request_id');
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'acted_by');
    }

    /* ── Helpers ───────────────────────────────────────────── */

    public function isApproval(): bool
    {
        return $this->action === 'approved';
    }

    public function isRejection(): bool
    {
        return $this->action === 'rejected';
    }
}
