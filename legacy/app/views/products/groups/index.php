<?php
ob_start();
$title = $title ?? 'Product Group Management';
$showDeleted = !empty($showDeleted);
$stats = $stats ?? ['active' => 0, 'inactive' => 0, 'products' => 0];
$csrfToken = htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES);
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/branch-index.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/product-theme.css">

<div class="branch-hub product-theme container-fluid py-2">
    <header class="branch-hub-hero">
        <div>
            <h1><i class="fas fa-globe me-2"></i><?= $showDeleted ? 'Inactive groups' : 'Product groups' ?></h1>
            <p>Sourcing / origin groups for SKUs (default: China).</p>
        </div>
        <div class="branch-hub-actions">
            <?php if ($showDeleted): ?>
                <a href="<?= BASE_URL ?>product/groups" class="btn btn-light btn-sm">Active</a>
            <?php else: ?>
                <a href="<?= BASE_URL ?>product/groups?deleted=1" class="btn btn-outline-light btn-sm">Inactive (<?= (int)$stats['inactive'] ?>)</a>
            <?php endif; ?>
            <a href="<?= BASE_URL ?>product" class="btn btn-outline-light btn-sm"><i class="fas fa-boxes me-1"></i> Products</a>
            <a href="<?= BASE_URL ?>product/groupCreate" class="btn btn-light btn-sm"><i class="fas fa-plus me-1"></i> New group</a>
        </div>
    </header>

    <?php if (!$showDeleted): ?>
    <div class="branch-hub-stats">
        <div class="branch-stat-card">
            <div class="branch-stat-icon teal"><i class="fas fa-globe"></i></div>
            <div><div class="stat-value"><?= (int)$stats['active'] ?></div><div class="stat-label">Active groups</div></div>
        </div>
        <div class="branch-stat-card">
            <div class="branch-stat-icon slate"><i class="fas fa-moon"></i></div>
            <div><div class="stat-value"><?= (int)$stats['inactive'] ?></div><div class="stat-label">Inactive</div></div>
        </div>
        <div class="branch-stat-card">
            <div class="branch-stat-icon indigo"><i class="fas fa-box"></i></div>
            <div><div class="stat-value"><?= (int)$stats['products'] ?></div><div class="stat-label">Active products</div></div>
        </div>
    </div>
    <?php endif; ?>

    <div class="branch-hub-panel">
        <div class="branch-hub-table-wrap">
            <table class="table table-borderless mb-0 w-100" id="groupTable">
                <thead>
                    <tr>
                        <th>Group</th>
                        <th class="text-center">Products</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
</div>

<script>
const GRP_BASE = "<?= BASE_URL ?>product";
const GRP_DELETED = <?= $showDeleted ? 'true' : 'false' ?>;
const GRP_CSRF = "<?= $csrfToken ?>";
const GRP_DEFAULT_ID = 1;

$(document).ready(function() {
    $('#groupTable').DataTable({
        processing: true,
        serverSide: true,
        ajax: { url: GRP_BASE + '/groups' + (GRP_DELETED ? '?deleted=1' : '') },
        pageLength: 25,
        order: [[0, 'asc']],
        dom: '<"branch-dt-toolbar"lf>Brt<"branch-dt-footer"ip>',
        buttons: ['copy', 'excel', 'pdf'],
        columns: [
            {
                data: 'group_name',
                render: function(d, t, row) {
                    const badge = parseInt(row.id, 10) === GRP_DEFAULT_ID ? ' <span class="hero-badge ms-1">Default</span>' : '';
                    return '<div class="branch-name-cell"><div class="category-avatar"><i class="fas fa-globe"></i></div><div class="name">'+ $('<div>').text(d).html() + badge +'</div></div>';
                }
            },
            {
                data: 'product_count',
                className: 'text-center',
                render: function(d) {
                    const n = parseInt(d, 10) || 0;
                    return n > 0 ? '<span class="branch-mini-stat"><i class="fas fa-box"></i> '+n+'</span>' : '<span class="text-muted small">0</span>';
                }
            },
            {
                data: 'id',
                orderable: false,
                className: 'text-center',
                render: function(id, type, row) {
                    let h = '<div class="branch-action-bar">';
                    h += '<a href="'+GRP_BASE+'/groupEdit/'+id+'" class="btn-action edit"><i class="fas fa-pen"></i></a>';
                    if (GRP_DELETED) {
                        h += '<button type="button" class="btn-action restore" onclick="restoreGroup('+id+')"><i class="fas fa-rotate-left"></i></button>';
                    } else if (parseInt(id, 10) === GRP_DEFAULT_ID) {
                        h += '<button type="button" class="btn-action" disabled title="System default"><i class="fas fa-trash"></i></button>';
                    } else if ((parseInt(row.product_count,10)||0) > 0) {
                        h += '<button type="button" class="btn-action" disabled title="In use"><i class="fas fa-trash"></i></button>';
                    } else {
                        h += '<button type="button" class="btn-action toggle-off" onclick="deleteGroup('+id+', '+JSON.stringify(row.group_name)+')"><i class="fas fa-trash"></i></button>';
                    }
                    return h + '</div>';
                }
            }
        ]
    });
});

function deleteGroup(id, name) {
    Swal.fire({ title: 'Deactivate group?', text: name, icon: 'warning', showCancelButton: true, confirmButtonColor: '#dc2626' })
    .then(r => {
        if (!r.isConfirmed) return;
        fetch(GRP_BASE+'/groupDelete/'+id, { method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body: new URLSearchParams({csrf_token:GRP_CSRF}) })
        .then(res => res.json()).then(d => {
            if (d.status==='success') Swal.fire('Done', d.message, 'success').then(() => location.reload());
            else Swal.fire('Blocked', d.message, 'error');
        });
    });
}
function restoreGroup(id) {
    Swal.fire({ title: 'Restore group?', icon: 'question', showCancelButton: true })
    .then(r => {
        if (!r.isConfirmed) return;
        fetch(GRP_BASE+'/groupRestore/'+id, { method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body: new URLSearchParams({csrf_token:GRP_CSRF}) })
        .then(res => res.json()).then(d => {
            if (d.status==='success') Swal.fire('Restored', d.message, 'success').then(() => location.reload());
            else Swal.fire('Error', d.message, 'error');
        });
    });
}
</script>
<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../layouts/main.php';
