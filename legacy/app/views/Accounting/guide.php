<?php
ob_start();
$title = $title ?? 'Accounting user guide';
$hubUrl = BASE_URL . 'Accounting/index';
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/branch-index.css">

<div class="branch-hub accounting-hub container-fluid py-2">
    <header class="branch-hub-hero">
        <div>
            <h1><i class="fas fa-book-open me-2"></i>Accounting user guide</h1>
            <p>Day-to-day workflows for GL, payments, reconciliation, reports, and period close.</p>
        </div>
        <div class="branch-hub-actions">
            <a href="<?= htmlspecialchars($hubUrl, ENT_QUOTES) ?>" class="btn btn-light btn-sm">
                <i class="fas fa-calculator me-1"></i> Dashboard
            </a>
        </div>
    </header>

    <div class="branch-hub-panel accounting-guide-doc">
        <nav class="accounting-guide-toc mb-4" aria-label="Guide sections">
            <a href="#start">Dashboard</a>
            <a href="#daily">Daily work</a>
            <a href="#reports">Reports</a>
            <a href="#recon">Reconciliation</a>
            <a href="#period">Period close</a>
            <a href="#mobile">Mobile</a>
        </nav>

        <section id="start" class="mb-4">
            <h2 class="h5 fw-bold">1. Start at the dashboard</h2>
            <p class="small text-muted">Open <strong>Accounting home</strong> to see trial balance status, reconciliation traffic lights, period lock, and recent journal activity for your branch.</p>
            <ul class="small">
                <li><strong>Balanced TB</strong> — month-to-date debits equal credits.</li>
                <li><strong>Traffic lights</strong> — tap a section to open GL reconciliation.</li>
                <li><strong>Recent journals</strong> — jump to source documents when linked.</li>
            </ul>
        </section>

        <section id="daily" class="mb-4">
            <h2 class="h5 fw-bold">2. Daily workflows</h2>
            <div class="row g-3">
                <div class="col-md-6">
                    <div class="accounting-hub-tile h-100">
                        <i class="fas fa-hand-holding-dollar"></i>
                        <div>
                            <strong>Customer payments</strong>
                            <span>Receive, refund, discount, write-off — check GL preview before save.</span>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="accounting-hub-tile h-100">
                        <i class="fas fa-truck"></i>
                        <div>
                            <strong>Supplier payments</strong>
                            <span>Payment, advance, receive against AP.</span>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="accounting-hub-tile h-100">
                        <i class="fas fa-user-tie"></i>
                        <div>
                            <strong>Employee money</strong>
                            <span>Advance, loan, salary, repayment, deduction.</span>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="accounting-hub-tile h-100">
                        <i class="fas fa-pen-to-square"></i>
                        <div>
                            <strong>Manual journals</strong>
                            <span>Balanced adjusting entries; debits must equal credits.</span>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section id="reports" class="mb-4">
            <h2 class="h5 fw-bold">3. Reports</h2>
            <p class="small text-muted">All financial and audit reports live under <strong>Reports</strong> in the sidebar — not in module quick nav.</p>
            <p class="small mb-0">Trial balance · General ledger · P&amp;L · Balance sheet · Cash flow · Aging · GL audit checklists (Operations category).</p>
        </section>

        <section id="recon" class="mb-4">
            <h2 class="h5 fw-bold">4. GL reconciliation</h2>
            <p class="small">Compare sub-ledgers (AR, AP, employee, bank register, stock) to GL control accounts. Fix <span class="text-danger">red</span> sections before month-end; review <span class="text-warning">amber</span> mismatches within tolerance.</p>
        </section>

        <section id="period" class="mb-4">
            <h2 class="h5 fw-bold">5. Period close &amp; year-end</h2>
            <ol class="small">
                <li>Run <strong>Year-end checklist</strong> (TB + reconciliation + backup checks).</li>
                <li>Export TB / GL archive when checks pass.</li>
                <li>Apply <strong>Period close</strong> with closed-through date per branch.</li>
            </ol>
            <p class="small text-muted mb-0">Reopening a closed period requires superadmin.</p>
        </section>

        <section id="mobile" class="mb-2">
            <h2 class="h5 fw-bold">6. Phone / tablet</h2>
            <p class="small mb-0">List pages show <strong>cards</strong> on narrow screens. Use the <strong>Filters</strong> panel to search; buttons are sized for touch.</p>
        </section>

        <p class="small text-muted mt-4 mb-0">
            Full documentation for developers: <code>docs/ACCOUNTING_USER_GUIDE.md</code> in the repository.
        </p>
    </div>
</div>
<?php
$content = ob_get_clean();
require_once __DIR__ . '/../layouts/main.php';
