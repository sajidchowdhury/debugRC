<?php
ob_start();
$title = 'Money Transfer Audit Logs';
$logs = $logs ?? [];
?>

<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/branch-index.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/customer-transaction-theme.css">

<div class="branch-hub money-transfer-theme container-fluid py-2">
    <header class="branch-hub-hero">
        <div>
            <h1><i class="fas fa-history me-2"></i>Money Transfer Audit Logs</h1>
            <p>Track all creations, modifications, and reversals with full details</p>
            <span class="hero-badge"><i class="fas fa-shield-alt"></i> Last 300 entries</span>
        </div>
        <div class="branch-hub-actions">
            <a href="<?= BASE_URL ?>MoneyTransfer" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to Transfers
            </a>
        </div>
    </header>

    <div class="branch-hub-panel">
        <div class="branch-hub-filters mb-3">
            <div class="row g-3 align-items-end">
                <div class="col-sm-4 col-md-3">
                    <div class="filter-label">Action Type</div>
                    <select id="filterAction" class="form-select form-select-sm">
                        <option value="">All Actions</option>
                        <option value="created">Created</option>
                        <option value="reversed">Reversed</option>
                    </select>
                </div>
                <div class="col-sm-4 col-md-3">
                    <div class="filter-label">From Date</div>
                    <input type="date" id="fromDate" class="form-control form-control-sm">
                </div>
                <div class="col-sm-4 col-md-3">
                    <div class="filter-label">To Date</div>
                    <input type="date" id="toDate" class="form-control form-control-sm">
                </div>
                <div class="col-sm-auto">
                    <button id="clearAuditFilters" class="btn btn-outline-secondary btn-sm btn-clear">
                        <i class="fas fa-rotate-left me-1"></i> Reset
                    </button>
                </div>
            </div>
        </div>

        <!-- Desktop Table -->
        <div class="branch-hub-table-wrap">
            <table class="table table-borderless mb-0 w-100" id="auditTable">
                <thead>
                    <tr>
                        <th style="width: 160px;">Timestamp</th>
                        <th style="width: 90px;">User</th>
                        <th>Action</th>
                        <th style="width: 110px;">Transfer ID</th>
                        <th>Details</th>
                        <th style="width: 110px;">IP Address</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log): ?>
                        <?php
                        $action = strtolower($log['action'] ?? '');
                        $timestamp = htmlspecialchars($log['timestamp'] ?? '');
                        $performedBy = (int)($log['performed_by'] ?? 0);
                        $targetId = htmlspecialchars($log['target_user_id'] ?? '-');
                        $ip = htmlspecialchars($log['ip'] ?? 'unknown');
                        $details = $log['details'] ?? [];

                        $detailsHtml = '';
                        if (!empty($details['transfer_code'])) {
                            $detailsHtml .= '<strong>' . htmlspecialchars($details['transfer_code']) . '</strong>';

                            if (!empty($details['transfer_type'])) {
                                $typeLabels = [
                                    'cash_to_bank' => 'Cash→Bank',
                                    'bank_to_cash' => 'Bank→Cash',
                                    'cash_to_cash' => 'Cash→Cash',
                                    'bank_to_bank' => 'Bank→Bank'
                                ];
                                $typeLabel = $typeLabels[$details['transfer_type']] ?? $details['transfer_type'];
                                $detailsHtml .= ' <span class="text-muted">• ' . htmlspecialchars($typeLabel) . '</span>';
                            }

                            if (!empty($details['amount'])) {
                                $detailsHtml .= '<br><span class="fw-bold">৳ ' . number_format($details['amount'], 2) . '</span>';
                            }

                            if (!empty($details['from']) || !empty($details['to'])) {
                                $fromTo = [];
                                if (!empty($details['from'])) $fromTo[] = 'From: ' . htmlspecialchars($details['from']);
                                if (!empty($details['to']))   $fromTo[] = 'To: ' . htmlspecialchars($details['to']);
                                if (!empty($fromTo)) {
                                    $detailsHtml .= '<br><small>' . implode(' → ', $fromTo) . '</small>';
                                }
                            }

                            if (!empty($details['reason'])) {
                                $detailsHtml .= '<br><small class="text-muted">Reason: ' . htmlspecialchars($details['reason']) . '</small>';
                            }
                        }

                        $actionBadge = match(true) {
                            str_contains($action, 'created') => '<span class="branch-status-pill active"><span class="dot"></span> Created</span>',
                            str_contains($action, 'reversed') => '<span class="branch-status-pill inactive"><span class="dot"></span> Reversed</span>',
                            default => '<span class="badge bg-secondary">' . htmlspecialchars($log['action']) . '</span>'
                        };
                        ?>
                        <tr>
                            <td><small><?= $timestamp ?></small></td>
                            <td><span class="badge bg-light text-dark"><?= $performedBy ?></span></td>
                            <td><?= $actionBadge ?></td>
                            <td><strong><?= $targetId ?></strong></td>
                            <td><?= $detailsHtml ?></td>
                            <td><small class="text-muted"><?= $ip ?></small></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    const table = $("#auditTable").DataTable({
        pageLength: 50,
        order: [[0, "desc"]],
        dom: '<"branch-dt-toolbar"lf>Brt<"branch-dt-footer"ip>',
        buttons: ["copy", "excel", "pdf"],
        language: {
            emptyTable: "No audit logs found for money transfers."
        }
    });

    // Simple client-side filtering
    $('#filterAction').on('change', function() {
        table.draw();
    });

    $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
        const actionFilter = $('#filterAction').val().toLowerCase();
        if (!actionFilter) return true;
        
        const actionText = data[2].toLowerCase(); // Action column
        return actionText.includes(actionFilter);
    });

    $('#clearAuditFilters').on('click', function() {
        $('#filterAction').val('');
        table.draw();
    });
});
</script>

<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';
?>