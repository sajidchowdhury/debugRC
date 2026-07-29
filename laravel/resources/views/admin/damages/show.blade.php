@extends('layouts.admin')

@section('content')
@php
    $dmg = $damage;

    // Damages = loss → confirmed = danger (red), draft = warning, cancelled = secondary.
    $statusBadge = function () use ($dmg): string {
        return [
            'draft'     => '<span class="badge bg-warning-subtle text-warning fs-6"><i class="fas fa-pen-to-square me-1"></i>Draft</span>',
            'confirmed' => '<span class="badge bg-danger-subtle text-danger fs-6"><i class="fas fa-triangle-exclamation me-1"></i>Confirmed</span>',
            'cancelled' => '<span class="badge bg-secondary-subtle text-secondary fs-6"><i class="fas fa-ban me-1"></i>Cancelled</span>',
        ][$dmg->status] ?? '<span class="badge bg-light text-dark fs-6">' . e($dmg->status) . '</span>';
    };

    $je           = $dmg->journalEntry;
    $jeLines      = $je ? $je->lines : collect();
    $debitTotal   = $jeLines->sum(fn ($l) => (float) $l->debit);
    $creditTotal  = $jeLines->sum(fn ($l) => (float) $l->credit);
    $hasMovements = is_iterable($stockMovements) && count($stockMovements) > 0;

    // Phase 0 (Damage plan): only admin/manager may confirm or cancel a
    // damage (they post/reverse stock + GL). warehouse_manager can create
    // drafts and view, but cannot post — so hide the action buttons and
    // show an explanatory note instead of letting them click into a 403.
    $canPost = auth()->check() && auth()->user()->hasRole('admin', 'manager');

    // Phase 1 — damage type badge + structured reason label.
    $typeLabels = \App\Models\DamageInvoice::DAMAGE_TYPE_LABELS;
    $typeBadges = \App\Models\DamageInvoice::DAMAGE_TYPE_BADGE_CLASSES;
    $typeIcons  = \App\Models\DamageInvoice::DAMAGE_TYPE_ICONS;
    $typeBadge = function () use ($dmg, $typeLabels, $typeBadges, $typeIcons): string {
        $t    = $dmg->damage_type ?? 'other';
        $cls  = $typeBadges[$t] ?? 'bg-light text-dark';
        $icon = $typeIcons[$t]  ?? 'fa-circle-question';
        $lbl  = $typeLabels[$t] ?? $t;
        return '<span class="badge ' . $cls . ' fs-6"><i class="fas ' . $icon . ' me-1"></i>' . e($lbl) . '</span>';
    };
    // Structured reason (from the eager-loaded reasonTaxonomy relation).
    $reasonTax  = $dmg->reasonTaxonomy;
    $reasonLbl  = $reasonTax ? $reasonTax->label : null;

    // Phase 3 — evidence / photo requirement.
    // `require_photo_for_types` (config/damage.php) lists the damage types
    // that MUST have at least one attachment before confirm. The hard gate
    // lives in DamageService::confirmDamage; this surfaces it on the UI so
    // the user knows BEFORE clicking Confirm (and disables the button).
    $requirePhotoTypes = (array) config('damage.require_photo_for_types', []);
    $photoRequired     = in_array($dmg->damage_type, $requirePhotoTypes, true);
    $photoCount        = $dmg->attachments ? $dmg->attachments->count() : 0;
    $photoMissing      = $photoRequired && $photoCount === 0;
    $maxAttachments    = (int) config('damage.attachment_max_per_damage', \App\Models\DamageAttachment::MAX_PER_DAMAGE);
    $maxSizeKb         = (int) config('damage.attachment_max_size_kb', \App\Models\DamageAttachment::MAX_FILE_SIZE_KB);
    $canUpload         = auth()->check()
        && auth()->user()->can('uploadAttachment', $dmg);   // draft + same-branch + role

    // Phase 4 — witness / accountable / recovery context.
    $witness     = $dmg->witnessEmployee;
    $accountable = $dmg->accountableEmployee;
    $hasRecovery = $dmg->hasRecovery();
    $recoveryAmount = (float) $dmg->recovery_amount;
    $recoveryJe   = $dmg->recoveryJournalEntry;
    $recoverable  = $dmg->isRecoverable();   // confirmed + accountable + no recovery yet
    $canRecover   = auth()->check()
        && auth()->user()->can('recoverFromEmployee', $dmg);  // admin/manager + same-branch + recoverable

    // Whether the current user may view the employee profile/account page
    // (admin/manager/hr). warehouse_manager can create damages but NOT view
    // employee profiles — so the link degrades to plain text for them.
    $canViewEmployees = auth()->check()
        && auth()->user()->hasRole('admin', 'manager', 'hr');
@endphp

<div class="container-fluid py-2">
    {{-- Hero header (red = loss) --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#dc2626,#b91c1c);">
        <div>
            <h1 class="h4 mb-1">
                <i class="fas fa-triangle-exclamation me-2"></i>{{ $title }}
                {!! $statusBadge() !!}
                {{-- Phase 1 — damage type badge (prominent, next to status) --}}
                {!! $typeBadge() !!}
                @if ($dmg->is_reversed)
                    <span class="badge bg-light text-danger ms-1"><i class="fas fa-rotate-left me-1"></i>Reversed</span>
                @endif
            </h1>
            <p class="mb-0 small opacity-75">
                @if ($dmg->warehouse)
                    {{ $dmg->warehouse->warehouse_name }}
                    @if ($dmg->warehouse->branch) · {{ $dmg->warehouse->branch->branch_name }} @endif
                @endif
            </p>
        </div>
        <div>
            <a href="{{ route('admin.damages.index') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to list
            </a>
        </div>
    </header>

    {{-- Reversal banner --}}
    @if ($dmg->is_reversed)
        <div class="alert alert-danger d-flex align-items-center mb-3" role="alert">
            <i class="fas fa-rotate-left me-2 fa-lg"></i>
            <div>
                <strong>This damage invoice has been reversed.</strong>
                @if ($dmg->reversed_at)
                    Reversed on {{ \Carbon\Carbon::parse($dmg->reversed_at)->format('d M Y H:i') }}
                @endif
                @if ($dmg->reversed_by)
                    · by user #{{ $dmg->reversed_by }}
                @endif
                @if ($dmg->reverse_reason)
                    · Reason: <em>{{ $dmg->reverse_reason }}</em>
                @endif
            </div>
        </div>
    @endif

    <div class="row g-3">
        {{-- Left: main details --}}
        <div class="col-lg-8">
            {{-- Damage details card --}}
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white">
                    <h2 class="h6 mb-0"><i class="fas fa-circle-info me-1 text-danger"></i> Damage details</h2>
                </div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-3 text-muted">Code</dt>
                        <dd class="col-sm-9">
                            <span class="badge bg-secondary-subtle text-secondary">{{ $dmg->damage_code }}</span>
                        </dd>

                        <dt class="col-sm-3 text-muted">Date</dt>
                        <dd class="col-sm-9">{{ \Carbon\Carbon::parse($dmg->damage_date)->format('d M Y') }}</dd>

                        <dt class="col-sm-3 text-muted">Warehouse</dt>
                        <dd class="col-sm-9">
                            @if ($dmg->warehouse)
                                <strong>{{ $dmg->warehouse->warehouse_name }}</strong>
                                <span class="text-muted">({{ $dmg->warehouse->warehouse_code }})</span>
                                @if ($dmg->warehouse->branch)
                                    <div class="small text-muted">
                                        <i class="fas fa-building me-1"></i>{{ $dmg->warehouse->branch->branch_name }}
                                    </div>
                                @endif
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </dd>

                        <dt class="col-sm-3 text-muted">Branch</dt>
                        <dd class="col-sm-9">
                            @if ($dmg->branch)
                                {{ $dmg->branch->branch_name }}
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </dd>

                        {{-- Phase 1 — Damage type (prominent) --}}
                        <dt class="col-sm-3 text-muted">Damage type</dt>
                        <dd class="col-sm-9">{!! $typeBadge() !!}</dd>

                        {{-- Phase 1 — structured reason (label from taxonomy) --}}
                        <dt class="col-sm-3 text-muted">Reason</dt>
                        <dd class="col-sm-9">
                            @if ($reasonLbl)
                                <span class="fw-semibold">{{ $reasonLbl }}</span>
                                @if ($dmg->reason_code)
                                    <code class="small text-muted ms-1">{{ $dmg->reason_code }}</code>
                                @endif
                            @elseif ($dmg->reason_code)
                                <code>{{ $dmg->reason_code }}</code>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </dd>

                        {{-- Phase 1 — reason details (structured context) --}}
                        <dt class="col-sm-3 text-muted">Reason details</dt>
                        <dd class="col-sm-9">{!! nl2br(e($dmg->reason_detail ?: '—')) !!}</dd>

                        <dt class="col-sm-3 text-muted">Total value</dt>
                        <dd class="col-sm-9"><strong class="text-danger">Tk {{ number_format((float) $dmg->total_value, 2) }}</strong></dd>

                        <dt class="col-sm-3 text-muted">Created</dt>
                        <dd class="col-sm-9 small text-muted">
                            {{ optional($dmg->created_at)->format('Y-m-d H:i') }}
                            @if ($dmg->created_by) · by user #{{ $dmg->created_by }} @endif
                        </dd>

                        {{-- Legacy free-text reason (kept for back-compat) --}}
                        @if ($dmg->reason)
                            <dt class="col-sm-3 text-muted">Additional notes</dt>
                            <dd class="col-sm-9">{!! nl2br(e($dmg->reason)) !!}</dd>
                        @endif
                    </dl>
                </div>
            </div>

            {{-- Items table --}}
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white">
                    <h2 class="h6 mb-0">
                        <i class="fas fa-table-list me-1 text-danger"></i> Damaged items
                        <span class="badge bg-danger-subtle text-danger ms-1">{{ $dmg->items->count() }}</span>
                    </h2>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-striped table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Product</th>
                                    <th class="text-end">Qty</th>
                                    <th class="text-end">Rate (Tk)</th>
                                    <th class="text-end">Amount (Tk)</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($dmg->items as $item)
                                    <tr>
                                        <td>
                                            @if ($item->product)
                                                <span class="fw-semibold">{{ $item->product->product_name }}</span>
                                                <div class="small text-muted">{{ $item->product->product_code }}</div>
                                            @else
                                                <span class="text-muted">Product #{{ $item->product_id }}</span>
                                            @endif
                                        </td>
                                        <td class="text-end">{{ number_format((float) $item->qty, 4) }}</td>
                                        <td class="text-end">{{ number_format((float) $item->rate, 2) }}</td>
                                        <td class="text-end">{{ number_format($item->amount(), 2) }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="text-center text-muted py-4">No items.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                            <tfoot>
                                <tr class="table-light fw-bold">
                                    <td colspan="3" class="text-end">Total damage value</td>
                                    <td class="text-end text-danger">Tk {{ number_format((float) $dmg->total_value, 2) }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>

            {{-- Stock movements (only if confirmed and present) --}}
            @if ($dmg->isConfirmed() && $hasMovements)
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-white">
                        <h2 class="h6 mb-0">
                            <i class="fas fa-boxes-stacked me-1 text-danger"></i> Stock movements
                            <span class="badge bg-danger-subtle text-danger ms-1">{{ count($stockMovements) }}</span>
                        </h2>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-sm table-striped table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Date</th>
                                        <th>TX#</th>
                                        <th>Product</th>
                                        <th class="text-end">Qty</th>
                                        <th class="text-end">Rate (Tk)</th>
                                        <th class="text-end">Value (Tk)</th>
                                        <th class="text-center">Reversed?</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($stockMovements as $st)
                                        @php
                                            $qty = (float) $st->qty;
                                            // Damages are stock OUT → negative/red. Positive = green (reversal in).
                                            $qtyClass = $qty < 0 ? 'text-danger fw-bold' : 'text-success fw-bold';
                                        @endphp
                                        <tr>
                                            <td class="text-nowrap small">
                                                {{ \Carbon\Carbon::parse($st->transaction_date)->format('d M Y') }}
                                            </td>
                                            <td><span class="badge bg-light text-dark">#{{ $st->id }}</span></td>
                                            <td>
                                                <span class="fw-semibold">{{ $st->product_name }}</span>
                                                <div class="small text-muted">{{ $st->product_code }}</div>
                                            </td>
                                            <td class="text-end {{ $qtyClass }}">
                                                {{ $qty > 0 ? '+' : '' }}{{ number_format($qty, 4) }}
                                            </td>
                                            <td class="text-end">{{ number_format((float) $st->rate, 2) }}</td>
                                            <td class="text-end">{{ number_format((float) $st->total_value, 2) }}</td>
                                            <td class="text-center">
                                                @if (!empty($st->is_reversed))
                                                    <span class="badge bg-danger-subtle text-danger">
                                                        <i class="fas fa-rotate-left me-1"></i>Yes
                                                    </span>
                                                @else
                                                    <span class="badge bg-light text-muted">—</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            @endif

            {{-- GL Journal Entry (only if confirmed + has JE) --}}
            @if ($dmg->isConfirmed() && $je)
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <h2 class="h6 mb-0">
                            <i class="fas fa-book me-1 text-danger"></i> GL Journal Entry
                        </h2>
                        @if ($je->is_reversed)
                            <span class="badge bg-danger-subtle text-danger">
                                <i class="fas fa-rotate-left me-1"></i>Entry reversed
                            </span>
                        @endif
                    </div>
                    <div class="card-body">
                        <dl class="row mb-3 small">
                            <dt class="col-sm-2 text-muted">JE#</dt>
                            <dd class="col-sm-4"><span class="badge bg-secondary-subtle text-secondary">{{ $je->entry_no }}</span></dd>
                            <dt class="col-sm-2 text-muted">Entry date</dt>
                            <dd class="col-sm-4">{{ \Carbon\Carbon::parse($je->entry_date)->format('d M Y') }}</dd>
                            <dt class="col-sm-2 text-muted">Description</dt>
                            <dd class="col-sm-10">{{ $je->description ?: '—' }}</dd>
                        </dl>

                        <div class="table-responsive">
                            <table class="table table-sm table-striped table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Ledger</th>
                                        <th class="text-end">Debit (Tk)</th>
                                        <th class="text-end">Credit (Tk)</th>
                                        <th>Memo</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($jeLines as $line)
                                        <tr>
                                            <td>
                                                @if ($line->ledger)
                                                    <span class="fw-semibold">{{ $line->ledger->ledger_name }}</span>
                                                    <div class="small text-muted">{{ $line->ledger->ledger_code }}</div>
                                                @else
                                                    <span class="text-muted">Ledger #{{ $line->ledger_id }}</span>
                                                @endif
                                            </td>
                                            <td class="text-end">
                                                {{ (float) $line->debit > 0 ? number_format((float) $line->debit, 2) : '—' }}
                                            </td>
                                            <td class="text-end">
                                                {{ (float) $line->credit > 0 ? number_format((float) $line->credit, 2) : '—' }}
                                            </td>
                                            <td class="small">{{ $line->memo ?: '—' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                <tfoot>
                                    <tr class="table-light fw-bold">
                                        <td class="text-end">Total</td>
                                        <td class="text-end">{{ number_format($debitTotal, 2) }}</td>
                                        <td class="text-end">{{ number_format($creditTotal, 2) }}</td>
                                        <td>
                                            @if (abs($debitTotal - $creditTotal) < 0.01)
                                                <span class="badge bg-success-subtle text-success">
                                                    <i class="fas fa-check me-1"></i>Balanced
                                                </span>
                                            @else
                                                <span class="badge bg-danger-subtle text-danger">
                                                    <i class="fas fa-triangle-exclamation me-1"></i>Out by
                                                    {{ number_format(abs($debitTotal - $creditTotal), 2) }}
                                                </span>
                                            @endif
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
            @endif

            {{-- Phase 2 — Integrity checks panel (ports legacy DamageAuditModel).
                 Live-computed by DamageIntegrityService on every render. Shows
                 whether the damage header, its items, stock_transactions and GL
                 journal all reconcile. A red `fail` surfaces drift that should
                 be reconciled; a yellow `warn` is a soft flag (e.g. missing GL
                 that can be re-posted); blue `info` = expected for this state. --}}
            @php
                $intgItems   = $integrity['items'] ?? [];
                $intgSummary = $integrity['summary'] ?? ['pass' => 0, 'warn' => 0, 'fail' => 0, 'info' => 0];

                // Headline colour: red if any fail, else yellow if any warn, else green.
                $intgHasFail = ($intgSummary['fail'] ?? 0) > 0;
                $intgHasWarn = ($intgSummary['warn'] ?? 0) > 0;
                $intgHeadlineClass = $intgHasFail
                    ? 'bg-danger-subtle text-danger'
                    : ($intgHasWarn ? 'bg-warning-subtle text-warning' : 'bg-success-subtle text-success');
                $intgHeadlineIcon = $intgHasFail
                    ? 'fa-circle-xmark'
                    : ($intgHasWarn ? 'fa-triangle-exclamation' : 'fa-circle-check');

                $intgStatusMap = [
                    'pass' => ['icon' => 'fa-circle-check',      'cls' => 'text-success', 'bg' => 'bg-success-subtle', 'lbl' => 'Pass'],
                    'warn' => ['icon' => 'fa-triangle-exclamation', 'cls' => 'text-warning', 'bg' => 'bg-warning-subtle', 'lbl' => 'Warn'],
                    'fail' => ['icon' => 'fa-circle-xmark',      'cls' => 'text-danger',  'bg' => 'bg-danger-subtle',  'lbl' => 'Fail'],
                    'info' => ['icon' => 'fa-circle-info',       'cls' => 'text-info',    'bg' => 'bg-info-subtle',    'lbl' => 'Info'],
                ];
            @endphp
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <h2 class="h6 mb-0">
                        <i class="fas fa-shield-halved me-1 text-danger"></i> Integrity checks
                    </h2>
                    <span class="badge {{ $intgHeadlineClass }} fs-6">
                        <i class="fas {{ $intgHeadlineIcon }} me-1"></i>
                        @if ($intgHasFail)
                            {{ $intgSummary['fail'] }} failing
                        @elseif ($intgHasWarn)
                            {{ $intgSummary['warn'] }} warning(s)
                        @else
                            All checks passed
                        @endif
                    </span>
                </div>
                <div class="card-body p-0">
                    {{-- Summary tally strip --}}
                    <div class="d-flex flex-wrap gap-2 px-3 pt-3 pb-2 border-bottom">
                        @foreach (['pass','warn','fail','info'] as $st)
                            @php $n = $intgSummary[$st] ?? 0; $m = $intgStatusMap[$st]; @endphp
                            <span class="badge {{ $m['bg'] }} {{ $m['cls'] }}">
                                <i class="fas {{ $m['icon'] }} me-1"></i>{{ $m['lbl'] }}: {{ $n }}
                            </span>
                        @endforeach
                    </div>

                    {{-- Per-check list --}}
                    <ul class="list-group list-group-flush mb-0">
                        @foreach ($intgItems as $check)
                            @php
                                $m = $intgStatusMap[$check['status']] ?? $intgStatusMap['info'];
                                $showReconcile = $check['status'] === 'fail'
                                    && in_array($check['id'], ['total_value','stock','gl'], true);
                            @endphp
                            <li class="list-group-item d-flex align-items-start gap-3 py-2">
                                <i class="fas {{ $m['icon'] }} {{ $m['cls'] }} fa-lg mt-1"
                                   title="{{ $m['lbl'] }}"></i>
                                <div class="flex-grow-1">
                                    <div class="d-flex justify-content-between align-items-baseline gap-2">
                                        <span class="fw-semibold">{{ $check['title'] }}</span>
                                        <span class="badge {{ $m['bg'] }} {{ $m['cls'] }} small">{{ $m['lbl'] }}</span>
                                    </div>
                                    <div class="small text-muted">{{ $check['expected'] }}</div>
                                    @if (!empty($check['detail']))
                                        <div class="small text-body mt-1">
                                            <i class="fas fa-chevron-right me-1 text-muted small"></i>{{ $check['detail'] }}
                                        </div>
                                    @endif
                                    @if ($showReconcile)
                                        <div class="small mt-1">
                                            <span class="text-danger">
                                                <i class="fas fa-wrench me-1"></i>Reconcile
                                            </span>
                                            — verify stock + GL match this damage header.
                                        </div>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                    <div class="px-3 py-2 small text-muted border-top">
                        <i class="fas fa-clock-rotate-left me-1"></i>
                        Checks are live-computed from current DB state on every page load.
                    </div>
                </div>
            </div>

            {{-- Phase 3 — Evidence / photo attachments card.
                 Surfaces uploaded photos + PDFs as proof of the damage. For
                 real_damage / theft / quality_reject, at least one photo is
                 REQUIRED to confirm (gate in DamageService::confirmDamage);
                 the panel shows a prominent "required" banner when missing.
                 Files are served via the authorized viewAttachment route
                 (private disk — NOT /storage/...), so RLS actually protects
                 them. Upload/delete are draft-only (policy). --}}
            <div class="card border-0 shadow-sm mb-3" id="evidenceCard">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <h2 class="h6 mb-0">
                        <i class="fas fa-camera me-1 text-danger"></i> Evidence
                        <span class="badge bg-light text-muted ms-1">{{ $photoCount }} / {{ $maxAttachments }}</span>
                    </h2>
                    @if ($photoRequired)
                        @if ($photoMissing)
                            <span class="badge bg-danger-subtle text-danger">
                                <i class="fas fa-triangle-exclamation me-1"></i>Photo required
                            </span>
                        @else
                            <span class="badge bg-success-subtle text-success">
                                <i class="fas fa-circle-check me-1"></i>Requirement met
                            </span>
                        @endif
                    @else
                        <span class="badge bg-light text-muted">Optional</span>
                    @endif
                </div>
                <div class="card-body">
                    @if ($photoMissing)
                        <div class="alert alert-warning small mb-3" role="alert">
                            <i class="fas fa-triangle-exclamation me-1"></i>
                            This <strong>{{ $typeLabels[$dmg->damage_type] ?? $dmg->damage_type }}</strong> damage
                            <strong>requires at least one photo</strong> before it can be confirmed.
                            Upload evidence below (photos of the damaged stock, scene, defect, etc.).
                        </div>
                    @endif

                    {{-- Upload form (draft only + same-branch + role). Hidden once
                         the damage leaves draft (evidence is then frozen for audit). --}}
                    @if ($canUpload)
                        @if ($photoCount >= $maxAttachments)
                            <div class="alert alert-info small mb-3 mb-0">
                                <i class="fas fa-circle-info me-1"></i>
                                Attachment limit reached ({{ $maxAttachments }} per damage). Remove one to add another.
                            </div>
                        @else
                            <form method="POST" action="{{ route('admin.damages.attachments.store', $dmg) }}"
                                  enctype="multipart/form-data" id="evidenceUploadForm">
                                @csrf
                                <div class="border border-2 border-dashed rounded-3 p-3 text-center bg-light" id="evidenceDropzone">
                                    <i class="fas fa-cloud-arrow-up fa-2x text-muted mb-2"></i>
                                    <p class="mb-1 small">
                                        <strong>Drop a file here</strong> or
                                        <label for="evidenceFileInput" class="text-danger text-decoration-pointer mb-0" style="cursor:pointer;">
                                            browse
                                        </label>
                                    </p>
                                    <p class="text-muted small mb-2">
                                        JPG, PNG, WebP, or PDF · max {{ round($maxSizeKb / 1024, 1) }} MB
                                    </p>
                                    <input type="file" name="file" id="evidenceFileInput" class="d-none"
                                           accept=".jpg,.jpeg,.png,.webp,.pdf,image/jpeg,image/png,image/webp,application/pdf">
                                    <input type="text" name="caption" class="form-control form-control-sm mt-2"
                                           maxlength="255" placeholder="Optional caption (e.g. 'Broken seal on carton 14')">
                                    <button type="submit" class="btn btn-danger btn-sm mt-2 d-none" id="evidenceSubmitBtn">
                                        <i class="fas fa-upload me-1"></i> Upload
                                    </button>
                                </div>
                            </form>
                        @endif
                    @elseif ($dmg->isDraft() && auth()->check())
                        {{-- Draft but current user lacks upload permission (wrong role / cross-branch). --}}
                        <div class="alert alert-secondary small mb-0">
                            <i class="fas fa-lock me-1"></i>
                            You do not have permission to upload evidence for this damage.
                        </div>
                    @elseif (!$dmg->isDraft())
                        <div class="alert alert-secondary small mb-3">
                            <i class="fas fa-lock me-1"></i>
                            Evidence is <strong>frozen</strong> — this damage is no longer a draft.
                            Attachments are retained for the audit trail and cannot be added or removed.
                        </div>
                    @endif

                    {{-- Gallery --}}
                    @if ($photoCount > 0)
                        <div class="row g-2 mt-1" id="evidenceGallery">
                            @foreach ($dmg->attachments as $att)
                                @php
                                    $viewUrl   = route('admin.damages.attachments.view', [$dmg->id, $att->id]);
                                    $dlUrl     = route('admin.damages.attachments.download', [$dmg->id, $att->id]);
                                    $delUrl    = route('admin.damages.attachments.destroy', [$dmg->id, $att->id]);
                                    $uploader  = $att->uploadedBy ? $att->uploadedBy->username : ('User #' . $att->uploaded_by);
                                    $uploadedAt = \Carbon\Carbon::parse($att->created_at)->format('d M Y H:i');
                                @endphp
                                <div class="col-6 col-md-4 col-xl-3">
                                    <div class="card h-100 border shadow-sm">
                                        <div class="position-relative evidence-thumb-wrap" style="height: 140px; background:#f8f9fa;">
                                            @if ($att->isImage())
                                                <img src="{{ $viewUrl }}" alt="{{ e($att->file_name) }}"
                                                     class="w-100 h-100 evidence-thumb"
                                                     style="object-fit: cover; cursor: zoom-in;"
                                                     data-bs-toggle="modal" data-bs-target="#evidenceLightbox"
                                                     data-lightbox-src="{{ $viewUrl }}"
                                                     data-lightbox-name="{{ e($att->file_name) }}"
                                                     loading="lazy">
                                            @elseif ($att->isPdf())
                                                <div class="w-100 h-100 d-flex flex-column align-items-center justify-content-center text-danger"
                                                     style="cursor: pointer;"
                                                     data-bs-toggle="modal" data-bs-target="#evidenceLightbox"
                                                     data-lightbox-src="{{ $viewUrl }}"
                                                     data-lightbox-name="{{ e($att->file_name) }}">
                                                    <i class="fas fa-file-pdf fa-3x"></i>
                                                    <span class="small mt-1">PDF</span>
                                                </div>
                                            @else
                                                <div class="w-100 h-100 d-flex flex-column align-items-center justify-content-center text-muted">
                                                    <i class="fas fa-file fa-3x"></i>
                                                    <span class="small mt-1">{{ e(strtoupper(pathinfo($att->file_name, PATHINFO_EXTENSION))) }}</span>
                                                </div>
                                            @endif
                                        </div>
                                        <div class="card-body p-2">
                                            @if ($att->caption)
                                                <p class="small mb-1 fw-semibold text-truncate" title="{{ e($att->caption) }}">
                                                    {{ e($att->caption) }}
                                                </p>
                                            @endif
                                            <p class="small text-muted text-truncate mb-1" title="{{ e($att->file_name) }}">
                                                <i class="fas fa-paperclip me-1"></i>{{ e($att->file_name) }}
                                            </p>
                                            <p class="small text-muted mb-1">{{ $att->formattedSize() }}</p>
                                            <p class="small text-muted mb-2">
                                                <i class="fas fa-user me-1"></i>{{ e($uploader) }}
                                                · <i class="fas fa-clock me-1"></i>{{ $uploadedAt }}
                                            </p>
                                            <div class="d-flex gap-1">
                                                <a href="{{ $viewUrl }}" target="_blank" rel="noopener"
                                                   class="btn btn-outline-secondary btn-sm flex-grow-1">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                <a href="{{ $dlUrl }}" class="btn btn-outline-secondary btn-sm" title="Download">
                                                    <i class="fas fa-download"></i>
                                                </a>
                                                @if ($canUpload)
                                                    <button type="button" class="btn btn-outline-danger btn-sm evidence-delete-btn"
                                                            data-delete-url="{{ $delUrl }}"
                                                            data-file-name="{{ e($att->file_name) }}"
                                                            title="Remove">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @elseif ($canUpload)
                        <div class="text-center text-muted small py-3 mb-0">
                            <i class="fas fa-images fa-2x mb-2 d-block opacity-50"></i>
                            No evidence uploaded yet.
                        </div>
                    @endif
                </div>
            </div>

            {{-- Phase 4 — Accountability card.
                 Names the witness + accountable employee for this damage and
                 shows the recovery status. For missing/theft types, a missing
                 required party is flagged here too (the create gate should
                 have caught it, but pre-Phase-4 rows + the exempt
                 sales-return flow may lack one). --}}
            @php
                $requireAccountable = in_array($dmg->damage_type, (array) (config('damage.accountability.require_accountable_for_types') ?? []), true);
                $requireWitness     = in_array($dmg->damage_type, (array) (config('damage.accountability.require_witness_for_types') ?? []), true);
                $missingAccountable = $requireAccountable && empty($accountable);
                $missingWitness     = $requireWitness && empty($witness);

                // Render an employee as a link (if the user may view employee
                // profiles) or plain text (warehouse_manager can't).
                $renderEmployee = function ($emp) use ($canViewEmployees) {
                    if (!$emp) {
                        return '<span class="text-muted">—</span>';
                    }
                    $name = e($emp->name) . ' <code class="small text-muted ms-1">#' . e($emp->employee_code) . '</code>';
                    $role = $emp->role ? '<span class="badge bg-light text-muted ms-1">' . e($emp->role) . '</span>' : '';
                    $branch = ($emp->branch ? ' · <span class="text-muted small">' . e($emp->branch->branch_name) . '</span>' : '');
                    if ($canViewEmployees) {
                        return '<a href="' . e(route('admin.employees.account', $emp)) . '" class="text-decoration-none fw-semibold">'
                            . $name . '</a>' . $role . $branch;
                    }
                    return '<span class="fw-semibold">' . $name . '</span>' . $role . $branch;
                };
            @endphp
            <div class="card border-0 shadow-sm mb-3" id="accountabilityCard">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <h2 class="h6 mb-0">
                        <i class="fas fa-user-shield me-1 text-danger"></i> Accountability
                    </h2>
                    @if ($hasRecovery)
                        <span class="badge bg-success-subtle text-success">
                            <i class="fas fa-circle-check me-1"></i>Recovered Tk {{ number_format($recoveryAmount, 2) }}
                        </span>
                    @elseif ($recoverable)
                        <span class="badge bg-warning-subtle text-warning">
                            <i class="fas fa-circle-exclamation me-1"></i>Recovery pending
                        </span>
                    @elseif ($accountable || $witness)
                        <span class="badge bg-light text-muted">Named</span>
                    @else
                        <span class="badge bg-light text-muted">None</span>
                    @endif
                </div>
                <div class="card-body">
                    @if ($missingAccountable || $missingWitness)
                        <div class="alert alert-danger small mb-3" role="alert">
                            <i class="fas fa-triangle-exclamation me-1"></i>
                            @if ($missingAccountable)
                                This <strong>{{ $typeLabels[$dmg->damage_type] ?? $dmg->damage_type }}</strong> damage
                                requires an <strong>accountable employee</strong> but none is set.
                            @endif
                            @if ($missingWitness)
                                @if ($missingAccountable)<br>@endif
                                This <strong>{{ $typeLabels[$dmg->damage_type] ?? $dmg->damage_type }}</strong> damage
                                requires a <strong>witness employee</strong> but none is set.
                            @endif
                            @if ($dmg->isDraft())
                                This will block confirmation. Recreate the damage with the required party, or reclassify the type.
                            @else
                                The damage was created before Phase 4 (or via the sales-return-linked auto-flow which is exempt).
                            @endif
                        </div>
                    @endif

                    <dl class="row mb-0">
                        <dt class="col-sm-4 text-muted">
                            Accountable employee
                            @if ($requireAccountable)<span class="text-danger">*</span>@endif
                        </dt>
                        <dd class="col-sm-8">{!! $renderEmployee($accountable) !!}</dd>

                        <dt class="col-sm-4 text-muted">
                            Witness
                            @if ($requireWitness)<span class="text-danger">*</span>@endif
                        </dt>
                        <dd class="col-sm-8">{!! $renderEmployee($witness) !!}</dd>

                        @if ($hasRecovery)
                            <dt class="col-sm-4 text-muted">Recovery amount</dt>
                            <dd class="col-sm-8">
                                <strong class="text-success">Tk {{ number_format($recoveryAmount, 2) }}</strong>
                                @if ($recoveryJe)
                                    <span class="badge bg-secondary-subtle text-secondary ms-1">JE {{ $recoveryJe->entry_no }}</span>
                                @endif
                                <div class="small text-muted">
                                    Posted against the accountable employee's ledger (salary deduction).
                                    Reversed automatically if this damage is cancelled.
                                </div>
                            </dd>
                        @elseif ($recoverable)
                            <dt class="col-sm-4 text-muted">Recoverable</dt>
                            <dd class="col-sm-8">
                                <strong class="text-warning">Tk {{ number_format((float) $dmg->total_value, 2) }}</strong>
                                <div class="small text-muted">
                                    No recovery posted yet. Use the “Recover from employee” action to post a salary deduction.
                                </div>
                            </dd>
                        @endif
                    </dl>
                </div>
            </div>
        </div>

        {{-- Right: actions aside --}}
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white">
                    <h2 class="h6 mb-0"><i class="fas fa-gear me-1 text-secondary"></i> Status &amp; actions</h2>
                </div>
                <div class="card-body">
                    <div class="text-center mb-3">
                        <div class="text-muted small mb-1">Current status</div>
                        <div class="mb-2 fs-5">{!! $statusBadge() !!}</div>
                        <div class="text-muted small">
                            Total value: <strong class="text-danger">Tk {{ number_format((float) $dmg->total_value, 2) }}</strong>
                        </div>
                    </div>

                    {{-- CONFIRM (draft only) — admin/manager only --}}
                    @if ($dmg->isDraft() && $canPost)
                        <form method="POST" action="{{ route('admin.damages.confirm', $dmg) }}"
                              id="confirmForm">
                            @csrf
                            <input type="hidden" name="confirm_reason" id="confirmReasonField" value="">
                            @if ($photoMissing)
                                {{-- Phase 3 — confirm is blocked until a photo is uploaded.
                                     The hard gate is in DamageService::confirmDamage; this
                                     disabled button + tooltip gives the user a clear hint
                                     instead of a 500 after clicking. --}}
                                <button type="button" class="btn btn-danger w-100 mb-2" id="confirmBtn"
                                        disabled
                                        data-bs-toggle="tooltip" data-bs-placement="top"
                                        title="Upload at least one photo in the Evidence card first">
                                    <i class="fas fa-lock me-1"></i> Confirm damage
                                </button>
                            @else
                                <button type="button" class="btn btn-danger w-100 mb-2" id="confirmBtn">
                                    <i class="fas fa-circle-check me-1"></i> Confirm damage
                                </button>
                            @endif
                        </form>
                        <div class="alert alert-warning small mb-3">
                            <i class="fas fa-triangle-exclamation me-1"></i>
                            Confirming will <strong>write off stock</strong> (OUT) and <strong>post a GL loss</strong>
                            (Dr Damage Loss / Cr Inventory).
                            @if ($photoRequired)
                                <br>
                                <i class="fas fa-camera me-1"></i>
                                This damage type requires
                                <strong>at least one photo</strong> before confirm.
                            @endif
                        </div>
                    @endif

                    {{-- CANCEL (draft or confirmed) — admin/manager only --}}
                    @if (($dmg->isDraft() || $dmg->isConfirmed()) && $canPost)
                        <form method="POST" action="{{ route('admin.damages.cancel', $dmg) }}"
                              id="cancelForm">
                            @csrf
                            <input type="hidden" name="cancel_reason" id="cancelReasonField" value="">
                            <button type="button" class="btn btn-outline-danger w-100" id="cancelBtn">
                                <i class="fas fa-ban me-1"></i>
                                @if ($dmg->isConfirmed())
                                    Cancel &amp; reverse
                                @else
                                    Cancel draft
                                @endif
                            </button>
                        </form>
                        @if ($dmg->isConfirmed())
                            <div class="alert alert-danger small mt-2 mb-0">
                                <i class="fas fa-triangle-exclamation me-1"></i>
                                Cancelling a confirmed damage <strong>reverses the stock write-off and the GL entry</strong>.
                                A reason is required.
                            </div>
                        @endif
                    @endif

                    {{-- Phase 4 — Recover from employee (confirmed + accountable +
                         no prior recovery + admin/manager + same-branch). Posts a
                         one-shot GL entry (Dr employee_payable / Cr loss) + an
                         employee_ledger deduction row. One-shot: to undo a
                         recovery, cancel the damage (which reverses both). --}}
                    @if ($canRecover)
                        <hr class="my-3">
                        <form method="POST" action="{{ route('admin.damages.recover', $dmg) }}" id="recoverForm">
                            @csrf
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <i class="fas fa-hand-holding-dollar text-warning"></i>
                                <span class="fw-semibold small">Recover from employee</span>
                            </div>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text">Tk</span>
                                <input type="number" name="recovery_amount" id="recoveryAmountField"
                                       class="form-control @error('recovery_amount') is-invalid @enderror"
                                       step="0.01" min="0.01"
                                       max="{{ number_format((float) $dmg->total_value, 2, '.', '') }}"
                                       value="{{ old('recovery_amount', number_format((float) $dmg->total_value, 2, '.', '')) }}"
                                       required>
                                <button type="button" class="btn btn-warning" id="recoverBtn">
                                    <i class="fas fa-check me-1"></i> Post
                                </button>
                            </div>
                            @error('recovery_amount')
                                <div class="invalid-feedback d-block">{{ $message }}</div>
                            @enderror
                            <div class="form-text small mt-1">
                                @if ($accountable)
                                    Debits <strong>{{ $accountable->name }}</strong>'s ledger (salary deduction).
                                    Max Tk {{ number_format((float) $dmg->total_value, 2) }}.
                                @endif
                            </div>
                        </form>
                    @endif

                    {{-- Phase 4 — if a recovery was already posted, show a read-only
                         summary in the actions aside (the full detail is in the
                         Accountability card). --}}
                    @if ($hasRecovery && $dmg->isConfirmed())
                        <div class="alert alert-success small mb-0 mt-2">
                            <i class="fas fa-circle-check me-1"></i>
                            Tk {{ number_format($recoveryAmount, 2) }} recovered from
                            <strong>{{ $accountable?->name ?? 'employee' }}</strong>.
                            <div class="text-muted small mt-1">
                                Cancel the damage to reverse the recovery (and the write-off).
                            </div>
                        </div>
                    @endif

                    {{-- Phase 0 (Damage plan): warehouse_manager sees this note instead of
                        the confirm/cancel buttons (which would 403). --}}
                    @if (($dmg->isDraft() || $dmg->isConfirmed()) && !$canPost)
                        <div class="alert alert-info small mb-0">
                            <i class="fas fa-circle-info me-1"></i>
                            @if ($dmg->isDraft())
                                This draft damage must be <strong>confirmed</strong> by a manager or admin
                                to write off stock and post the GL loss.
                            @else
                                This confirmed damage can only be <strong>cancelled/reversed</strong> by a
                                manager or admin.
                            @endif
                        </div>
                    @endif

                    @if ($dmg->isCancelled())
                        <div class="alert alert-secondary small mb-0">
                            <i class="fas fa-ban me-1"></i>
                            This damage invoice is cancelled and cannot be modified further.
                        </div>
                    @endif
                </div>
            </div>

            {{-- Reversal info card (if reversed) --}}
            @if ($dmg->is_reversed)
                <div class="card border-danger shadow-sm mb-3">
                    <div class="card-header bg-danger-subtle">
                        <h2 class="h6 mb-0 text-danger">
                            <i class="fas fa-rotate-left me-1"></i> Reversal info
                        </h2>
                    </div>
                    <div class="card-body small">
                        <div class="d-flex justify-content-between py-1 border-bottom">
                            <span class="text-muted">Reversed at</span>
                            <strong>
                                @if ($dmg->reversed_at)
                                    {{ \Carbon\Carbon::parse($dmg->reversed_at)->format('d M Y H:i') }}
                                @else
                                    —
                                @endif
                            </strong>
                        </div>
                        <div class="d-flex justify-content-between py-1 border-bottom">
                            <span class="text-muted">Reversed by</span>
                            <strong>{{ $dmg->reversed_by ? ('User #' . $dmg->reversed_by) : '—' }}</strong>
                        </div>
                        <div class="py-1">
                            <span class="text-muted d-block">Reason</span>
                            <span>{{ $dmg->reverse_reason ?: '—' }}</span>
                        </div>
                    </div>
                </div>
            @endif

            {{-- Quick facts card --}}
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white">
                    <h2 class="h6 mb-0"><i class="fas fa-circle-info me-1 text-muted"></i> Quick facts</h2>
                </div>
                <div class="card-body small">
                    <div class="d-flex justify-content-between py-1 border-bottom">
                        <span class="text-muted">Items</span>
                        <strong>{{ $dmg->items->count() }}</strong>
                    </div>
                    <div class="d-flex justify-content-between py-1 border-bottom">
                        <span class="text-muted">Total value</span>
                        <strong class="text-danger">Tk {{ number_format((float) $dmg->total_value, 2) }}</strong>
                    </div>
                    <div class="d-flex justify-content-between py-1 border-bottom">
                        <span class="text-muted">Stock transactions</span>
                        <strong>{{ is_iterable($stockMovements) ? count($stockMovements) : 0 }}</strong>
                    </div>
                    <div class="d-flex justify-content-between py-1 border-bottom">
                        <span class="text-muted">GL journal</span>
                        @if ($je)
                            <strong>{{ $je->entry_no }}</strong>
                        @else
                            <span class="text-muted">Not posted</span>
                        @endif
                    </div>
                    <div class="d-flex justify-content-between py-1">
                        <span class="text-muted">Reversed</span>
                        @if ($dmg->is_reversed)
                            <span class="badge bg-danger-subtle text-danger">Yes</span>
                        @else
                            <span class="text-muted">No</span>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Phase 3 — Evidence lightbox modal (Bootstrap 5). Shows the full-res image
     or an embedded PDF when a thumbnail is clicked. Reused for every
     attachment via data attributes (no per-item modal markup). --}}
<div class="modal fade" id="evidenceLightbox" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content bg-dark">
            <div class="modal-header border-0">
                <h5 class="modal-title text-light small" id="evidenceLightboxTitle">Evidence</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0 text-center" id="evidenceLightboxBody">
                {{-- Filled by JS: <img> or <iframe> depending on mime type --}}
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
$(function () {
    // ====== Confirm (draft → confirmed) ======
    $('#confirmBtn').on('click', function () {
        Swal.fire({
            icon: 'warning',
            title: 'Confirm this damage?',
            html: '<p class="text-start">Confirming will <strong>write off stock</strong> and <strong>post a GL loss</strong> (Dr Damage Loss / Cr Inventory).</p>',
            input: 'textarea',
            inputLabel: 'Optional confirm reason',
            inputPlaceholder: 'e.g. Approved by manager after physical inspection.',
            showCancelButton: true,
            confirmButtonText: '<i class="fas fa-check"></i> Confirm',
            confirmButtonColor: '#dc2626',
            cancelButtonText: 'Cancel',
            reverseButtons: true
        }).then(function (result) {
            if (result.isConfirmed) {
                $('#confirmReasonField').val(result.value || '');
                var $btn = $('#confirmBtn');
                $btn.prop('disabled', true)
                    .html('<i class="fas fa-spinner fa-spin me-1"></i> Confirming…');
                $('#confirmForm').submit();
            }
        });
    });

    // ====== Cancel (draft or confirmed) ======
    $('#cancelBtn').on('click', function () {
        var isConfirmed = @json($dmg->isConfirmed());
        var title = isConfirmed ? 'Cancel & reverse this damage?' : 'Cancel this draft?';
        var html  = isConfirmed
            ? '<p class="text-start">This will <strong>reverse the stock write-off and the GL journal entry</strong>. A reason is required.</p>'
            : '<p class="text-start">The draft will be marked cancelled. A reason is required.</p>';

        Swal.fire({
            icon: 'warning',
            title: title,
            html: html,
            input: 'textarea',
            inputLabel: 'Cancel reason (required)',
            inputPlaceholder: 'Why is this damage being cancelled?',
            showCancelButton: true,
            confirmButtonText: '<i class="fas fa-ban"></i> Cancel damage',
            confirmButtonColor: '#dc3545',
            cancelButtonText: 'Keep',
            reverseButtons: true,
            inputValidator: function (value) {
                if (!value || !value.trim()) {
                    return 'A cancel reason is required.';
                }
                return null;
            }
        }).then(function (result) {
            if (result.isConfirmed) {
                $('#cancelReasonField').val(result.value.trim());
                var $btn = $('#cancelBtn');
                $btn.prop('disabled', true)
                    .html('<i class="fas fa-spinner fa-spin me-1"></i> Cancelling…');
                $('#cancelForm').submit();
            }
        });
    });

    // ====== Phase 4 — Recover from employee ======
    // SweetAlert confirmation before posting a salary-deduction recovery
    // against the accountable employee. The form itself is a plain POST to
    // admin.damages.recover; this just asks "are you sure?" with the amount
    // pre-filled (the user can adjust it in the modal input).
    $('#recoverBtn').on('click', function () {
        var maxAmount = @json((float) $dmg->total_value);
        var employeeName = @json($accountable?->name ?? 'the employee');
        var currentVal = parseFloat($('#recoveryAmountField').val()) || 0;

        Swal.fire({
            icon: 'warning',
            title: 'Recover from employee?',
            html: '<p class="text-start">This will debit <strong>' + $('<div>').text(employeeName).html()
                + '</strong>\'s employee ledger (salary deduction) and credit the damage loss ledger.</p>',
            input: 'number',
            inputValue: currentVal.toFixed(2),
            inputAttributes: { step: '0.01', min: '0.01', max: maxAmount.toFixed(2) },
            inputLabel: 'Amount to recover (Tk)',
            showCancelButton: true,
            confirmButtonText: '<i class="fas fa-hand-holding-dollar"></i> Post recovery',
            confirmButtonColor: '#d97706',
            cancelButtonText: 'Cancel',
            reverseButtons: true,
            inputValidator: function (value) {
                var v = parseFloat(value);
                if (!v || v <= 0) {
                    return 'Enter an amount greater than zero.';
                }
                if (v > maxAmount + 0.01) {
                    return 'Amount cannot exceed Tk ' + maxAmount.toFixed(2) + '.';
                }
                return null;
            }
        }).then(function (result) {
            if (result.isConfirmed) {
                $('#recoveryAmountField').val(parseFloat(result.value).toFixed(2));
                var $btn = $('#recoverBtn');
                $btn.prop('disabled', true)
                    .html('<i class="fas fa-spinner fa-spin me-1"></i> Posting…');
                $('#recoverForm').submit();
            }
        });
    });

    // ====== Phase 3 — Evidence card ======

    // Bootstrap tooltips (used on the gated Confirm button).
    var $tt = $('[data-bs-toggle="tooltip"]');
    if ($tt.length && window.bootstrap) {
        $tt.each(function () { new bootstrap.Tooltip(this); });
    }

    // Dropzone: click-to-browse + drag-and-drop.
    var $dz = $('#evidenceDropzone');
    var $fileInput = $('#evidenceFileInput');
    var $submitBtn = $('#evidenceSubmitBtn');
    var $form = $('#evidenceUploadForm');

    if ($dz.length) {
        // Click anywhere on the dropzone (except the caption input) opens the
        // file picker.
        $dz.on('click', function (e) {
            if (e.target.tagName === 'INPUT' || e.target.tagName === 'LABEL') return;
            $fileInput.trigger('click');
        });

        // Drag-and-drop highlight.
        $dz.on('dragover', function (e) {
            e.preventDefault();
            $dz.addClass('border-danger bg-danger-subtle');
        }).on('dragleave drop', function (e) {
            e.preventDefault();
            $dz.removeClass('border-danger bg-danger-subtle');
        });

        $dz.on('drop', function (e) {
            if (e.originalEvent.dataTransfer.files.length) {
                $fileInput[0].files = e.originalEvent.dataTransfer.files;
                handleFileSelected();
            }
        });

        $fileInput.on('change', handleFileSelected);

        function handleFileSelected() {
            if ($fileInput[0].files && $fileInput[0].files[0]) {
                $submitBtn.removeClass('d-none');
                // Auto-submit for a single drag-dropped file (feels instant;
                // user can still add a caption first if they used "browse").
            }
        }

        // Auto-submit the form when a file is chosen via drag-drop (single
        // file). For the "browse" path the user clicks Upload after typing
        // an optional caption.
        $form.on('submit', function () {
            if (!$fileInput[0].files || !$fileInput[0].files[0]) {
                Swal.fire({
                    icon: 'info',
                    title: 'Choose a file first',
                    timer: 1800,
                    showConfirmButton: false
                });
                return false;
            }
            $submitBtn.prop('disabled', true)
                .html('<i class="fas fa-spinner fa-spin me-1"></i> Uploading…');
            return true;
        });
    }

    // Delete-attachment confirm (SweetAlert → POST _method=DELETE).
    $(document).on('click', '.evidence-delete-btn', function () {
        var url = $(this).data('delete-url');
        var name = $(this).data('file-name');
        Swal.fire({
            icon: 'warning',
            title: 'Remove this evidence?',
            html: '<p class="text-start"><strong>' + $('<div>').text(name).html() + '</strong><br>This will delete the file permanently. Only available while the damage is a draft.</p>',
            showCancelButton: true,
            confirmButtonText: '<i class="fas fa-trash"></i> Remove',
            confirmButtonColor: '#dc3545',
            cancelButtonText: 'Keep',
            reverseButtons: true
        }).then(function (result) {
            if (result.isConfirmed) {
                // Synthetic DELETE form (the route is DELETE).
                var $f = $('<form method="POST" action="' + url + '"><input type="hidden" name="_method" value="DELETE"></form>');
                $f.append($('<input>').attr({ type: 'hidden', name: '_token', value: $('meta[name="csrf-token"]').attr('content') || $('[name="_token"]').first().val() }));
                $('body').append($f);
                $f.trigger('submit');
            }
        });
    });

    // Lightbox: populate the modal body with an <img> or <iframe> based on
    // the clicked thumbnail's data-lightbox-src. Built via jQuery DOM
    // creation (NOT string concatenation) so a filename containing quotes
    // or angle brackets can't break out of an attribute (XSS hardening —
    // file_name is user-supplied).
    var $lbBody = $('#evidenceLightboxBody');
    var $lbTitle = $('#evidenceLightboxTitle');
    $(document).on('click', '[data-bs-toggle="modal"][data-lightbox-src]', function () {
        var src = $(this).data('lightbox-src');
        var name = $(this).data('lightbox-name') || 'Evidence';
        $lbTitle.text(name);  // .text() escapes HTML
        $lbBody.empty();
        if (/\.pdf$/i.test(name)) {
            $('<iframe>').attr({
                src: src,
                title: 'Evidence PDF'
            }).css({ width: '100%', height: '80vh', border: '0' }).appendTo($lbBody);
        } else {
            $('<img>').attr({
                src: src,
                alt: name,
                class: 'img-fluid'
            }).css({ maxHeight: '85vh', width: 'auto' }).appendTo($lbBody);
        }
    });
    // Clear the modal body on close so a stale image doesn't flash on reopen.
    var $lbModal = $('#evidenceLightbox');
    $lbModal.on('hidden.bs.modal', function () { $lbBody.empty(); });

    // ====== Phase 3 — SSE auto-refresh on attachment changes ======
    // When another tab (or another user) uploads / removes an attachment,
    // PostgreSQL fires rcerp_damage_attachment_change → ListenNotifyWorker
    // → Redis → SSE. We listen on that channel and reload the page if the
    // payload's damage_invoice_id matches THIS damage, so the gallery +
    // integrity "evidence" check + the confirm-button gating stay live
    // without manual refresh. Reuses the shared window.rcerpEventSource
    // (exposed by notification.js) so we don't open a second SSE connection.
    (function attachSseListener() {
        var currentDamageId = @json((int) $dmg->id);
        var attempts = 0;
        function tryAttach() {
            var es = window.rcerpEventSource;
            if (es && typeof es.addEventListener === 'function') {
                es.addEventListener('rcerp_damage_attachment_change', function (ev) {
                    try {
                        var data = JSON.parse(ev.data || '{}');
                        var payload = data.payload || data;
                        if (parseInt(payload.damage_invoice_id, 10) === currentDamageId) {
                            // Soft reload: keep scroll position (browser default).
                            window.location.reload();
                        }
                    } catch (e) { /* ignore malformed payload */ }
                });
            } else if (attempts++ < 40) {
                // notification.js creates the EventSource asynchronously
                // after /sse/status resolves; retry up to ~20s.
                setTimeout(tryAttach, 500);
            }
        }
        tryAttach();
    })();
});
</script>
@endpush
@endsection
