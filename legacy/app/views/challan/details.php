<?php
$challan = $challan ?? [];
$journal_blocks = $journal_blocks ?? [];
$isReversed = !empty($challan['is_reversed']);
$customerLabel = trim($challan['shop_name'] ?? '') ?: trim($challan['customer_name'] ?? 'Customer');
$title = 'Challan — ' . ($challan['challan_code'] ?? '');
ob_start();
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/branch-index.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/accounting-money-flow.css">

<div class="branch-hub acct-money-app container-fluid py-2">
    <header class="branch-hub-hero">
        <div>
            <h1><i class="fas fa-truck-loading me-2"></i><?= htmlspecialchars($challan['challan_code'] ?? '', ENT_QUOTES) ?></h1>
            <p>
                Invoice <?= htmlspecialchars($challan['invoice_code'] ?? '', ENT_QUOTES) ?>
                · <?= htmlspecialchars($customerLabel, ENT_QUOTES) ?>
                · <?= htmlspecialchars($challan['branch_name'] ?? '', ENT_QUOTES) ?>
            </p>
            <span class="hero-badge ms-0">
                <?= $isReversed ? '<i class="fas fa-circle-xmark"></i> Reversed' : '<i class="fas fa-circle-check"></i> Active' ?>
            </span>
        </div>
        <div class="branch-hub-actions d-flex flex-wrap gap-2">
            <a href="<?= BASE_URL ?>Challan/challan_copy/<?= (int)($challan['invoice_id'] ?? 0) ?>" class="btn btn-outline-light btn-sm" target="_blank" rel="noopener">
                <i class="fas fa-print me-1"></i> Print challan
            </a>
            <a href="<?= BASE_URL ?>sales/show/<?= (int)($challan['invoice_id'] ?? 0) ?>" class="btn btn-outline-light btn-sm">
                <i class="fas fa-file-invoice me-1"></i> Invoice GL
            </a>
            <a href="<?= BASE_URL ?>challan" class="btn btn-light btn-sm"><i class="fas fa-arrow-left me-1"></i> Queue</a>
        </div>
    </header>

    <div class="branch-hub-stats mb-3">
        <div class="branch-stat-card">
            <div class="branch-stat-icon indigo"><i class="fas fa-calendar"></i></div>
            <div><div class="stat-value small"><?= !empty($challan['challan_date']) ? date('d M Y', strtotime($challan['challan_date'])) : '—' ?></div><div class="stat-label">Challan date</div></div>
        </div>
        <div class="branch-stat-card">
            <div class="branch-stat-icon teal"><i class="fas fa-box"></i></div>
            <div><div class="stat-value small"><?= !empty($challan['journal_entry_id']) ? 'JE #' . (int)$challan['journal_entry_id'] : '—' ?></div><div class="stat-label">COGS journal</div></div>
        </div>
        <div class="branch-stat-card">
            <div class="branch-stat-icon amber"><i class="fas fa-truck"></i></div>
            <div><div class="stat-value small"><?= number_format((float)($challan['transport_adjustment'] ?? 0), 2) ?></div><div class="stat-label">Transport adj.</div></div>
        </div>
    </div>

    <?php include __DIR__ . '/../partials/sales_gl_journal_blocks.php'; ?>
</div>
<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';
