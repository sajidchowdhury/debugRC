<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DamageAttachment;
use App\Models\DamageInvoice;
use App\Models\DamageReason;
use App\Models\Warehouse;
use App\Models\Product;
use App\Models\Branch;
use App\Services\Stock\DamageIntegrityService;
use App\Services\Stock\DamageService;
use App\Services\Stock\StockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Damage Controller — Phase 6.6.
 *
 * Two-phase flow (same as 6.3/6.4/6.5):
 *   - create / store: create a draft damage (no stock, no GL)
 *   - show: detail with items + stock movements + GL journal
 *   - confirm: apply stock OUT + post GL (Dr Damage Loss / Cr Inventory)
 *   - cancel: reverse if confirmed, or mark draft as cancelled
 */
class DamageController extends Controller
{
    public function __construct(
        private DamageService $damageService,
        private StockService $stockService,
        private DamageIntegrityService $integrityService
    ) {}

    public function index(Request $request)
    {
        // Phase 0 (Damage plan): defense-in-depth policy check behind the
        // role:admin,manager,warehouse_manager route middleware.
        $this->authorize('viewAny', DamageInvoice::class);

        $query = DamageInvoice::with(['warehouse.branch', 'items'])
            ->when($request->input('from_date'), fn($q, $d) => $q->where('damage_date', '>=', $d))
            ->when($request->input('to_date'), fn($q, $d) => $q->where('damage_date', '<=', $d))
            ->when($request->input('warehouse_id'), fn($q, $wid) => $q->where('warehouse_id', $wid))
            ->when($request->input('status'), fn($q, $s) => $q->where('status', $s))
            ->when($request->input('damage_type'), fn($q, $t) => $q->where('damage_type', $t))
            ->when($request->input('branch_id'), fn($q, $bid) => $q->where('branch_id', $bid))
            ->when($request->input('search'), function ($q, $search) {
                $q->where('damage_code', 'ILIKE', "%{$search}%");
            })
            ->orderBy('damage_date', 'desc')
            ->orderBy('id', 'desc');

        $damages = $query->paginate(25);

        $warehouses = Warehouse::active()->with('branch')->orderBy('warehouse_name')->get();
        $branches = Branch::active()->orderBy('branch_name')->get();

        $stats = [
            'total' => DamageInvoice::count(),
            'draft' => DamageInvoice::where('status', 'draft')->count(),
            'confirmed' => DamageInvoice::where('status', 'confirmed')->count(),
            'cancelled' => DamageInvoice::where('status', 'cancelled')->count(),
            'total_value' => DamageInvoice::where('status', 'confirmed')->sum('total_value'),
            // Phase 1 — per-type counts for the accountability dashboard.
            'missing_count' => DamageInvoice::where('damage_type', 'missing')->count(),
            'theft_count' => DamageInvoice::where('damage_type', 'theft')->count(),
        ];

        return view('admin.damages.index', [
            'title' => 'Damages',
            'damages' => $damages,
            'warehouses' => $warehouses,
            'branches' => $branches,
            'damageTypes' => DamageInvoice::DAMAGE_TYPES,
            'damageTypeLabels' => DamageInvoice::DAMAGE_TYPE_LABELS,
            'stats' => $stats,
            'filters' => $request->only(['from_date', 'to_date', 'warehouse_id', 'status', 'damage_type', 'branch_id', 'search']),
        ]);
    }

    public function create()
    {
        // Phase 0 (Damage plan): defense-in-depth policy check.
        $this->authorize('create', DamageInvoice::class);

        $warehouses = Warehouse::active()->with('branch')->orderBy('warehouse_name')->get();
        $products = Product::active()->orderBy('product_name')->limit(500)->get();

        // Phase 1 — load the reason taxonomy grouped by damage_type for the
        // type-filtered dropdown on the create form.
        $damageReasons = DamageReason::groupedByType();

        return view('admin.damages.create', [
            'title' => 'New Damage Invoice',
            'warehouses' => $warehouses,
            'products' => $products,
            'damageTypes' => DamageInvoice::DAMAGE_TYPES,
            'damageTypeLabels' => DamageInvoice::DAMAGE_TYPE_LABELS,
            'damageReasons' => $damageReasons,
        ]);
    }

    public function store(Request $request)
    {
        // Phase 0 (Damage plan): defense-in-depth policy check.
        $this->authorize('create', DamageInvoice::class);

        $validated = $request->validate([
            'warehouse_id' => 'required|integer|exists:warehouses,id',
            'damage_date' => 'required|date',
            // Phase 1 — damage_type is required and must be a known enum.
            'damage_type' => ['required', 'string', Rule::in(DamageInvoice::DAMAGE_TYPES)],
            // reason_code is optional but if supplied MUST be an active reason
            // belonging to the chosen damage_type (so the dropdown filter is
            // authoritative). DamageService re-validates this as a backstop.
            'reason_code' => [
                'nullable', 'string', 'max:50',
                Rule::exists('damage_reasons', 'reason_code')->where(function ($q) use ($request) {
                    $q->where('damage_type', $request->input('damage_type'))
                      ->where('is_active', true);
                }),
            ],
            'reason_detail' => 'nullable|string|max:2000',
            'reason' => 'nullable|string|max:1000',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.qty' => 'required|numeric|min:0.001',
            'items.*.rate' => 'nullable|numeric|min:0',
        ]);

        try {
            $damage = $this->damageService->createDamage([
                'warehouse_id' => $validated['warehouse_id'],
                'damage_date' => $validated['damage_date'],
                'damage_type' => $validated['damage_type'],
                'reason_code' => $validated['reason_code'] ?? '',
                'reason_detail' => $validated['reason_detail'] ?? '',
                'reason' => $validated['reason'] ?? '',
                'items' => $validated['items'],
                'created_by' => auth()->id(),
            ]);
            return redirect()->route('admin.damages.show', $damage)
                ->with('success', "Draft damage {$damage->damage_code} created. Review and confirm to apply.");
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function show(int $id)
    {
        $damage = DamageInvoice::with([
            'items.product', 'warehouse.branch', 'branch',
            'journalEntry.lines.ledger',
            // Phase 1 — eager-load the structured reason label for display.
            'reasonTaxonomy',
            // Phase 3 — eager-load evidence attachments + uploader for the
            // Evidence card. Avoids N+1 on the gallery render.
            'attachments.uploadedBy',
        ])->findOrFail($id);

        // Phase 0 (Damage plan): defense-in-depth policy check (same-branch
        // for non-admins). branch.isolation middleware already gated the
        // request; this re-confirms on the loaded model.
        $this->authorize('view', $damage);

        $stockMovements = [];
        if ($damage->isConfirmed() || $damage->is_reversed) {
            $stockMovements = DB::table('stock_transactions as st')
                ->join('products as p', 'p.id', '=', 'st.product_id')
                ->where('st.reference_type', 'damage')
                ->where('st.reference_id', $id)
                ->select('st.*', 'p.product_code', 'p.product_name')
                ->orderBy('st.id')
                ->get();
        }

        // Phase 2 — live-computed integrity panel (ports legacy
        // DamageAuditModel::runDamageChecks). Read-only, indexed lookups,
        // safe to run on every detail-page render. Surfaces drift between
        // the damage header, its items, stock_transactions and GL journal
        // so reconciliation issues are visible at a glance instead of
        // silently accumulating. Passes the already-eager-loaded $damage
        // model so the service doesn't re-query the header.
        $integrity = $this->integrityService->runChecks($damage);

        return view('admin.damages.show', [
            'title' => 'Damage ' . $damage->damage_code,
            'damage' => $damage,
            'stockMovements' => $stockMovements,
            'integrity' => $integrity,
        ]);
    }

    public function confirm(Request $request, int $id)
    {
        // Phase 0 (Damage plan): defense-in-depth policy check. Loads the
        // model first so the policy can verify same-branch for non-admins.
        $damage = DamageInvoice::findOrFail($id);
        $this->authorize('confirm', $damage);

        $request->validate([
            'confirm_reason' => 'nullable|string|max:500',
        ]);

        try {
            $damage = $this->damageService->confirmDamage($id, auth()->id());
            return redirect()->route('admin.damages.show', $damage)
                ->with('success', "Damage {$damage->damage_code} confirmed. Stock written off + GL posted.");
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function cancel(Request $request, int $id)
    {
        // Phase 0 (Damage plan): defense-in-depth policy check.
        $damage = DamageInvoice::findOrFail($id);
        $this->authorize('cancel', $damage);

        $request->validate([
            'cancel_reason' => 'required|string|max:500',
        ]);

        try {
            $damage = $this->damageService->cancelDamage($id, auth()->id(), $request->input('cancel_reason'));
            return redirect()->route('admin.damages.show', $damage)
                ->with('success', "Damage {$damage->damage_code} cancelled.");
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * AJAX: get product stock + rate for a warehouse.
     */
    public function getProductStock(Request $request)
    {
        // Phase 0 (Damage plan): defense-in-depth policy check.
        $this->authorize('viewProductStock', DamageInvoice::class);

        $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'warehouse_id' => 'required|integer|exists:warehouses,id',
        ]);

        $rate = $this->stockService->getWarehouseAvgCost(
            (int) $request->input('warehouse_id'),
            (int) $request->input('product_id')
        );
        $qty = $this->stockService->getWarehouseQty(
            (int) $request->input('warehouse_id'),
            (int) $request->input('product_id')
        );

        return response()->json([
            'rate' => round($rate, 2),
            'available_qty' => round($qty, 4),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Phase 3 — Photo / Evidence Attachments
    |--------------------------------------------------------------------------
    */

    /**
     * Upload an evidence attachment to a draft damage.
     *
     * Stores the file on the configured `local` (private) disk — NOT the
     * public disk — because evidence is sensitive (theft scenes, damaged
     * inventory, possibly identifying employees). Files are served only via
     * the authorized viewAttachment() route, so RLS actually means something.
     */
    public function uploadAttachment(Request $request, int $id)
    {
        $damage = DamageInvoice::findOrFail($id);
        $this->authorize('uploadAttachment', $damage);

        $maxKb    = (int) config('damage.attachment_max_size_kb', DamageAttachment::MAX_FILE_SIZE_KB);
        $maxCount = (int) config('damage.attachment_max_per_damage', DamageAttachment::MAX_PER_DAMAGE);
        $diskName = (string) config('damage.attachment_disk', 'local');
        $folder   = (string) config('damage.attachment_folder', 'damage-evidence');

        $request->validate([
            'file'    => ['required', 'file', 'max:' . $maxKb, 'mimes:jpg,jpeg,png,webp,pdf'],
            'caption' => ['nullable', 'string', 'max:255'],
        ]);

        $file = $request->file('file');
        if (!$file->isValid()) {
            return back()->with('error', 'Uploaded file is not valid.');
        }

        // Enforce the per-damage count limit BEFORE storing (avoids orphaned
        // files when the limit is hit). RLS + the draft-only policy gate
        // already prevent cross-branch / post-confirm uploads.
        $currentCount = $damage->attachments()->count();
        if ($currentCount >= $maxCount) {
            return back()->with('error', "Attachment limit reached ({$maxCount} per damage). Remove one before adding another.");
        }

        $mime     = $file->getMimeType() ?: 'application/octet-stream';
        $origName = $file->getClientOriginalName() ?: 'evidence';
        $size     = (int) $file->getSize();

        // Store under damage-evidence/{damage_id}/ so a future cleanup job
        // (orphaned-file sweep) can prune by directory. Random filename to
        // avoid collisions + path-traversal via user-supplied names.
        $storedPath = $file->storeAs(
            $folder . '/' . $id,
            bin2hex(random_bytes(16)) . '.' . ($file->getClientOriginalExtension() ?: 'bin'),
            $diskName
        );

        if ($storedPath === false) {
            return back()->with('error', 'Could not store the uploaded file. Check disk permissions.');
        }

        DamageAttachment::create([
            'damage_invoice_id' => $damage->id,
            'file_path'         => $storedPath,
            'file_name'         => $origName,
            'mime_type'         => $mime,
            'file_size'         => $size,
            'caption'           => trim((string) $request->input('caption')) ?: null,
            'uploaded_by'       => auth()->id(),
            'created_at'        => now(),
        ]);

        return redirect()->route('admin.damages.show', $damage)
            ->with('success', 'Evidence uploaded.');
    }

    /**
     * Delete an evidence attachment (draft only — policy enforces).
     *
     * Removes the physical file FIRST, then the DB row. If the file delete
     * fails (disk error), the DB row is still removed — the row is the
     * source of truth for the UI, and a stale orphaned file is preferable
     * to a dangling DB row pointing at nothing (which would 404 on view).
     * A scheduled cleanup job can sweep orphans later.
     */
    public function deleteAttachment(int $id, int $attachmentId)
    {
        $damage = DamageInvoice::findOrFail($id);
        $this->authorize('deleteAttachment', $damage);

        /** @var DamageAttachment|null $attachment */
        $attachment = $damage->attachments()->where('id', $attachmentId)->first();
        if (!$attachment) {
            return back()->with('error', 'Attachment not found on this damage.');
        }

        $diskName = (string) config('damage.attachment_disk', 'local');
        try {
            if (Storage::disk($diskName)->exists($attachment->file_path)) {
                Storage::disk($diskName)->delete($attachment->file_path);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Damage attachment file delete failed (DB row will still be removed)', [
                'attachment_id' => $attachment->id,
                'file_path'     => $attachment->file_path,
                'error'         => $e->getMessage(),
            ]);
        }

        $attachment->delete();

        return redirect()->route('admin.damages.show', $damage)
            ->with('success', 'Evidence removed.');
    }

    /**
     * Stream an evidence attachment inline (for the gallery / lightbox <img>).
     *
     * Authorization: DamagePolicy::viewAttachment (role + same-branch) +
     * branch.isolation middleware + RLS on damage_attachments. The file is
     * read from the `local` (private) disk and streamed with the correct
     * Content-Type so the browser renders it inline.
     */
    public function viewAttachment(int $id, int $attachmentId)
    {
        $damage = DamageInvoice::findOrFail($id);
        $this->authorize('viewAttachment', $damage);

        /** @var DamageAttachment|null $attachment */
        $attachment = $damage->attachments()->where('id', $attachmentId)->first();
        if (!$attachment) {
            abort(404, 'Attachment not found.');
        }

        return $this->streamAttachment($attachment, inline: true);
    }

    /**
     * Force-download an evidence attachment (Content-Disposition: attachment).
     */
    public function downloadAttachment(int $id, int $attachmentId)
    {
        $damage = DamageInvoice::findOrFail($id);
        $this->authorize('viewAttachment', $damage);

        /** @var DamageAttachment|null $attachment */
        $attachment = $damage->attachments()->where('id', $attachmentId)->first();
        if (!$attachment) {
            abort(404, 'Attachment not found.');
        }

        return $this->streamAttachment($attachment, inline: false);
    }

    /**
     * Stream a damage attachment file from the private disk with the right
     * headers. Centralizes the disk read so view + download share the same
     * authorization + 404 handling.
     */
    private function streamAttachment(DamageAttachment $attachment, bool $inline)
    {
        $diskName = (string) config('damage.attachment_disk', 'local');
        $disk     = Storage::disk($diskName);

        if (!$disk->exists($attachment->file_path)) {
            abort(404, 'Evidence file is missing from storage.');
        }

        $disposition = $inline ? 'inline' : 'attachment';
        // Sanitize filename for the Content-Disposition header (RFC 5987
        // fallback for non-ASCII names — avoids header injection).
        $safeName = str_replace(['"', "\r", "\n"], '', $attachment->file_name);

        return response($disk->get($attachment->file_path), 200, [
            'Content-Type'        => $attachment->mime_type,
            'Content-Length'      => (string) $attachment->file_size,
            'Content-Disposition' => $disposition . '; filename="' . $safeName . '"',
            'Cache-Control'       => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
