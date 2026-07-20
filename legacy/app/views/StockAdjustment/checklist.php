<?php
$report = $report ?? [];
$sections = $report['sections'] ?? [];
$summary = $report['summary'] ?? [];
$branchName = $branch_name ?? 'Branch';
$ranAt = $report['ran_at'] ?? date('Y-m-d H:i:s');
$missingAdjustmentJournals = $report['missing_adjustment_journals'] ?? [];
$missingDamageJournals = $report['missing_damage_journals'] ?? [];

$statusIcon = static function (string $status): string {
    return match ($status) {
        'pass' => 'fa-check',
        'warn' => 'fa-exclamation-triangle',
        'fail' => 'fa-times',
        default => 'fa-info-circle',
    };
};

$title = $title ?? 'Stock Adjustment Audit';
ob_start();
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/purchase-audit-checklist.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/stock-take.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/stock-adjustment.css">

<div class="purch-audit-app st-take-app sa-adjust-app container-fluid py-2" id="stock-adjustment-audit-checklist">
    <header class="purch-index-hero purch-audit-hero sa-hero" style="border-radius:14px;margin-bottom:1rem">
        <div>
            <h1><i class="fas fa-clipboard-check me-2"></i>Stock adjustment audit</h1>
            <p>Stock/GL alignment, data quality — <?= htmlspecialchars($branchName, ENT_QUOTES) ?></p>
        </div>
        <div class="d-flex gap-2 flex-shrink-0 flex-wrap">
            <button type="button" class="btn btn-light btn-sm" id="btnRefreshSaAudit">
                <i class="fas fa-sync-alt me-1"></i> Re-run checks
            </button>
            <a href="<?= BASE_URL ?>StockAdjustment" class="btn btn-light btn-sm"><i class="fas fa-balance-scale me-1"></i> Adjustments</a>
            <a href="<?= BASE_URL ?>StockTake" class="btn btn-light btn-sm"><i class="fas fa-cubes me-1"></i> Stock Take</a>
        </div>
    </header>

    <p class="purch-audit-meta" id="saAuditMeta">Last run: <?= htmlspecialchars($ranAt, ENT_QUOTES) ?></p>

    <div class="purch-audit-summary" id="saAuditSummary">
        <span class="chip pass"><i class="fas fa-check"></i> <?= (int)($summary['pass'] ?? 0) ?> pass</span>
        <span class="chip warn"><i class="fas fa-exclamation-triangle"></i> <?= (int)($summary['warn'] ?? 0) ?> warn</span>
        <span class="chip fail"><i class="fas fa-times"></i> <?= (int)($summary['fail'] ?? 0) ?> fail</span>
        <span class="chip info"><i class="fas fa-info-circle"></i> <?= (int)($summary['info'] ?? 0) ?> reference</span>
    </div>

    <div id="saAuditSections">
        <?php foreach ($sections as $section): ?>
        <section class="purch-audit-section" id="sa-section-<?= htmlspecialchars($section['id'] ?? '', ENT_QUOTES) ?>">
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
                    <a href="<?= htmlspecialchars($item['url'], ENT_QUOTES) ?>" class="btn btn-outline-primary btn-sm purch-audit-item-link mt-1">Open</a>
                    <?php endif; ?>
                </div>
                <span class="purch-audit-badge <?= htmlspecialchars($st, ENT_QUOTES) ?>"><?= htmlspecialchars($st, ENT_QUOTES) ?></span>
            </article>
            <?php endforeach; ?>
        </section>
        <?php endforeach; ?>
    </div>

    <?php if ($missingAdjustmentJournals !== []): ?>
    <section class="purch-audit-section mt-3">
        <div class="purch-audit-section-head"><i class="fas fa-balance-scale"></i> Adjustments missing journal (sample)</div>
        <div class="table-responsive p-2">
            <table class="table table-sm table-bordered mb-0">
                <thead><tr><th>Code</th><th>Date</th><th>Type</th><th class="text-end">Amount</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($missingAdjustmentJournals as $row): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['adjustment_code'] ?? '', ENT_QUOTES) ?></td>
                        <td><?= htmlspecialchars($row['adjustment_date'] ?? '', ENT_QUOTES) ?></td>
                        <td><?= htmlspecialchars(ucfirst((string)($row['adjustment_type'] ?? '')), ENT_QUOTES) ?></td>
                        <td class="text-end"><?= number_format((float)($row['total_amount'] ?? 0), 2) ?></td>
                        <td><a href="<?= BASE_URL ?>StockAdjustment/details/<?= (int)($row['id'] ?? 0) ?>">GL detail</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php endif; ?>

    <?php if ($missingDamageJournals !== []): ?>
    <section class="purch-audit-section mt-3">
        <div class="purch-audit-section-head"><i class="fas fa-heart-crack"></i> Damage records missing journal (sample)</div>
        <div class="table-responsive p-2">
            <table class="table table-sm table-bordered mb-0">
                <thead><tr><th>Code</th><th>Date</th><th class="text-end">Value</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($missingDamageJournals as $row): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['damage_code'] ?? '', ENT_QUOTES) ?></td>
                        <td><?= htmlspecialchars($row['damage_date'] ?? '', ENT_QUOTES) ?></td>
                        <td class="text-end"><?= number_format((float)($row['total_value'] ?? 0), 2) ?></td>
                        <td><a href="<?= BASE_URL ?>Damage/details/<?= (int)($row['id'] ?? 0) ?>">GL detail</a></td>
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
    const btn = document.getElementById('btnRefreshSaAudit');
    if (!btn) return;

    const statusIcon = (st) => ({
        pass: 'fa-check', warn: 'fa-exclamation-triangle', fail: 'fa-times', info: 'fa-info-circle',
    }[st] || 'fa-info-circle');

    btn.addEventListener('click', async () => {
        btn.disabled = true;
        try {
            const res = await fetch(base + 'StockAdjustment/run_checks', { credentials: 'same-origin' });
            const data = await res.json();
            if (!data?.sections) {
                Swal.fire('Error', 'Could not refresh audit report.', 'error');
                return;
            }
            document.getElementById('saAuditMeta').textContent = 'Last run: ' + (data.ran_at || '');
            const s = data.summary || {};
            document.getElementById('saAuditSummary').innerHTML =
                `<span class="chip pass"><i class="fas fa-check"></i> ${s.pass || 0} pass</span>`
                + `<span class="chip warn"><i class="fas fa-exclamation-triangle"></i> ${s.warn || 0} warn</span>`
                + `<span class="chip fail"><i class="fas fa-times"></i> ${s.fail || 0} fail</span>`
                + `<span class="chip info"><i class="fas fa-info-circle"></i> ${s.info || 0} reference</span>`;

            const esc = (t) => { const d = document.createElement('div'); d.textContent = t ?? ''; return d.innerHTML; };
            let html = '';
            data.sections.forEach((section) => {
                html += `<section class="purch-audit-section"><div class="purch-audit-section-head"><i class="fas ${esc(section.icon || 'fa-folder')}"></i> ${esc(section.title)}</div>`;
                (section.items || []).forEach((item) => {
                    const st = item.status || 'info';
                    html += `<article class="purch-audit-item status-${esc(st)}"><div class="status-icon"><i class="fas ${statusIcon(st)}"></i></div><div>`
                        + `<h3>${esc(item.title)}</h3><p class="expected">${esc(item.expected)}</p>`;
                    if (item.detail) html += `<p class="detail">${esc(item.detail)}</p>`;
                    if (item.url) html += `<a href="${esc(item.url)}" class="btn btn-outline-primary btn-sm mt-1">Open</a>`;
                    html += `</div><span class="purch-audit-badge ${esc(st)}">${esc(st)}</span></article>`;
                });
                html += '</section>';
            });
            document.getElementById('saAuditSections').innerHTML = html;
            Swal.fire('Updated', 'Checks completed.', 'success');
        } catch {
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