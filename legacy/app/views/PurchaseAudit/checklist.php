<?php
$report = $report ?? [];
$sections = $report['sections'] ?? [];
$summary = $report['summary'] ?? [];
$branchName = $branch_name ?? 'Branch';
$ranAt = $report['ran_at'] ?? date('Y-m-d H:i:s');
$negativeStocks = $report['negative_stocks'] ?? [];
$missingGrnJournals = $report['missing_grn_journals'] ?? [];
$missingReturnJournals = $report['missing_return_journals'] ?? [];

$statusIcon = static function (string $status): string {
    return match ($status) {
        'pass' => 'fa-check',
        'warn' => 'fa-exclamation-triangle',
        'fail' => 'fa-times',
        default => 'fa-info-circle',
    };
};

$title = $title ?? 'Purchase Module Audit Checklist';
ob_start();
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/purchase-audit-checklist.css">

<div class="purch-audit-app container-fluid py-2" id="purchase-audit-checklist">
    <header class="purch-audit-hero">
        <div>
            <h1><i class="fas fa-clipboard-check me-2"></i>Purchase audit checklist</h1>
            <p>Stock SSOT, GRN/return GL, supplier ledger, and data integrity — branch: <?= htmlspecialchars($branchName, ENT_QUOTES) ?></p>
        </div>
        <div class="d-flex gap-2 flex-shrink-0 flex-wrap">
            <button type="button" class="btn btn-light btn-sm" id="btnRefreshPurchaseAudit">
                <i class="fas fa-sync-alt me-1"></i> Re-run checks
            </button>
            <a href="<?= BASE_URL ?>PurchaseAudit/checklist" class="btn btn-light btn-sm"><i class="fas fa-clipboard-check me-1"></i> This checklist</a>
            <a href="<?= BASE_URL ?>PurchaseOrder" class="btn btn-light btn-sm"><i class="fas fa-file-invoice me-1"></i> PO</a>
            <a href="<?= BASE_URL ?>PurchaseReceive" class="btn btn-light btn-sm"><i class="fas fa-dolly me-1"></i> GRN</a>
            <a href="<?= BASE_URL ?>PurchaseReturn" class="btn btn-light btn-sm"><i class="fas fa-undo me-1"></i> Returns</a>
        </div>
    </header>

    <p class="purch-audit-meta" id="purchAuditMeta">
        Last run: <?= htmlspecialchars($ranAt, ENT_QUOTES) ?>
        <?php if (!empty($report['branch_id'])): ?>
        · Branch filter #<?= (int)$report['branch_id'] ?>
        <?php endif; ?>
    </p>

    <div class="purch-audit-summary" id="purchAuditSummary">
        <span class="chip pass"><i class="fas fa-check"></i> <?= (int)($summary['pass'] ?? 0) ?> pass</span>
        <span class="chip warn"><i class="fas fa-exclamation-triangle"></i> <?= (int)($summary['warn'] ?? 0) ?> warn</span>
        <span class="chip fail"><i class="fas fa-times"></i> <?= (int)($summary['fail'] ?? 0) ?> fail</span>
        <span class="chip info"><i class="fas fa-info-circle"></i> <?= (int)($summary['info'] ?? 0) ?> reference</span>
    </div>

    <nav class="purch-audit-toc" aria-label="Sections">
        <?php foreach ($sections as $section): ?>
        <a class="purch-audit-toc-link" href="#pa-section-<?= htmlspecialchars($section['id'] ?? '', ENT_QUOTES) ?>">
            <i class="fas <?= htmlspecialchars($section['icon'] ?? 'fa-folder', ENT_QUOTES) ?>"></i>
            <?= htmlspecialchars($section['title'] ?? '', ENT_QUOTES) ?>
        </a>
        <?php endforeach; ?>
    </nav>

    <div id="purchAuditSections">
        <?php foreach ($sections as $section): ?>
        <section class="purch-audit-section" id="pa-section-<?= htmlspecialchars($section['id'] ?? '', ENT_QUOTES) ?>">
            <div class="purch-audit-section-head">
                <i class="fas <?= htmlspecialchars($section['icon'] ?? 'fa-folder', ENT_QUOTES) ?>"></i>
                <?= htmlspecialchars($section['title'] ?? '', ENT_QUOTES) ?>
            </div>
            <?php foreach ($section['items'] ?? [] as $item):
                $st = (string)($item['status'] ?? 'info');
                ?>
            <article class="purch-audit-item status-<?= htmlspecialchars($st, ENT_QUOTES) ?>">
                <div class="status-icon"><i class="fas <?= $statusIcon($st) ?>"></i></div>
                <div>
                    <h3><?= htmlspecialchars($item['title'] ?? '', ENT_QUOTES) ?></h3>
                    <p class="expected"><?= htmlspecialchars($item['expected'] ?? '', ENT_QUOTES) ?></p>
                    <?php if (!empty($item['detail'])): ?>
                    <p class="detail"><?= htmlspecialchars($item['detail'], ENT_QUOTES) ?></p>
                    <?php endif; ?>
                    <?php if (!empty($item['url'])): ?>
                    <a href="<?= htmlspecialchars($item['url'], ENT_QUOTES) ?>" class="btn btn-outline-primary btn-sm purch-audit-item-link mt-1">
                        Open module
                    </a>
                    <?php endif; ?>
                </div>
                <span class="purch-audit-badge <?= htmlspecialchars($st, ENT_QUOTES) ?>"><?= htmlspecialchars($st, ENT_QUOTES) ?></span>
            </article>
            <?php endforeach; ?>
        </section>
        <?php endforeach; ?>
    </div>

    <?php if ($negativeStocks !== []): ?>
    <section class="purch-audit-section mt-3">
        <div class="purch-audit-section-head">
            <i class="fas fa-exclamation-circle"></i> Negative warehouse stock (action required)
        </div>
        <div class="table-responsive p-2">
            <table class="table table-sm table-bordered mb-0">
                <thead>
                    <tr>
                        <th>Warehouse</th>
                        <th>Product</th>
                        <th class="text-end">Qty</th>
                        <th class="text-end">Avg cost</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($negativeStocks as $row): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['warehouse_name'] ?? '', ENT_QUOTES) ?></td>
                        <td><?= htmlspecialchars($row['product_name'] ?? '', ENT_QUOTES) ?></td>
                        <td class="text-end"><?= number_format((float)($row['ws_qty'] ?? 0), 2) ?></td>
                        <td class="text-end"><?= number_format((float)($row['avg_cost'] ?? 0), 2) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php endif; ?>

    <?php if ($missingGrnJournals !== []): ?>
    <section class="purch-audit-section mt-3">
        <div class="purch-audit-section-head"><i class="fas fa-dolly"></i> GRNs missing journal (sample)</div>
        <div class="table-responsive p-2">
            <table class="table table-sm table-bordered mb-0">
                <thead><tr><th>GRN</th><th>Date</th><th class="text-end">Total</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($missingGrnJournals as $row): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['receive_code'] ?? '', ENT_QUOTES) ?></td>
                        <td><?= htmlspecialchars($row['receive_date'] ?? '', ENT_QUOTES) ?></td>
                        <td class="text-end"><?= number_format((float)($row['total_amount'] ?? 0), 2) ?></td>
                        <td><a href="<?= BASE_URL ?>PurchaseReceive/details/<?= (int)($row['id'] ?? 0) ?>">GL detail</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php endif; ?>

    <?php if ($missingReturnJournals !== []): ?>
    <section class="purch-audit-section mt-3">
        <div class="purch-audit-section-head"><i class="fas fa-undo-alt"></i> Returns missing journal (sample)</div>
        <div class="table-responsive p-2">
            <table class="table table-sm table-bordered mb-0">
                <thead><tr><th>Return</th><th>GRN</th><th class="text-end">Total</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($missingReturnJournals as $row): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['return_code'] ?? '', ENT_QUOTES) ?></td>
                        <td><?= htmlspecialchars($row['receive_code'] ?? '', ENT_QUOTES) ?></td>
                        <td class="text-end"><?= number_format((float)($row['total_amount'] ?? 0), 2) ?></td>
                        <td><a href="<?= BASE_URL ?>PurchaseReturn/details/<?= (int)($row['id'] ?? 0) ?>">GL detail</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php endif; ?>
</div>

<script>
(function () {
    const base = <?= json_encode(BASE_URL, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    const btn = document.getElementById('btnRefreshPurchaseAudit');
    if (!btn) return;

    const statusIcon = (st) => ({
        pass: 'fa-check',
        warn: 'fa-exclamation-triangle',
        fail: 'fa-times',
        info: 'fa-info-circle',
    }[st] || 'fa-info-circle');

    btn.addEventListener('click', async function () {
        btn.disabled = true;
        try {
            const res = await fetch(base + 'PurchaseAudit/run_checks', { credentials: 'same-origin' });
            const data = await res.json();
            if (!data || !data.sections) {
                Swal.fire('Error', 'Could not refresh audit report.', 'error');
                return;
            }
            document.getElementById('purchAuditMeta').textContent =
                'Last run: ' + (data.ran_at || '') + (data.branch_id ? ' · Branch filter #' + data.branch_id : '');
            const s = data.summary || {};
            document.getElementById('purchAuditSummary').innerHTML =
                '<span class="chip pass"><i class="fas fa-check"></i> ' + (s.pass || 0) + ' pass</span>'
                + '<span class="chip warn"><i class="fas fa-exclamation-triangle"></i> ' + (s.warn || 0) + ' warn</span>'
                + '<span class="chip fail"><i class="fas fa-times"></i> ' + (s.fail || 0) + ' fail</span>'
                + '<span class="chip info"><i class="fas fa-info-circle"></i> ' + (s.info || 0) + ' reference</span>';

            const esc = (t) => {
                const d = document.createElement('div');
                d.textContent = t == null ? '' : String(t);
                return d.innerHTML;
            };

            let html = '';
            data.sections.forEach((section) => {
                html += '<section class="purch-audit-section" id="pa-section-' + esc(section.id) + '">'
                    + '<div class="purch-audit-section-head"><i class="fas ' + esc(section.icon || 'fa-folder') + '"></i> '
                    + esc(section.title) + '</div>';
                (section.items || []).forEach((item) => {
                    const st = item.status || 'info';
                    html += '<article class="purch-audit-item status-' + esc(st) + '">'
                        + '<div class="status-icon"><i class="fas ' + statusIcon(st) + '"></i></div><div>'
                        + '<h3>' + esc(item.title) + '</h3><p class="expected">' + esc(item.expected) + '</p>';
                    if (item.detail) html += '<p class="detail">' + esc(item.detail) + '</p>';
                    if (item.url) {
                        html += '<a href="' + esc(item.url) + '" class="btn btn-outline-primary btn-sm purch-audit-item-link mt-1">Open module</a>';
                    }
                    html += '</div><span class="purch-audit-badge ' + esc(st) + '">' + esc(st) + '</span></article>';
                });
                html += '</section>';
            });
            document.getElementById('purchAuditSections').innerHTML = html;
            Swal.fire('Updated', 'Audit checks completed.', 'success');
        } catch (e) {
            Swal.fire('Error', 'Network error while refreshing.', 'error');
        } finally {
            btn.disabled = false;
        }
    });
})();
</script>
<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';