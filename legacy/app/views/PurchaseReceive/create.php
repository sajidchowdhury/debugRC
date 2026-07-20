<?php
ob_start();
$title = 'New Purchase Receive';
$poJson = json_encode($pos);
$warehouseJson = json_encode($warehouses);
?>
<div class="container-fluid py-4">
    <div class="row justify-content-center">
        <div class="col-lg-11 col-xl-10">

            <!-- Header -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h3 class="mb-1 fw-bold">
                        <i class="fas fa-truck-loading text-primary me-2"></i> New Purchase Receive
                    </h3>
                    <p class="text-muted mb-0">Record goods received from supplier (GRN)</p>
                </div>
                <a href="<?= BASE_URL ?>PurchaseReceive" class="btn btn-outline-secondary">
                    <i class="fas fa-arrow-left me-1"></i> Back to List
                </a>
            </div>

            <form id="receiveForm" method="POST" action="<?= BASE_URL ?>PurchaseReceive/store">
                <!-- CSRF Token -->
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                <!-- For JS AJAX (PurchaseReceive.js expects this) -->
                <input type="hidden" id="base_url" value="<?= BASE_URL ?>">

                <!-- Mode Toggle -->
                <div class="card shadow-sm border-0 mb-4">
                    <div class="card-body py-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="directPurchaseToggle" onchange="toggleDirectPurchaseMode()">
                            <label class="form-check-label fw-medium" for="directPurchaseToggle">
                                <strong>Direct Purchase</strong> <span class="text-muted">(Receive without Purchase Order)</span>
                            </label>
                        </div>
                    </div>
                </div>

                <div class="row g-4">

                    <!-- Left: Basic Information -->
                    <div class="col-lg-5">
                        <div class="card shadow-sm border-0 h-100">
                            <div class="card-header bg-light py-3">
                                <h6 class="mb-0 fw-semibold"><i class="fas fa-info-circle me-2"></i> Basic Information</h6>
                            </div>
                            <div class="card-body">

                                <!-- PO Selection (shown when not Direct) -->
                                <div id="poSelectionRow">
                                    <div class="mb-3">
                                        <label class="form-label fw-medium">Select Purchase Order <span class="text-danger">*</span></label>
                                        <select id="poSelect" class="form-select" onchange="loadPODetails()" required>
                                            <option value="">-- Select PO --</option>
                                            <?php foreach ($pos as $po): ?>
                                                <option value="<?= $po['id'] ?>">
                                                    <?= htmlspecialchars($po['po_code']) ?> - <?= htmlspecialchars($po['supplier_name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>

                                <!-- Direct Purchase Supplier -->
                                <div id="directSupplierRow" class="d-none">
                                    <div class="mb-3">
                                        <label class="form-label fw-medium">Supplier <span class="text-danger">*</span></label>
                                        <select name="supplier_id" id="supplierSelect" class="form-select">
                                            <option value="">-- Select Supplier --</option>
                                            <?php foreach ($suppliers as $s): ?>
                                                <option value="<?= $s['id'] ?>">
                                                    <?= htmlspecialchars($s['supplier_name']) ?> (<?= htmlspecialchars($s['supplier_code']) ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-7">
                                        <label class="form-label fw-medium">Receive Date <span class="text-danger">*</span></label>
                                        <input type="date" name="receive_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                                    </div>
                                </div>

                            </div>
                        </div>
                    </div>

                    <!-- Right: Remarks -->
                    <div class="col-lg-7">
                        <div class="card shadow-sm border-0 h-100">
                            <div class="card-header bg-light py-3">
                                <h6 class="mb-0 fw-semibold"><i class="fas fa-comment-dots me-2"></i> Remarks</h6>
                            </div>
                            <div class="card-body">
                                <textarea name="remarks" class="form-control" rows="3" placeholder="Any remarks about this receipt..."></textarea>
                            </div>
                        </div>
                    </div>

                </div>

                <!-- Items Section -->
                <div class="card shadow-sm border-0 mt-4">
                    <div class="card-header bg-light py-3 d-flex justify-content-between align-items-center">
                        <h6 class="mb-0 fw-semibold"><i class="fas fa-boxes me-2"></i> Items to Receive</h6>
                        <button type="button" id="addManualItemBtn" class="btn btn-sm btn-outline-primary d-none" onclick="addDirectItemRow()">
                            <i class="fas fa-plus me-1"></i> Add Item
                        </button>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-bordered mb-0" id="receiveTable">
                                <thead class="table-light">
                                    <tr>
                                        <th>Product</th>
                                        <th style="width: 120px;">Remaining</th>
                                        <th style="width: 140px;">Receive Qty</th>
                                        <th style="width: 120px;">Rate</th>
                                        <th style="width: 180px;">Warehouse</th>
                                        <th style="width: 100px;" class="text-end">Amount</th>
                                        <th style="width: 50px;"></th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>
                    <div class="card-footer bg-light text-end py-3">
                        <strong>Total Amount: <span id="totalAmount">0.00</span></strong>
                    </div>
                </div>

                <!-- Submit -->
                <div class="d-flex justify-content-end gap-2 mt-4">
                    <a href="<?= BASE_URL ?>PurchaseReceive" class="btn btn-outline-secondary px-4">Cancel</a>
                    <button type="submit" class="btn btn-primary px-5">
                        <i class="fas fa-save me-2"></i> Save Receive (GRN)
                    </button>
                </div>

            </form>

        </div>
    </div>
</div>

<script>
    window.pos = <?= $poJson ?>;
    window.warehouses = <?= $warehouseJson ?>;
    window.CSRF_TOKEN = "<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>";
</script>
<script src="<?= BASE_URL ?>assets/js/PurchaseReceive.js"></script>

<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';
?>