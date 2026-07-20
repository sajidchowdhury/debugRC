<?php
/**
 * Premium report frame — expects:
 *   $rpt (array from ReportsCatalog::get)
 *   $rpt_filters (HTML string)
 *   $rpt_body (HTML string)
 *   $rpt_kpis (optional HTML string)
 *   $rpt_has_run (bool)
 *   $rpt_export_url (optional string)
 */
$rpt = $rpt ?? [];
$accent = $rpt['category_accent'] ?? 'finance';
$title = $rpt['title'] ?? ($title ?? 'Report');
$route = $rpt['route'] ?? '';
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/reports-premium.css">
<div class="rpt-frame container-fluid py-2">
    <header class="rpt-frame-hero <?= htmlspecialchars($accent, ENT_QUOTES) ?> rpt-no-print">
        <div>
            <div class="rpt-breadcrumb">
                <a href="<?= BASE_URL ?>Report"><i class="fas fa-table-cells-large me-1"></i> Reports</a>
                <span class="mx-1">/</span>
                <span><?= htmlspecialchars($rpt['category_label'] ?? 'Report', ENT_QUOTES) ?></span>
            </div>
            <h1><i class="fas <?= htmlspecialchars($rpt['icon'] ?? 'fa-chart-bar', ENT_QUOTES) ?> me-2"></i><?= htmlspecialchars($title, ENT_QUOTES) ?></h1>
            <?php if (!empty($rpt['tagline'])): ?>
            <p class="rpt-subtitle"><?= htmlspecialchars($rpt['tagline'], ENT_QUOTES) ?></p>
            <?php endif; ?>
        </div>
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <?php if (!empty($rpt_export_url)): ?>
            <a href="<?= htmlspecialchars($rpt_export_url, ENT_QUOTES) ?>" class="btn btn-light btn-sm">
                <i class="fas fa-file-csv me-1"></i> Export CSV
            </a>
            <?php endif; ?>
            <?php if (!empty($rpt_export_pdf_url)): ?>
            <a href="<?= htmlspecialchars($rpt_export_pdf_url, ENT_QUOTES) ?>" class="btn btn-outline-light btn-sm" target="_blank">
                <i class="fas fa-file-pdf me-1"></i> PDF
            </a>
            <?php endif; ?>
            <?php if (!empty($rpt_has_run)): ?>
            <button type="button" class="btn btn-outline-light btn-sm" onclick="window.print()">
                <i class="fas fa-print me-1"></i> Print
            </button>
            <?php endif; ?>
            <a href="<?= BASE_URL ?>Report" class="btn btn-outline-light btn-sm">
                <i class="fas fa-th-large me-1"></i> All reports
            </a>
        </div>
    </header>

    <div class="rpt-filter-dock rpt-no-print" id="rptFilterDock">
        <div class="rpt-filter-dock-head">
            <span><i class="fas fa-sliders me-1"></i> Report parameters</span>
            <i class="fas fa-chevron-up small"></i>
        </div>
        <div class="rpt-filter-dock-body">
            <?= $rpt_filters ?? '' ?>
        </div>
    </div>

    <?php if (!empty($rpt_kpis)): ?>
    <div class="rpt-kpi-strip rpt-no-print"><?= $rpt_kpis ?></div>
    <?php endif; ?>

    <div class="rpt-result-area">
        <?= $rpt_body ?? '' ?>
    </div>
</div>
<script src="<?= BASE_URL ?>assets/js/ReportsHub.js"></script>