<?php
$title = 'Purchase Return';
$branchName = $_SESSION['branch_name'] ?? 'Branch';
ob_start();
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/purchase-return-index.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/purchase-return-create.css">
<meta name="theme-color" content="#ea580c">

<div id="prt-create-page" class="prt-create-app container-fluid py-2">
    <header class="prt-create-hero">
        <div>
            <h1><i class="fas fa-truck-loading me-2"></i>Purchase return</h1>
            <p>Search GRN once, enter quantities and warehouse, save</p>
            <span class="purchase-return-branch-tag"><i class="fas fa-map-marker-alt me-1"></i><?= htmlspecialchars($branchName, ENT_QUOTES) ?></span>
        </div>
        <a href="<?= BASE_URL ?>PurchaseReturn" class="btn btn-light btn-sm flex-shrink-0">
            <i class="fas fa-list"></i> All returns
        </a>
    </header>

    <section class="prt-create-panel">
        <?php
        $workspace_id = 'purchaseReturnCreateRoot';
        $compact = false;
        require __DIR__ . '/partials/create_workspace.php';
        ?>
    </section>
</div>

<script>window.CSRF_TOKEN = <?= json_encode($_SESSION['csrf_token'] ?? '', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;</script>
<script>
window.PURCHASE_RETURN_BASE = <?= json_encode(BASE_URL, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
window.PURCHASE_RETURN_CREATE_BOOT = <?= json_encode([
    'workspace_id' => 'purchaseReturnCreateRoot',
    'prefill'      => trim($_GET['grn'] ?? $_GET['q'] ?? ''),
], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
</script>
<script src="<?= BASE_URL ?>assets/js/PurchaseReturn.js"></script>
<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';