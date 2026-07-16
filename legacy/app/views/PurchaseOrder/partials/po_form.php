<?php
/**
 * Shared PO form body — create & edit (draft).
 * @var string $form_mode 'create'|'edit'
 * @var array $suppliers
 * @var array $po PO row (edit) or []
 * @var string $form_action
 * @var string $branch_name
 */
$formMode = $form_mode ?? 'create';
$po = $po ?? [];
$isEdit = $formMode === 'edit';
$poId = (int)($po['id'] ?? 0);
$branchName = $branch_name ?? ($_SESSION['branch_name'] ?? 'Branch');
?>
<form id="poForm" method="POST" action="<?= htmlspecialchars($form_action, ENT_QUOTES) ?>">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>">
    <input type="hidden" id="base_url" value="<?= BASE_URL ?>">
    <?php if ($isEdit): ?>
    <input type="hidden" name="po_id" value="<?= $poId ?>">
    <?php endif; ?>

    <div class="purch-po-form-layout">
        <section class="purch-po-form-card">
            <div class="purch-po-form-card-head"><i class="fas fa-info-circle me-1"></i> Order details</div>
            <div class="purch-po-form-card-body">
                <div class="mb-3">
                    <label class="form-label">Supplier <span class="text-danger">*</span></label>
                    <select name="supplier_id" class="form-select" required>
                        <option value="">Select supplier</option>
                        <?php foreach ($suppliers as $s): ?>
                        <option value="<?= (int)$s['id'] ?>"
                            <?= $isEdit && (int)($po['supplier_id'] ?? 0) === (int)$s['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($s['supplier_name'], ENT_QUOTES) ?>
                            (<?= htmlspecialchars($s['supplier_code'] ?? '', ENT_QUOTES) ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="row g-2">
                    <div class="col-6">
                        <label class="form-label">PO date <span class="text-danger">*</span></label>
                        <input type="date" name="po_date" class="form-control" required
                               value="<?= htmlspecialchars($po['po_date'] ?? date('Y-m-d'), ENT_QUOTES) ?>">
                    </div>
                    <div class="col-6">
                        <label class="form-label">Expected date</label>
                        <input type="date" name="expected_date" class="form-control"
                               value="<?= htmlspecialchars($po['expected_date'] ?? '', ENT_QUOTES) ?>">
                    </div>
                </div>
                <div class="mt-3">
                    <label class="form-label">Remarks</label>
                    <textarea name="remarks" class="form-control" rows="3"
                              placeholder="Notes for supplier or internal use"><?= htmlspecialchars($po['remarks'] ?? '', ENT_QUOTES) ?></textarea>
                </div>
                <p class="small text-muted mb-0 mt-3">
                    <i class="fas fa-building me-1"></i> Branch: <?= htmlspecialchars($branchName, ENT_QUOTES) ?>
                    · Saved as <strong>draft</strong> until you receive goods on a GRN.
                </p>
            </div>
        </section>

        <section class="purch-po-form-card purch-po-items-card">
            <div class="purch-po-form-card-head d-flex justify-content-between align-items-center">
                <span><i class="fas fa-boxes me-1"></i> Line items</span>
                <button type="button" class="btn btn-sm btn-success" id="btnAddPoItem">
                    <i class="fas fa-plus me-1"></i> Add line
                </button>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="itemTable">
                    <thead class="table-light">
                        <tr>
                            <th>Product</th>
                            <th style="width: 100px;">Qty</th>
                            <th style="width: 110px;">Rate</th>
                            <th class="text-end" style="width: 110px;">Amount</th>
                            <th style="width: 44px;"></th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
            <p class="small text-muted px-3 py-2 mb-0">
                Search by product name or code · at least one line required
            </p>
        </section>
    </div>

    <div class="purch-po-form-footer">
        <div class="purch-po-total-label">Total: <span id="totalAmount">0.00</span></div>
        <div class="purch-po-form-actions">
            <a href="<?= BASE_URL ?>PurchaseOrder<?= $isEdit ? '/Details/' . $poId : '' ?>" class="btn btn-outline-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save me-1"></i> <?= $isEdit ? 'Update purchase order' : 'Save purchase order' ?>
            </button>
        </div>
    </div>
</form>