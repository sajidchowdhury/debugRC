<?php
ob_start();
$title = $title ?? 'Create New Branch';
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/branch-index.css">

<div class="branch-hub container-fluid py-2">
    <header class="branch-hub-hero">
        <div>
            <h1><i class="fas fa-plus-circle me-2"></i>New branch</h1>
            <p>Add a location to your network — used for warehouses, sales, stock, and team assignment.</p>
        </div>
        <div class="branch-hub-actions">
            <a href="<?= BASE_URL ?>branch" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to list
            </a>
        </div>
    </header>

    <div class="branch-form-layout has-aside">
        <div class="branch-form-panel">
            <form method="POST" action="<?= BASE_URL ?>branch/store" id="branchForm">
                <?php
                $branch = [];
                $isEdit = false;
                require __DIR__ . '/_form_fields.php';
                ?>
                <div class="branch-form-footer">
                    <button type="submit" class="btn btn-primary px-4">
                        <i class="fas fa-check me-1"></i> Create branch
                    </button>
                    <a href="<?= BASE_URL ?>branch" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>

        <aside class="branch-form-aside">
            <div class="aside-title">Live preview</div>
            <div class="branch-preview-card">
                <div class="branch-avatar" id="previewAvatar">?</div>
                <div class="preview-name" id="previewName">Branch name</div>
                <div class="preview-code" id="previewCode">BR-CODE</div>
            </div>
            <div class="branch-aside-tip">
                <i class="fas fa-lightbulb me-1"></i>
                Use a short unique <strong>branch code</strong> for reports and stock transfers. You can add warehouses after saving.
            </div>
        </aside>
    </div>
</div>

<script>
(function() {
    const nameEl = document.getElementById('branch_name');
    const codeEl = document.getElementById('branch_code');
    const previewName = document.getElementById('previewName');
    const previewCode = document.getElementById('previewCode');
    const previewAvatar = document.getElementById('previewAvatar');

    function updatePreview() {
        const name = (nameEl?.value || '').trim();
        const code = (codeEl?.value || '').trim();
        previewName.textContent = name || 'Branch name';
        previewCode.textContent = code || 'BR-CODE';
        previewAvatar.textContent = name ? name.charAt(0).toUpperCase() : '?';
    }

    nameEl?.addEventListener('input', updatePreview);
    codeEl?.addEventListener('input', updatePreview);
    updatePreview();
})();
</script>

<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';