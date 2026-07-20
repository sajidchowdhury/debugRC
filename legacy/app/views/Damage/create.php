<?php
$title = $title ?? 'Record Damage';
$warehouses = $warehouses ?? [];
$branchName = $branch_name ?? 'Branch';

ob_start();
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/purchase-index.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/stock-take.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/damage.css">

<div class="purch-index-app st-take-app dmg-app container-fluid py-2">
    <header class="purch-index-hero dmg-hero">
        <div>
            <h1><i class="fas fa-heart-crack me-2"></i>Record damage</h1>
            <p>Write off damaged stock from your branch warehouse — total damage amount posts to GL on save</p>
            <span class="purch-index-tag"><i class="fas fa-building me-1"></i><?= htmlspecialchars($branchName, ENT_QUOTES) ?></span>
        </div>
        <div class="purch-index-hero-actions">
            <a href="<?= BASE_URL ?>SalesReturn" class="btn btn-outline-light btn-sm me-1"><i class="fas fa-undo-alt me-1"></i> Returns</a>
            <a href="<?= BASE_URL ?>Damage" class="btn btn-light btn-sm"><i class="fas fa-arrow-left me-1"></i> List</a>
        </div>
    </header>

    <div class="alert alert-light border mb-3 small">
        <i class="fas fa-info-circle me-1 text-primary"></i>
        <strong>Sales journey:</strong> Damaged goods from a customer return are written off automatically when warehouse confirms the return with condition <em>Damage</em> — no separate entry needed.
        Use this form only for other warehouse shrinkage (breakage in store, expiry, etc.).
    </div>

    <div class="st-section-card p-3">
        <form id="damageForm">
            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Warehouse <span class="text-danger">*</span></label>
                    <select name="warehouse_id" id="dmg_warehouse_id" class="form-select" required>
                        <option value="">— Select warehouse —</option>
                        <?php foreach ($warehouses as $w): ?>
                        <option value="<?= (int)$w['id'] ?>"><?= htmlspecialchars($w['warehouse_name'] ?? '', ENT_QUOTES) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Damage date</label>
                    <input type="date" name="damage_date" class="form-control" value="<?= date('Y-m-d') ?>">
                </div>
            </div>

            <?php if (empty($warehouses)): ?>
            <div class="alert alert-warning py-2 small">No warehouses for your branch.</div>
            <?php endif; ?>

            <div class="mb-3">
                <label class="form-label">Remarks (optional)</label>
                <textarea name="remarks" class="form-control" rows="2" placeholder="Cause, location, notes…"></textarea>
            </div>

            <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="fw-semibold">Damaged products</span>
                <button type="button" onclick="addItemRow()" class="btn btn-outline-danger btn-sm">+ Add line</button>
            </div>
            <div id="items_section" class="mb-2"></div>

            <div class="st-count-summary-bar mb-3">
                <span>Lines: <strong id="dmg_line_count">0</strong></span>
                <span>Total damage amount: <strong id="dmg_total_value" class="text-danger">0.00</strong></span>
                <span class="small text-muted">Dr shrinkage / Cr inventory when amount &gt; 0</span>
            </div>

            <div class="d-flex justify-content-between">
                <button type="button" onclick="resetDamageForm()" class="btn btn-outline-secondary">Reset</button>
                <button type="submit" class="btn btn-danger">
                    <i class="fas fa-check me-1"></i> Save &amp; post
                </button>
            </div>
        </form>
    </div>
</div>

<script>window.DMG_BOOT = { baseUrl: <?= json_encode(BASE_URL, JSON_THROW_ON_ERROR) ?> };</script>
<input type="hidden" id="base_url" value="<?= htmlspecialchars(BASE_URL, ENT_QUOTES) ?>">
<script src="<?= BASE_URL ?>assets/js/Damage.js"></script>
<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';