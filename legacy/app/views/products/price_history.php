<?php
ob_start();
$product = $product ?? [];
$history = $history ?? [];
$currentPrice = $currentPrice ?? null;
$productId = (int)($product['id'] ?? 0);
$csrfToken = htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES);
$title = 'Price History — ' . ($product['product_name'] ?? '');
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/branch-index.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/product-theme.css">

<div class="branch-hub product-theme container-fluid py-2">
    <header class="branch-hub-hero">
        <div>
            <h1><i class="fas fa-chart-line me-2"></i>Price history</h1>
            <p>
                <?= htmlspecialchars($product['product_name'] ?? '', ENT_QUOTES) ?>
                <span class="hero-badge ms-1"><?= htmlspecialchars($product['product_code'] ?? '', ENT_QUOTES) ?></span>
            </p>
        </div>
        <div class="branch-hub-actions">
            <a href="<?= BASE_URL ?>product/edit/<?= $productId ?>" class="btn btn-outline-light btn-sm"><i class="fas fa-pen me-1"></i> Edit product</a>
            <a href="<?= BASE_URL ?>product" class="btn btn-light btn-sm"><i class="fas fa-arrow-left me-1"></i> Catalog</a>
        </div>
    </header>

    <?php if ($currentPrice): ?>
    <div class="product-price-hero">
        <div>
            <div class="price-label">Current selling range</div>
            <div class="price-value">Tk <?= number_format($currentPrice['min_rate'], 2) ?> – <?= number_format($currentPrice['max_rate'], 2) ?></div>
            <div class="small text-muted mt-1">Suggested default: <strong>Tk <?= number_format($currentPrice['default_rate'], 2) ?></strong></div>
        </div>
        <i class="fas fa-tag fa-2x text-success opacity-50"></i>
    </div>
    <?php else: ?>
    <div class="alert alert-warning border-0 rounded-3 mb-3">No selling price set yet — add a min / max / default range below.</div>
    <?php endif; ?>

    <div class="product-price-add-panel">
        <h6 class="fw-bold text-muted mb-3"><i class="fas fa-plus-circle me-1"></i> Add new price range</h6>
        <form method="POST" action="<?= BASE_URL ?>product/add_price/<?= $productId ?>" class="row g-3 align-items-end">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <div class="col-12 col-md-3">
                <label class="form-label small fw-semibold">Min rate (Tk)</label>
                <input type="number" name="min_rate" class="form-control" step="0.01" min="0.01" required placeholder="120.00">
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label small fw-semibold">Max rate (Tk)</label>
                <input type="number" name="max_rate" class="form-control" step="0.01" min="0.01" required placeholder="130.00">
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label small fw-semibold">Default / suggested (Tk)</label>
                <input type="number" name="default_rate" class="form-control" step="0.01" min="0.01" required placeholder="125.00">
            </div>
            <div class="col-12 col-md-3">
                <button type="submit" class="btn btn-primary w-100"><i class="fas fa-save me-1"></i> Save range</button>
            </div>
            <div class="col-12">
                <p class="small text-muted mb-0">Rule: <strong>min ≤ default ≤ max</strong>. Becomes active from the effective timestamp. Sales will pre-fill the default; users may sell anywhere in the range (Phase 8+).</p>
            </div>
        </form>
    </div>

    <div class="branch-hub-panel">
        <div class="branch-form-section-head px-3 pt-3">
            <span class="icon-wrap indigo"><i class="fas fa-history"></i></span>
            Change log <span class="text-muted fw-normal small">(append-only)</span>
        </div>
        <div class="table-responsive">
            <table class="table table-borderless mb-0 product-price-table" id="priceTable">
                <thead>
                    <tr>
                        <th>Effective from</th>
                        <th class="text-end">Min</th>
                        <th class="text-end">Max</th>
                        <th class="text-end">Default</th>
                        <th>Recorded by</th>
                        <th>Recorded</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($history)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-5">No price history yet.</td></tr>
                    <?php else: ?>
                    <?php $first = true; foreach ($history as $h): ?>
                    <tr class="<?= $first ? 'current-row' : '' ?>">
                        <td>
                            <strong><?= date('d M Y', strtotime($h['effective_from'])) ?></strong><br>
                            <small class="text-muted"><?= date('h:i A', strtotime($h['effective_from'])) ?></small>
                        </td>
                        <td class="text-end">Tk <?= number_format((float)$h['min_rate'], 2) ?></td>
                        <td class="text-end">Tk <?= number_format((float)$h['max_rate'], 2) ?></td>
                        <td class="text-end rate-cell">
                            Tk <?= number_format((float)$h['default_rate'], 2) ?>
                            <?php if ($first): ?><span class="branch-status-pill active ms-1"><span class="dot"></span> Current</span><?php endif; ?>
                        </td>
                        <td><small><?= htmlspecialchars($h['created_by_name'] ?? '—', ENT_QUOTES) ?></small></td>
                        <td><small class="text-muted"><?= date('d M Y h:i A', strtotime($h['created_at'])) ?></small></td>
                    </tr>
                    <?php $first = false; endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="branch-audit-meta-foot">
            <i class="fas fa-info-circle me-1"></i> Top row is the active range on the catalog. History entries cannot be deleted.
        </div>
    </div>
</div>

<script>
<?php if (isset($_GET['success'])): ?>
Swal.fire({ title: 'Price range updated', icon: 'success', timer: 2000, showConfirmButton: false });
<?php endif; ?>
<?php if (!empty($_SESSION['error'])): ?>
Swal.fire({ title: 'Error', text: <?= json_encode($_SESSION['error']) ?>, icon: 'error' });
<?php unset($_SESSION['error']); endif; ?>
</script>
<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';
