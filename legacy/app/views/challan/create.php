<?php
$invoice = $invoice ?? [];
$canReverse = !empty($can_reverse_challan);
$status = $invoice['status'] ?? 'draft';
$isCompleted = $status === 'challan_completed';
$isGodownReady = in_array($status, ['godown_issued', 'challan_completed'], true);
$lockGodownAssignments = $isGodownReady && !$isCompleted;
$transportLocked = $isCompleted;
$itemCount = count($invoice['items'] ?? []);
$grandTotal = (float)($invoice['total_amount'] ?? 0);
$subtotal = (float)($invoice['subtotal'] ?? 0);
$discount = (float)($invoice['discount'] ?? 0);
$transport = (float)($invoice['transport_cost'] ?? 0);
$branchName = $invoice['branch_name'] ?? 'Branch';

$selectedDispatchers = array_map(static function ($d) {
    return (int)($d['id'] ?? 0);
}, $invoice['dispatchers'] ?? []);

$statusLabel = $isCompleted ? 'Challan completed' : ($isGodownReady ? 'Godown issued' : 'Pending godown');
$statusClass = $isCompleted ? 'ch-create-status-done' : ($isGodownReady ? 'ch-create-status-godown' : 'ch-create-status-pending');

$title = 'Godown & Challan — ' . ($invoice['invoice_code'] ?? '');
ob_start();
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/sales-pos.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/challan-create.css">
<meta name="theme-color" content="#d97706">

<div id="challan-create-app" class="challan-create-app container-fluid py-2"
     data-invoice-status="<?= htmlspecialchars($status, ENT_QUOTES) ?>">

    <header class="challan-create-hero">
        <div>
            <h1><i class="fas fa-dolly me-2"></i>Godown &amp; Challan</h1>
            <p>
                <span class="challan-create-invoice-chip"><?= htmlspecialchars($invoice['invoice_code'] ?? '', ENT_QUOTES) ?></span>
                · <?= htmlspecialchars($branchName, ENT_QUOTES) ?>
            </p>
            <span class="challan-create-branch-tag"><i class="fas fa-map-marker-alt me-1"></i>Warehouse dispatch</span>
        </div>
        <div class="challan-create-hero-actions d-flex gap-2 flex-shrink-0 flex-wrap justify-content-end">
            <a href="<?= BASE_URL ?>challan" class="btn btn-light btn-sm">
                <i class="fas fa-arrow-left"></i> List
            </a>
            <?php if ($isGodownReady): ?>
            <a href="<?= BASE_URL ?>challan/print_blank_godown_copy/<?= (int)$invoice['id'] ?>"
               target="_blank" rel="noopener" class="btn btn-light btn-sm" id="btnPrintBlankGodown">
                <i class="fas fa-print"></i> Blank godown
            </a>
            <?php endif; ?>
        </div>
    </header>

    <?php if ($lockGodownAssignments): ?>
    <div class="challan-create-policy challan-create-policy-godown" role="note">
        <i class="fas fa-lock"></i>
        <div>
            <strong>Godown saved</strong> — warehouses and dispatchers are locked.
            Adjust <strong>transport</strong> or <strong>CTN</strong> as needed, then use <strong>Update CTN</strong> to save.
            After that, <strong>Finalize challan</strong> deducts stock.
            Stock shown as <em>reserved</em> for this invoice; it is not deducted until challan is finalized.
        </div>
    </div>
    <?php endif; ?>

    <div class="challan-create-steps" aria-label="Workflow progress">
        <div class="challan-step is-done">
            <span class="challan-step-dot"><i class="fas fa-check"></i></span>
            <span class="challan-step-label">Invoice</span>
        </div>
        <div class="challan-step-line <?= $isGodownReady ? 'is-done' : 'is-active' ?>"></div>
        <div class="challan-step <?= $isGodownReady ? 'is-done' : ($status === 'draft' ? 'is-active' : '') ?>">
            <span class="challan-step-dot"><?= $isGodownReady ? '<i class="fas fa-check"></i>' : '2' ?></span>
            <span class="challan-step-label">Godown</span>
        </div>
        <div class="challan-step-line <?= $isCompleted ? 'is-done' : ($isGodownReady ? 'is-active' : '') ?>"></div>
        <div class="challan-step <?= $isCompleted ? 'is-done' : ($isGodownReady ? 'is-active' : '') ?>">
            <span class="challan-step-dot"><?= $isCompleted ? '<i class="fas fa-check"></i>' : '3' ?></span>
            <span class="challan-step-label">Challan</span>
        </div>
    </div>

    <div class="challan-create-summary">
        <div class="challan-create-stat">
            <span class="label">Customer</span>
            <span class="value"><?= htmlspecialchars($invoice['shop_name'] ?? '—', ENT_QUOTES) ?></span>
            <span class="sub"><?= htmlspecialchars($invoice['mobile'] ?? '', ENT_QUOTES) ?></span>
        </div>
        <div class="challan-create-stat">
            <span class="label">Invoice date</span>
            <span class="value"><?= !empty($invoice['invoice_date']) ? date('d M Y', strtotime($invoice['invoice_date'])) : '—' ?></span>
            <span class="sub"><?= htmlspecialchars($invoice['salesman_name'] ?? '', ENT_QUOTES) ?></span>
        </div>
        <div class="challan-create-stat">
            <span class="label">Items</span>
            <span class="value"><?= (int)$itemCount ?></span>
            <span class="sub">line<?= $itemCount === 1 ? '' : 's' ?></span>
        </div>
        <div class="challan-create-stat challan-create-stat-highlight">
            <span class="label">Invoice total</span>
            <span class="value" id="challan-invoice-total-display">Tk <?= number_format($grandTotal, 2) ?></span>
            <span class="sub"><span class="challan-create-status-pill <?= $statusClass ?>"><?= htmlspecialchars($statusLabel, ENT_QUOTES) ?></span></span>
        </div>
    </div>

    <?php if ($isCompleted): ?>
    <section class="challan-create-print-bar">
        <span class="challan-create-print-label"><i class="fas fa-print me-1"></i> Documents</span>
        <div class="d-flex gap-2 flex-wrap">
            <a href="<?= BASE_URL ?>challan/challan_copy/<?= (int)$invoice['id'] ?>" target="_blank" rel="noopener" class="btn btn-sm btn-info">
                <i class="fas fa-truck"></i> Challan
            </a>
            <a href="<?= BASE_URL ?>challan/godown_copy/<?= (int)$invoice['id'] ?>" target="_blank" rel="noopener" class="btn btn-sm btn-primary">
                <i class="fas fa-boxes"></i> Godown copy
            </a>
            <a href="<?= BASE_URL ?>sales/invoice_copy/<?= (int)$invoice['id'] ?>" target="_blank" rel="noopener" class="btn btn-sm btn-success">
                <i class="fas fa-file-invoice"></i> Invoice
            </a>
        </div>
    </section>
    <?php endif; ?>

    <form id="godownForm" method="post" class="challan-create-form">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>">
        <input type="hidden" name="invoice_id" id="invoice_id" value="<?= (int)($invoice['id'] ?? 0) ?>">
        <input type="hidden" id="invoice_branch_id" value="<?= (int)Helper::sessionBranchId() ?>">

        <section class="challan-create-panel">
            <div class="challan-create-panel-head">
                <i class="fas fa-user"></i>
                <span>Delivery details</span>
            </div>
            <div class="challan-create-panel-body">
                <p class="challan-create-address mb-0">
                    <i class="fas fa-location-dot me-1 text-muted"></i>
                    <?= htmlspecialchars($invoice['address'] ?? '—', ENT_QUOTES) ?>
                </p>
            </div>
        </section>

        <section class="challan-create-panel">
            <div class="challan-create-panel-head">
                <i class="fas fa-shipping-fast"></i>
                <span>Transport cost</span>
            </div>
            <div class="challan-create-panel-body">
                <div class="row g-3 align-items-end">
                    <div class="col-md-4 col-lg-3">
                        <label class="form-label" for="transport_cost">Amount (Tk)</label>
                        <input type="number" step="0.01" min="0" class="form-control challan-transport-input"
                               id="transport_cost" name="transport_cost"
                               value="<?= number_format($transport, 2, '.', '') ?>"
                               <?= $transportLocked ? 'readonly' : '' ?>>
                    </div>
                    <?php if ($transportLocked): ?>
                    <div class="col-md-8">
                        <p class="text-muted small mb-0"><i class="fas fa-lock me-1"></i>Transport is locked after challan completion.</p>
                    </div>
                    <?php else: ?>
                    <div class="col-md-8">
                        <p class="text-muted small mb-0">
                            Saved with <strong>Save godown</strong> or <strong>Update CTN</strong> (updates invoice total).
                            You can change it again before finalize challan.
                        </p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <section class="challan-create-panel">
            <div class="challan-create-panel-head">
                <i class="fas fa-boxes-stacked"></i>
                <span>Dispatch items</span>
                <span class="badge bg-secondary ms-auto"><?= (int)$itemCount ?></span>
            </div>
            <div class="challan-create-panel-body p-0">
                <?php if (!$isCompleted && !$lockGodownAssignments): ?>
                <div class="challan-bulk-bar" id="challanBulkBar">
                    <div class="challan-bulk-bar-inner">
                        <label for="chBulkWarehouse"><i class="fas fa-layer-group me-1"></i>Apply warehouse to all</label>
                        <select id="chBulkWarehouse" class="form-select form-select-sm">
                            <option value="">— Choose warehouse —</option>
                        </select>
                        <button type="button" class="btn btn-sm btn-warning" id="chApplyBulkWarehouse">Apply</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="chFillAllCtn" title="Copy order CTN to dispatch CTN column">
                            <i class="fas fa-clone me-1"></i>Fill all CTN
                        </button>
                    </div>
                    <div class="challan-assign-progress-wrap" aria-hidden="true">
                        <div class="challan-assign-progress"><div id="chAssignProgressBar"></div></div>
                        <span class="challan-assign-progress-label" id="chAssignProgressLabel">0 / 0 warehouses set</span>
                    </div>
                </div>
                <?php elseif ($lockGodownAssignments && !$isCompleted): ?>
                <div class="challan-bulk-bar challan-bulk-bar--compact">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="chFillAllCtn">
                        <i class="fas fa-clone me-1"></i>Fill all CTN from order
                    </button>
                </div>
                <?php endif; ?>
                <div class="table-responsive challan-items-scroll">
                    <table class="table table-bordered godown-mobile-table mb-0" id="godownItemsTable">
                        <thead>
                            <tr>
                                <th>SL</th>
                                <th>Product</th>
                                <th class="text-end">Ordered</th>
                                <th class="text-end">CTN</th>
                                <th>Warehouse <span class="text-danger">*</span></th>
                                <th class="text-end"><?= $lockGodownAssignments ? 'Reserved' : 'Available' ?></th>
                                <th class="text-end">Demand (locked)</th>
                                <th class="text-end">Disp. CTN <span class="text-muted fw-normal">(editable)</span></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($invoice['items'] as $i => $item):
                            $ordered = (float)($item['qty'] ?? 0);
                            $pcs = (float)($item['pcs_per_carton'] ?? 0);
                            $orderCtn = $pcs > 0 ? round($ordered / $pcs, 2) : 0;
                            $dispQty = (float)($item['dispatched_qty'] ?? 0);
                            if ($dispQty <= 0) {
                                $dispQty = $ordered;
                            }
                            $dispCtn = (float)($item['dispatched_ctn'] ?? 0);
                            if ($dispCtn <= 0 && $pcs > 0) {
                                $dispCtn = round($dispQty / $pcs, 2);
                            }
                            $resolvedWh = (int)($item['resolved_warehouse_id'] ?? $item['warehouse_id'] ?? 0);
                            $availHint = (float)($item['available_qty'] ?? 0);
                        ?>
                            <tr data-product_id="<?= (int)$item['product_id'] ?>"
                                data-warehouse_id="<?= $resolvedWh ?>"
                                data-pcs-per-carton="<?= $pcs ?>"
                                data-ordered-qty="<?= $ordered ?>">
                                <input type="hidden" name="item_id[]" value="<?= (int)$item['id'] ?>">
                                <input type="hidden" name="product_id[]" value="<?= (int)$item['product_id'] ?>">
                                <td data-label="SL"><?= $i + 1 ?></td>
                                <td data-label="Product" class="fw-semibold"><?= htmlspecialchars($item['product_name'] ?? '', ENT_QUOTES) ?></td>
                                <td data-label="Ordered" class="text-end"><?= number_format($ordered, 2) ?></td>
                                <td data-label="CTN" class="text-end"><?= number_format($orderCtn, 2) ?></td>
                                <td data-label="Warehouse">
                                    <?php if ($isCompleted || $lockGodownAssignments): ?>
                                        <span class="challan-wh-readonly"><?= htmlspecialchars($item['warehouse_name'] ?? '—', ENT_QUOTES) ?></span>
                                        <input type="hidden" name="warehouse_id[]" value="<?= $resolvedWh ?>">
                                    <?php else: ?>
                                        <select class="form-select warehouse-select" name="warehouse_id[]" required>
                                            <option value="">Select warehouse</option>
                                        </select>
                                    <?php endif; ?>
                                </td>
                                <td data-label="<?= $lockGodownAssignments ? 'Reserved' : 'Available' ?>" class="text-end">
                                    <?php if ($lockGodownAssignments && $resolvedWh > 0): ?>
                                        <span class="challan-stock-badge is-reserved" data-role="stock-badge"
                                              title="Allocated at godown — stock deducts on challan finalize">
                                            <?= number_format($ordered, 2) ?> reserved
                                        </span>
                                    <?php else: ?>
                                        <span class="challan-stock-badge" data-role="stock-badge">—</span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Demand (locked)" class="text-end">
                                    <span class="challan-qty-locked"><?= number_format($ordered, 2) ?></span>
                                    <input type="hidden" class="dispatched-qty" name="dispatched_qty[]"
                                           value="<?= number_format($ordered, 2, '.', '') ?>">
                                </td>
                                <td data-label="Disp. CTN">
                                    <input type="number" step="0.01" min="0"
                                           class="form-control dispatched-ctn" name="dispatched_ctn[]"
                                           value="<?= $dispCtn > 0 ? number_format($dispCtn, 2, '.', '') : '' ?>"
                                           placeholder="CTN"
                                           <?= $isCompleted ? 'readonly' : '' ?>
                                           title="Adjust cartons for packing; invoice demand qty stays fixed">
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <section class="challan-create-panel">
            <div class="challan-create-panel-head">
                <i class="fas fa-users"></i>
                <span>Dispatcher(s)</span>
                <span class="text-danger ms-1">*</span>
            </div>
            <div class="challan-create-panel-body">
                <?php if ($lockGodownAssignments): ?>
                    <?php foreach ($invoice['dispatchers'] ?? [] as $d): ?>
                        <span class="challan-dispatcher-chip"><?= htmlspecialchars($d['name'] ?? '', ENT_QUOTES) ?></span>
                        <input type="hidden" name="dispatcher_id[]" value="<?= (int)($d['id'] ?? 0) ?>">
                    <?php endforeach; ?>
                    <?php if (empty($invoice['dispatchers'])): ?>
                        <p class="text-danger small mb-0">No dispatcher saved — contact admin.</p>
                    <?php endif; ?>
                <?php else: ?>
                <select class="form-select" id="dispatcher_id" name="dispatcher_id[]" multiple
                        <?= $isCompleted ? 'disabled' : 'required' ?>
                        data-selected='<?= json_encode($selectedDispatchers) ?>'>
                </select>
                <p class="text-muted small mt-2 mb-0">Select one or more warehouse dispatchers for this delivery.</p>
                <?php endif; ?>
            </div>
        </section>
    </form>

    <footer class="challan-create-actions">
        <a href="<?= BASE_URL ?>challan" class="btn btn-light challan-action-secondary">
            <i class="fas fa-arrow-left"></i> Back
        </a>
        <?php if (!$isCompleted): ?>
        <button type="button" id="btn-save-godown" class="btn challan-action-godown">
            <i class="fas fa-save"></i> <?= $lockGodownAssignments ? 'Update CTN' : 'Save godown' ?>
        </button>
        <button type="button" id="btn-create-challan" class="btn challan-action-challan"
                <?= !$isGodownReady ? 'disabled' : '' ?>
                title="<?= $isGodownReady ? 'Issue stock and complete challan' : 'Save godown copy first' ?>">
            <i class="fas fa-check-double"></i> Finalize challan
        </button>
        <span class="challan-kbd-hint d-none d-md-inline"><kbd>Ctrl</kbd>+<kbd>S</kbd> save godown</span>
        <?php else: ?>
        <span class="challan-completed-note"><i class="fas fa-check-circle text-success"></i> Challan completed</span>
        <?php if ($canReverse): ?>
        <button type="button" id="btn-reverse-challan" class="btn btn-danger">
            <i class="fas fa-undo"></i> Reverse challan
        </button>
        <?php endif; ?>
        <?php endif; ?>
    </footer>
</div>

<script>
window.CSRF_TOKEN = <?= json_encode($_SESSION['csrf_token'] ?? '') ?>;
window.CHALLAN_CREATE_BOOT = <?= json_encode([
    'baseUrl' => BASE_URL,
    'invoiceId' => (int)($invoice['id'] ?? 0),
    'sessionBranchId' => Helper::sessionBranchId(),
    'status' => $status,
    'isCompleted' => $isCompleted,
    'isGodownReady' => $isGodownReady,
    'lockGodownAssignments' => $lockGodownAssignments,
    'subtotal' => $subtotal,
    'discount' => $discount,
], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
</script>
<script src="<?= BASE_URL ?>assets/js/challan.js"></script>
<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';