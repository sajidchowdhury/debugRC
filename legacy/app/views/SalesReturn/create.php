<?php
$title = 'Receive Return';
$branchName = $_SESSION['branch_name'] ?? 'Branch';
ob_start();
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/sales-return-index.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/sales-return-create.css">
<meta name="theme-color" content="#e11d48">

<div id="sr-create-page" class="sr-create-app container-fluid py-2">
    <header class="sr-create-hero">
        <div>
            <h1><i class="fas fa-box-open me-2"></i>Step 1 — Receive from customer</h1>
            <p>Record what the customer is returning. Stock and credit apply after warehouse confirms (Step 2).</p>
            <div class="sr-journey-steps sr-journey-steps--hero" aria-label="Return process">
                <div class="sr-journey-step is-active">
                    <span class="sr-journey-num">1</span>
                    <span class="sr-journey-label">Receive from customer</span>
                </div>
                <span class="sr-journey-arrow" aria-hidden="true"><i class="fas fa-chevron-right"></i></span>
                <div class="sr-journey-step is-muted">
                    <span class="sr-journey-num">2</span>
                    <span class="sr-journey-label">Warehouse confirm</span>
                </div>
            </div>
            <span class="sales-return-branch-tag"><i class="fas fa-map-marker-alt me-1"></i><?= htmlspecialchars($branchName, ENT_QUOTES) ?></span>
        </div>
        <a href="<?= BASE_URL ?>SalesReturn" class="btn btn-light btn-sm flex-shrink-0">
            <i class="fas fa-list"></i> All returns
        </a>
    </header>

    <section class="sr-create-panel">
        <?php
        $workspace_id = 'salesReturnCreateRoot';
        $compact = false;
        require __DIR__ . '/partials/create_workspace.php';
        ?>
    </section>
</div>

<script>window.CSRF_TOKEN = <?= json_encode($_SESSION['csrf_token'] ?? '', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;</script>
<script>
window.SALES_RETURN_BASE = <?= json_encode(BASE_URL, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
window.SALES_RETURN_CREATE_BOOT = <?= json_encode([
    'workspace_id' => 'salesReturnCreateRoot',
    'prefill'        => trim($_GET['invoice'] ?? $_GET['q'] ?? ''),
], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
</script>
<script src="<?= BASE_URL ?>assets/js/SalesReturn.js"></script>
<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';