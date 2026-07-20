<?php
$report = $report ?? [];
$sections = $report['sections'] ?? [];
$summary = $report['summary'] ?? [];
$branchName = $branch_name ?? 'Branch';
$ranAt = $report['ran_at'] ?? date('Y-m-d H:i:s');

$statusIcon = static function (string $status): string {
    return match ($status) {
        'pass' => 'fa-check',
        'warn' => 'fa-exclamation-triangle',
        'fail' => 'fa-times',
        default => 'fa-info-circle',
    };
};

$title = $title ?? 'Warehouse Transfer Audit';
ob_start();
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/purchase-audit-checklist.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/stock-take.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/warehouse-transfer.css">

<div class="purch-audit-app wt-transfer-app container-fluid py-2">
    <header class="purch-index-hero purch-audit-hero wt-hero" style="border-radius:14px;margin-bottom:1rem">
        <div>
            <h1><i class="fas fa-clipboard-check me-2"></i>Warehouse transfer audit</h1>
            <p>Same-branch stock moves, demand linkage — <?= htmlspecialchars($branchName, ENT_QUOTES) ?></p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <button type="button" class="btn btn-light btn-sm" id="btnRefreshWtAudit"><i class="fas fa-sync-alt me-1"></i> Re-run</button>
            <a href="<?= BASE_URL ?>WarehouseTransfer" class="btn btn-light btn-sm"><i class="fas fa-truck-loading me-1"></i> Transfers</a>
        </div>
    </header>

    <p class="purch-audit-meta" id="wtAuditMeta">Last run: <?= htmlspecialchars($ranAt, ENT_QUOTES) ?></p>
    <div class="purch-audit-summary" id="wtAuditSummary">
        <span class="chip pass"><i class="fas fa-check"></i> <?= (int)($summary['pass'] ?? 0) ?> pass</span>
        <span class="chip warn"><i class="fas fa-exclamation-triangle"></i> <?= (int)($summary['warn'] ?? 0) ?> warn</span>
        <span class="chip fail"><i class="fas fa-times"></i> <?= (int)($summary['fail'] ?? 0) ?> fail</span>
        <span class="chip info"><i class="fas fa-info-circle"></i> <?= (int)($summary['info'] ?? 0) ?> ref</span>
    </div>

    <div id="wtAuditSections">
        <?php foreach ($sections as $section): ?>
        <section class="purch-audit-section">
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
                    <?php if (!empty($item['detail'])): ?><p class="detail"><?= htmlspecialchars($item['detail'], ENT_QUOTES) ?></p><?php endif; ?>
                    <?php if (!empty($item['url'])): ?>
                    <a href="<?= htmlspecialchars($item['url'], ENT_QUOTES) ?>" class="btn btn-outline-primary btn-sm mt-1">Open</a>
                    <?php endif; ?>
                </div>
                <span class="purch-audit-badge <?= htmlspecialchars($st, ENT_QUOTES) ?>"><?= htmlspecialchars($st, ENT_QUOTES) ?></span>
            </article>
            <?php endforeach; ?>
        </section>
        <?php endforeach; ?>
    </div>
</div>

<script>
(function () {
    const base = <?= json_encode(BASE_URL, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    const btn = document.getElementById('btnRefreshWtAudit');
    if (!btn) return;
    const statusIcon = (st) => ({ pass: 'fa-check', warn: 'fa-exclamation-triangle', fail: 'fa-times', info: 'fa-info-circle' }[st] || 'fa-info-circle');
    btn.addEventListener('click', async () => {
        btn.disabled = true;
        try {
            const res = await fetch(base + 'WarehouseTransfer/run_checks', { credentials: 'same-origin' });
            const data = await res.json();
            if (!data?.sections) { Swal.fire('Error', 'Could not refresh.', 'error'); return; }
            document.getElementById('wtAuditMeta').textContent = 'Last run: ' + (data.ran_at || '');
            const s = data.summary || {};
            document.getElementById('wtAuditSummary').innerHTML =
                `<span class="chip pass">${s.pass||0} pass</span><span class="chip warn">${s.warn||0} warn</span><span class="chip fail">${s.fail||0} fail</span>`;
            const esc = t => { const d = document.createElement('div'); d.textContent = t ?? ''; return d.innerHTML; };
            let html = '';
            data.sections.forEach(sec => {
                html += `<section class="purch-audit-section"><div class="purch-audit-section-head"><i class="fas ${esc(sec.icon||'fa-folder')}"></i> ${esc(sec.title)}</div>`;
                (sec.items||[]).forEach(it => {
                    const st = it.status||'info';
                    html += `<article class="purch-audit-item status-${esc(st)}"><div class="status-icon"><i class="fas ${statusIcon(st)}"></i></div><div><h3>${esc(it.title)}</h3><p class="expected">${esc(it.expected)}</p>`;
                    if (it.detail) html += `<p class="detail">${esc(it.detail)}</p>`;
                    if (it.url) html += `<a href="${esc(it.url)}" class="btn btn-outline-primary btn-sm mt-1">Open</a>`;
                    html += `</div><span class="purch-audit-badge ${esc(st)}">${esc(st)}</span></article>`;
                });
                html += '</section>';
            });
            document.getElementById('wtAuditSections').innerHTML = html;
            Swal.fire('Updated', 'Checks completed.', 'success');
        } catch { Swal.fire('Error', 'Network error', 'error'); }
        finally { btn.disabled = false; }
    });
})();
</script>
<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';