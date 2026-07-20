<?php
ob_start();
$title = $title ?? 'Menu Permissions';
$user = $user ?? [];
$menus = $menus ?? [];
$currentPermissions = $currentPermissions ?? [];
$copyCandidates = $copyCandidates ?? [];
$permissionStats = $permissionStats ?? ['view_count' => 0, 'edit_count' => 0, 'menu_count' => 0];
$userId = (int)($user['id'] ?? 0);
$csrf = htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES);

function permSearchBlob(array $menu): string
{
    $parts = [
        $menu['menu_name'] ?? '',
        $menu['section'] ?? '',
        $menu['controller'] ?? '',
        $menu['menu_link'] ?? '',
        $menu['action'] ?? '',
    ];

    return strtolower(implode(' ', array_filter($parts, static fn($v) => $v !== '' && $v !== null)));
}

function buildMenuRowsPermission(array $menu, array $currentPermissions, int $level = 0): string
{
    $hasView = !empty($currentPermissions[$menu['id']]['view']);
    $hasEdit = !empty($currentPermissions[$menu['id']]['edit']);
    $search = permSearchBlob($menu);
    $menuId = (int)($menu['id'] ?? 0);
    $menuName = htmlspecialchars($menu['menu_name'] ?? '', ENT_QUOTES);
    $meta = [];
    if (!empty($menu['section'])) {
        $meta[] = htmlspecialchars($menu['section'], ENT_QUOTES);
    }
    if (!empty($menu['controller'])) {
        $meta[] = htmlspecialchars($menu['controller'], ENT_QUOTES);
    }
    $metaHtml = $meta ? '<span class="user-perm-menu-meta">' . implode(' · ', $meta) . '</span>' : '';

    $html = '<tr class="menu-row" data-menu-id="' . $menuId . '" data-level="' . $level . '" data-search="' . htmlspecialchars($search, ENT_QUOTES) . '">';
    $html .= '<td><div class="user-perm-menu-name">' . $menuName . '</div>' . $metaHtml . '</td>';
    $html .= '<td class="text-center"><input type="checkbox" class="form-check-input view-check" name="permissions[' . $menuId . '][view]" value="1"' . ($hasView ? ' checked' : '') . '></td>';
    $html .= '<td class="text-center"><input type="checkbox" class="form-check-input edit-check" name="permissions[' . $menuId . '][edit]" value="1"' . ($hasEdit ? ' checked' : '') . '></td>';
    $html .= '</tr>';

    if (!empty($menu['children'])) {
        foreach ($menu['children'] as $child) {
            $html .= buildMenuRowsPermission($child, $currentPermissions, $level + 1);
        }
    }

    return $html;
}

$menuRowsHtml = '';
foreach ($menus as $menu) {
    $menuRowsHtml .= buildMenuRowsPermission($menu, $currentPermissions);
}

$viewCount = (int)($permissionStats['view_count'] ?? 0);
$editCount = (int)($permissionStats['edit_count'] ?? 0);
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/branch-index.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/master-data-hub.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/user-theme.css">

<div class="branch-hub user-theme container-fluid py-2">
    <header class="branch-hub-hero">
        <div>
            <h1><i class="fas fa-shield-halved me-2"></i>Menu permissions</h1>
            <p>
                <strong><?= htmlspecialchars($user['employee_name'] ?? '', ENT_QUOTES) ?></strong>
                · <?= htmlspecialchars($user['username'] ?? '', ENT_QUOTES) ?>
            </p>
            <span class="hero-badge"><i class="fas fa-eye"></i> <?= $viewCount ?> view</span>
            <span class="hero-badge ms-2"><i class="fas fa-pen"></i> <?= $editCount ?> edit</span>
        </div>
        <div class="branch-hub-actions">
            <?php if (!empty($user['employee_id'])): ?>
            <a href="<?= BASE_URL ?>employee/account/<?= (int)$user['employee_id'] ?>" class="btn btn-outline-light btn-sm">
                <i class="fas fa-id-card-clip me-1"></i> Employee hub
            </a>
            <?php endif; ?>
            <a href="<?= BASE_URL ?>user/edit/<?= $userId ?>" class="btn btn-outline-light btn-sm">
                <i class="fas fa-user-pen me-1"></i> Edit user
            </a>
            <a href="<?= BASE_URL ?>user" class="btn btn-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Users
            </a>
        </div>
    </header>

    <div class="branch-hub-panel">
        <form method="POST" action="<?= BASE_URL ?>user/save_permissions" id="permissionForm">
            <input type="hidden" name="user_id" value="<?= $userId ?>">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">

            <div class="user-perm-toolbar">
                <div class="hub-search-wrap user-perm-search">
                    <i class="fas fa-search"></i>
                    <input type="search" id="permMenuSearch" class="hub-search-input" placeholder="Search menus, sections, controllers…" autocomplete="off">
                </div>
                <div class="user-perm-toolbar-actions">
                    <button type="button" id="checkAllViewBtn" class="btn btn-sm btn-outline-primary">
                        <i class="fas fa-eye me-1"></i> All view
                    </button>
                    <button type="button" id="checkAllVisibleViewBtn" class="btn btn-sm btn-outline-primary">
                        <i class="fas fa-filter me-1"></i> Visible view
                    </button>
                    <button type="button" id="checkAllEditBtn" class="btn btn-sm btn-outline-secondary">
                        <i class="fas fa-pen me-1"></i> All edit
                    </button>
                    <button type="button" id="uncheckAllBtn" class="btn btn-sm btn-outline-danger">
                        <i class="fas fa-times me-1"></i> Clear all
                    </button>
                </div>
            </div>

            <?php if (!empty($copyCandidates)): ?>
            <div class="user-perm-copy-bar">
                <div class="user-perm-copy-label">
                    <i class="fas fa-copy me-1"></i> Copy from another user
                </div>
                <select id="copyFromUser" class="form-select form-select-sm">
                    <option value="">Select source user…</option>
                    <?php foreach ($copyCandidates as $candidate): ?>
                    <option value="<?= (int)$candidate['id'] ?>">
                        <?= htmlspecialchars($candidate['employee_name'] ?? '', ENT_QUOTES) ?>
                        (<?= htmlspecialchars($candidate['username'] ?? '', ENT_QUOTES) ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
                <button type="button" id="applyCopyBtn" class="btn btn-sm btn-outline-primary" disabled>
                    <i class="fas fa-download me-1"></i> Apply to form
                </button>
                <span class="user-perm-copy-hint small text-muted">Copies checkboxes only — click Save to persist.</span>
            </div>
            <?php endif; ?>

            <div class="user-perm-status-bar">
                <span id="permVisibleCount" class="branch-audit-chip"><i class="fas fa-list"></i> <?= count($menus) ?> top-level menus</span>
                <span id="permCheckedView" class="branch-audit-chip"><i class="fas fa-eye"></i> <span data-stat="view"><?= $viewCount ?></span> view</span>
                <span id="permCheckedEdit" class="branch-audit-chip updated"><i class="fas fa-pen"></i> <span data-stat="edit"><?= $editCount ?></span> edit</span>
            </div>

            <div class="table-responsive user-perm-table-wrap">
                <table class="table table-hover mb-0 user-perm-table" id="permissionTable">
                    <thead>
                        <tr>
                            <th style="width:55%;">Menu</th>
                            <th class="text-center" style="width:22.5%;">View</th>
                            <th class="text-center" style="width:22.5%;">Edit</th>
                        </tr>
                    </thead>
                    <tbody><?= $menuRowsHtml ?></tbody>
                </table>
                <div id="permNoResults" class="hub-empty-state py-4 d-none">
                    <i class="fas fa-search d-block"></i>
                    <p class="mb-0">No menus match your search.</p>
                </div>
            </div>

            <div class="branch-form-footer m-3">
                <button type="button" id="savePermissionsBtn" class="btn btn-primary px-4">
                    <i class="fas fa-save me-1"></i> Save permissions
                </button>
                <a href="<?= BASE_URL ?>user" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>

    <div class="branch-aside-tip mx-0 mt-2" style="max-width:100%;">
        <i class="fas fa-lightbulb me-1"></i>
        Checking a parent cascades view/edit to child menus. Use copy-from-user to clone an existing profile, then adjust and save.
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const table = document.getElementById('permissionTable');
    const tbody = table?.querySelector('tbody');
    if (!table || !tbody) return;

    const rows = () => [...tbody.querySelectorAll('tr.menu-row')];
    const searchInput = document.getElementById('permMenuSearch');
    const noResults = document.getElementById('permNoResults');
    const visibleCountEl = document.getElementById('permVisibleCount');
    const copySelect = document.getElementById('copyFromUser');
    const applyCopyBtn = document.getElementById('applyCopyBtn');
    const permBase = <?= json_encode(rtrim(BASE_URL, '/') . '/user/permission_json/') ?>;

    function updateStats() {
        const allRows = rows();
        let view = 0;
        let edit = 0;
        let visible = 0;
        allRows.forEach(row => {
            if (row.classList.contains('d-none')) return;
            visible++;
            if (row.querySelector('.view-check')?.checked) view++;
            if (row.querySelector('.edit-check')?.checked) edit++;
        });
        const viewStat = document.querySelector('[data-stat="view"]');
        const editStat = document.querySelector('[data-stat="edit"]');
        if (viewStat) viewStat.textContent = view;
        if (editStat) editStat.textContent = edit;
        if (visibleCountEl) {
            visibleCountEl.innerHTML = '<i class="fas fa-list"></i> ' + visible + ' menu row' + (visible === 1 ? '' : 's') + ' shown';
        }
    }

    function filterMenus(query) {
        const q = query.trim().toLowerCase();
        const allRows = rows();
        if (!q) {
            allRows.forEach(r => {
                r.classList.remove('d-none', 'perm-search-match');
            });
            noResults?.classList.add('d-none');
            table.classList.remove('d-none');
            updateStats();
            return;
        }

        const directMatch = allRows.map(r => (r.dataset.search || '').includes(q));
        const visible = new Array(allRows.length).fill(false);

        allRows.forEach((row, i) => {
            if (!directMatch[i]) return;
            visible[i] = true;
            let level = parseInt(row.dataset.level || '0', 10);
            for (let j = i - 1; j >= 0; j--) {
                const parentLevel = parseInt(allRows[j].dataset.level || '0', 10);
                if (parentLevel < level) {
                    visible[j] = true;
                    level = parentLevel;
                }
            }
        });

        let anyVisible = false;
        allRows.forEach((row, i) => {
            const show = visible[i];
            row.classList.toggle('d-none', !show);
            row.classList.toggle('perm-search-match', show && directMatch[i]);
            if (show) anyVisible = true;
        });

        table.classList.toggle('d-none', !anyVisible);
        noResults?.classList.toggle('d-none', anyVisible);
        updateStats();
    }

    function setAllChecks(selector, checked, visibleOnly = false) {
        rows().forEach(row => {
            if (visibleOnly && row.classList.contains('d-none')) return;
            const cb = row.querySelector(selector);
            if (cb) cb.checked = checked;
        });
        updateStats();
    }

    function applyPermissionsMap(perms) {
        rows().forEach(row => {
            const menuId = row.dataset.menuId;
            const viewCb = row.querySelector('.view-check');
            const editCb = row.querySelector('.edit-check');
            const grant = perms[menuId] || perms[parseInt(menuId, 10)];
            const hasView = grant && (grant.view === 1 || grant.view === '1' || grant.view === true);
            const hasEdit = grant && (grant.edit === 1 || grant.edit === '1' || grant.edit === true);
            if (viewCb) viewCb.checked = !!hasView;
            if (editCb) editCb.checked = !!hasEdit;
        });
        updateStats();
    }

    searchInput?.addEventListener('input', () => filterMenus(searchInput.value));

    document.getElementById('checkAllViewBtn')?.addEventListener('click', () => setAllChecks('.view-check', true));
    document.getElementById('checkAllVisibleViewBtn')?.addEventListener('click', () => setAllChecks('.view-check', true, true));
    document.getElementById('checkAllEditBtn')?.addEventListener('click', () => setAllChecks('.edit-check', true));
    document.getElementById('uncheckAllBtn')?.addEventListener('click', () => {
        setAllChecks('.view-check', false);
        setAllChecks('.edit-check', false);
    });

    copySelect?.addEventListener('change', () => {
        if (applyCopyBtn) applyCopyBtn.disabled = !copySelect.value;
    });

    applyCopyBtn?.addEventListener('click', async () => {
        const sourceId = copySelect?.value;
        if (!sourceId) return;

        const label = copySelect.selectedOptions[0]?.textContent?.trim() || 'this user';
        const confirm = await Swal.fire({
            title: 'Copy permissions?',
            text: 'Replace all checkboxes on this form with permissions from ' + label + '. You still need to Save.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Apply copy'
        });
        if (!confirm.isConfirmed) return;

        applyCopyBtn.disabled = true;
        try {
            const res = await fetch(permBase + sourceId, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            const data = await res.json();
            if (!res.ok || data.status !== 'success') {
                throw new Error(data.message || 'Failed to load permissions.');
            }
            applyPermissionsMap(data.permissions || {});
            Swal.fire({
                title: 'Copied',
                text: 'Permissions from ' + (data.employee_name || data.username) + ' applied. Review and Save when ready.',
                icon: 'success',
                timer: 2200,
                showConfirmButton: false
            });
        } catch (err) {
            Swal.fire('Copy failed', err.message || 'Could not load source permissions.', 'error');
        } finally {
            applyCopyBtn.disabled = !copySelect?.value;
        }
    });

    table.addEventListener('change', function(e) {
        const checkbox = e.target;
        if (!checkbox.matches('.view-check, .edit-check')) return;

        const row = checkbox.closest('tr');
        if (!row) return;
        const level = parseInt(row.dataset.level || '0', 10);
        const isView = checkbox.classList.contains('view-check');

        if (checkbox.checked) {
            let nextRow = row.nextElementSibling;
            while (nextRow && parseInt(nextRow.dataset.level || '0', 10) > level) {
                const target = nextRow.querySelector(isView ? '.view-check' : '.edit-check');
                if (target) target.checked = true;
                nextRow = nextRow.nextElementSibling;
            }
        }

        if (!isView && checkbox.checked) {
            const viewCb = row.querySelector('.view-check');
            if (viewCb) viewCb.checked = true;
        }

        updateStats();
    });

    const saveBtn = document.getElementById('savePermissionsBtn');
    const form = document.getElementById('permissionForm');
    if (saveBtn && form) {
        saveBtn.addEventListener('click', function() {
            Swal.fire({
                title: 'Save permissions?',
                text: 'This will immediately update this user\'s access rights.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Yes, save'
            }).then((result) => {
                if (result.isConfirmed) form.submit();
            });
        });
    }

    updateStats();
});
</script>

<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';
