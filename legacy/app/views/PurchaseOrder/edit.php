<?php
$title = 'Edit Purchase Order';
$branchName = $_SESSION['branch_name'] ?? 'Branch';
$poCode = $po['po_code'] ?? '';
ob_start();
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/purchase-index.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/purchase-order-form.css">

<div class="purch-index-app purch-po-form-app container-fluid py-2">
    <header class="purch-index-hero">
        <div>
            <h1><i class="fas fa-edit me-2"></i>Edit <?= htmlspecialchars($poCode, ENT_QUOTES) ?></h1>
            <p>Draft purchase order — adjust lines before receiving goods</p>
            <span class="purch-index-tag"><i class="fas fa-building me-1"></i><?= htmlspecialchars($branchName, ENT_QUOTES) ?></span>
        </div>
        <div class="purch-index-hero-actions">
            <a href="<?= BASE_URL ?>PurchaseOrder/Details/<?= (int)($po['id'] ?? 0) ?>" class="btn btn-light btn-sm">
                <i class="fas fa-eye me-1"></i> View
            </a>
            <a href="<?= BASE_URL ?>PurchaseOrder" class="btn btn-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> List
            </a>
        </div>
    </header>

    <section class="purch-po-form-card-body p-0 border-0 bg-transparent">
        <?php
        $form_mode = 'edit';
        $form_action = BASE_URL . 'PurchaseOrder/update/' . (int)($po['id'] ?? 0);
        $branch_name = $branchName;
        require __DIR__ . '/partials/po_form.php';
        ?>
    </section>
</div>

<script>window.CSRF_TOKEN = <?= json_encode($_SESSION['csrf_token'] ?? '', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;</script>
<script>
window.products = <?= json_encode($products ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
window.existingItems = <?= json_encode($po['items'] ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
window.PO_FORM_BOOT = <?= json_encode(['mode' => 'edit', 'po_id' => (int)($po['id'] ?? 0)], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
</script>
<script src="<?= BASE_URL ?>assets/js/PurchaseOrder.js"></script>
<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';