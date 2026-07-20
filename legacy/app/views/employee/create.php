<?php
ob_start();
$title = $title ?? 'Create New Employee';
$branches = $branches ?? [];
$roles = $roles ?? [];
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/branch-index.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/employee-theme.css">

<div class="branch-hub employee-theme container-fluid py-2">
    <header class="branch-hub-hero">
        <div>
            <h1><i class="fas fa-user-plus me-2"></i>New employee</h1>
            <p>Add staff for branches, sales, warehouse, and system user accounts.</p>
        </div>
        <div class="branch-hub-actions">
            <a href="<?= BASE_URL ?>employee" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to list
            </a>
        </div>
    </header>

    <div class="branch-form-layout has-aside">
        <div class="branch-form-panel">
            <form method="POST" action="<?= BASE_URL ?>employee/store" enctype="multipart/form-data" id="employeeForm">
                <?php $isEdit = false; require __DIR__ . '/_form_fields.php'; ?>
                <div class="branch-form-footer">
                    <button type="submit" class="btn btn-primary px-4">
                        <i class="fas fa-check me-1"></i> Create employee
                    </button>
                    <a href="<?= BASE_URL ?>employee" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>
        <aside class="branch-form-aside">
            <div class="aside-title">Live preview</div>
            <div class="branch-preview-card">
                <div class="branch-avatar" id="previewAvatar">?</div>
                <div class="preview-name" id="previewName">Full name</div>
                <div class="preview-code" id="previewRole">Role</div>
                <div class="mt-2 small text-muted" id="previewBranch">Branch</div>
            </div>
        </aside>
    </div>
</div>
<script>
(function() {
    const nameEl = document.getElementById('emp_name');
    const roleEl = document.getElementById('role');
    const branchEl = document.getElementById('branch_id');
    function upd() {
        const n = (nameEl?.value || '').trim();
        document.getElementById('previewName').textContent = n || 'Full name';
        document.getElementById('previewAvatar').textContent = n ? n.charAt(0).toUpperCase() : '?';
        document.getElementById('previewRole').textContent = roleEl?.selectedOptions?.[0]?.text?.trim() || 'Role';
        document.getElementById('previewBranch').textContent = branchEl?.selectedOptions?.[0]?.text?.trim() || 'Branch';
    }
    nameEl?.addEventListener('input', upd);
    roleEl?.addEventListener('change', upd);
    branchEl?.addEventListener('change', upd);
    upd();
})();
</script>
<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';