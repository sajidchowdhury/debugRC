<?php
ob_start();
require_once __DIR__ . '/../../helpers/JournalReportHelper.php';
$ledger = $ledger ?? [];
$balance = $balance ?? [];
$usage = $usage ?? [];
$journalLines = $journal_lines ?? [];
$childLedgers = $child_ledgers ?? [];
$bankAccounts = $bank_accounts ?? [];
$ledgerId = (int)($ledger['id'] ?? 0);
$isActive = !empty($ledger['is_active']);
$isSystem = !empty($ledger['is_system']);
$title = $title ?? 'Ledger account';

$trialBalanceUrl = BASE_URL . 'Report/TrialBalance?search=1'
    . '&account_type=' . urlencode((string)($ledger['account_type'] ?? ''));
$generalLedgerUrl = BASE_URL . 'Report/GeneralLedger?search=1'
    . '&ledger_id=' . $ledgerId
    . '&from_date=' . urlencode(date('Y-m-01'))
    . '&to_date=' . urlencode(date('Y-m-d'));
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/branch-index.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/ledger-theme.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/master-data-hub.css">

<div class="branch-hub ledger-theme container-fluid py-2">
    <header class="branch-hub-hero">
        <div>
            <h1>
                <i class="fas fa-book-open me-2"></i>
                <?= htmlspecialchars($ledger['ledger_name'] ?? 'Ledger', ENT_QUOTES) ?>
            </h1>
            <p>
                GL account hub — balance from journal lines, recent activity, and linked bank mappings.
            </p>
            <span class="hero-badge">
                <?= $isActive ? '<i class="fas fa-circle-check"></i> Active' : '<i class="fas fa-circle-xmark"></i> Inactive' ?>
                · <span class="branch-code-pill"><?= htmlspecialchars($ledger['ledger_code'] ?? '', ENT_QUOTES) ?></span>
                <?= $isSystem ? ' · <i class="fas fa-shield-halved"></i> System' : '' ?>
            </span>
        </div>
        <div class="branch-hub-actions">
            <a href="<?= BASE_URL ?>ledger/edit/<?= $ledgerId ?>" class="btn btn-outline-light btn-sm">
                <i class="fas fa-<?= $isSystem ? 'eye' : 'pen' ?> me-1"></i> <?= $isSystem ? 'View' : 'Edit' ?>
            </a>
            <a href="<?= $trialBalanceUrl ?>" class="btn btn-light btn-sm">
                <i class="fas fa-table me-1"></i> Trial balance
            </a>
            <a href="<?= $generalLedgerUrl ?>" class="btn btn-outline-light btn-sm">
                <i class="fas fa-book-open me-1"></i> General ledger
            </a>
            <a href="<?= BASE_URL ?>ledger" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Chart of accounts
            </a>
        </div>
    </header>

    <div class="branch-hub-stats">
        <div class="branch-stat-card">
            <div class="branch-stat-icon teal"><i class="fas fa-scale-balanced"></i></div>
            <div>
                <div class="stat-value">
                    Tk <?= number_format((float)($balance['balance'] ?? 0), 2) ?>
                    <span class="small"><?= htmlspecialchars($balance['balance_side'] ?? 'Dr', ENT_QUOTES) ?></span>
                </div>
                <div class="stat-label">GL balance (net)</div>
            </div>
        </div>
        <div class="branch-stat-card">
            <div class="branch-stat-icon amber"><i class="fas fa-arrow-down"></i></div>
            <div>
                <div class="stat-value">Tk <?= number_format((float)($balance['total_debit'] ?? 0), 0) ?></div>
                <div class="stat-label">Total debits</div>
            </div>
        </div>
        <div class="branch-stat-card">
            <div class="branch-stat-icon indigo"><i class="fas fa-arrow-up"></i></div>
            <div>
                <div class="stat-value">Tk <?= number_format((float)($balance['total_credit'] ?? 0), 0) ?></div>
                <div class="stat-label">Total credits</div>
            </div>
        </div>
        <div class="branch-stat-card">
            <div class="branch-stat-icon slate"><i class="fas fa-file-invoice"></i></div>
            <div>
                <div class="stat-value"><?= (int)($balance['line_count'] ?? 0) ?></div>
                <div class="stat-label">Journal lines</div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="branch-hub-panel mb-3">
                <div class="p-3 border-bottom">
                    <h2 class="h6 mb-0 fw-semibold"><i class="fas fa-clock-rotate-left me-1"></i> Recent journal activity</h2>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Date</th>
                                <th>Entry</th>
                                <th>Reference</th>
                                <th class="text-end">Debit</th>
                                <th class="text-end">Credit</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($journalLines)): ?>
                            <tr>
                                <td colspan="5" class="text-center text-muted py-4">No journal lines posted to this account yet.</td>
                            </tr>
                            <?php else: foreach ($journalLines as $line):
                                $refUrl = JournalReportHelper::referenceUrl($line['reference_type'] ?? null, $line['reference_id'] ?? 0);
                            ?>
                            <tr class="<?= !empty($line['is_reversed']) ? 'table-secondary' : '' ?>">
                                <td class="text-nowrap small"><?= htmlspecialchars($line['entry_date'] ?? '', ENT_QUOTES) ?></td>
                                <td>
                                    <a href="<?= BASE_URL ?>Report/JournalEntries?search=1&amp;from_date=<?= urlencode((string)($line['entry_date'] ?? date('Y-m-01'))) ?>&amp;to_date=<?= urlencode((string)($line['entry_date'] ?? date('Y-m-d'))) ?>&amp;q=<?= urlencode((string)($line['entry_no'] ?? '')) ?>"
                                       class="fw-semibold small text-decoration-none">
                                        <?= htmlspecialchars($line['entry_no'] ?? '', ENT_QUOTES) ?>
                                    </a>
                                    <?php if (!empty($line['is_reversed'])): ?>
                                    <span class="badge bg-danger-subtle text-danger">Reversed</span>
                                    <?php endif; ?>
                                </td>
                                <td class="small">
                                    <?php if ($refUrl): ?>
                                    <a href="<?= htmlspecialchars($refUrl, ENT_QUOTES) ?>">
                                        <?= htmlspecialchars(JournalReportHelper::referenceLabel($line['reference_type'] ?? null), ENT_QUOTES) ?>
                                        <?php if (!empty($line['reference_id'])): ?>
                                        <span class="text-muted">#<?= (int)$line['reference_id'] ?></span>
                                        <?php endif; ?>
                                    </a>
                                    <?php else: ?>
                                    <?= htmlspecialchars(str_replace('_', ' ', (string)($line['reference_type'] ?? '—')), ENT_QUOTES) ?>
                                    <?php if (!empty($line['reference_id'])): ?>
                                    <span class="text-muted">#<?= (int)$line['reference_id'] ?></span>
                                    <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end small"><?= (float)($line['debit'] ?? 0) > 0 ? number_format((float)$line['debit'], 2) : '—' ?></td>
                                <td class="text-end small"><?= (float)($line['credit'] ?? 0) > 0 ? number_format((float)$line['credit'], 2) : '—' ?></td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php if (!empty($childLedgers)): ?>
            <div class="branch-hub-panel">
                <div class="p-3 border-bottom">
                    <h2 class="h6 mb-0 fw-semibold"><i class="fas fa-sitemap me-1"></i> Child accounts</h2>
                </div>
                <ul class="list-group list-group-flush">
                    <?php foreach ($childLedgers as $child): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <a href="<?= BASE_URL ?>ledger/show/<?= (int)$child['id'] ?>" class="text-decoration-none fw-semibold">
                            <?= htmlspecialchars($child['ledger_name'] ?? '', ENT_QUOTES) ?>
                        </a>
                        <span class="small text-muted"><?= htmlspecialchars($child['ledger_code'] ?? '', ENT_QUOTES) ?></span>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
        </div>

        <div class="col-lg-4">
            <div class="branch-preview-card mb-3">
                <div class="aside-title mb-2">Account details</div>
                <dl class="row small mb-0 ledger-show-meta">
                    <dt class="col-5 text-muted">Type</dt>
                    <dd class="col-7"><?= htmlspecialchars($ledger['account_type'] ?? '—', ENT_QUOTES) ?></dd>
                    <dt class="col-5 text-muted">Nature</dt>
                    <dd class="col-7"><?= htmlspecialchars(str_replace('_', ' ', (string)($ledger['ledger_nature'] ?? '—')), ENT_QUOTES) ?></dd>
                    <dt class="col-5 text-muted">Normal</dt>
                    <dd class="col-7"><?= htmlspecialchars(ucfirst((string)($ledger['normal_balance'] ?? 'debit')), ENT_QUOTES) ?></dd>
                    <dt class="col-5 text-muted">Parent</dt>
                    <dd class="col-7">
                        <?php if (!empty($ledger['parent_id'])): ?>
                        <a href="<?= BASE_URL ?>ledger/show/<?= (int)$ledger['parent_id'] ?>">
                            <?= htmlspecialchars($ledger['parent_name'] ?? ('#' . $ledger['parent_id']), ENT_QUOTES) ?>
                        </a>
                        <?php else: ?>
                        Top level
                        <?php endif; ?>
                    </dd>
                    <?php if (!empty($ledger['is_control_account'])): ?>
                    <dt class="col-5 text-muted">Control</dt>
                    <dd class="col-7"><?= htmlspecialchars(ucfirst((string)($ledger['control_account_type'] ?? 'yes')), ENT_QUOTES) ?></dd>
                    <?php endif; ?>
                </dl>
                <?php if (!empty($ledger['description'])): ?>
                <div class="mt-3 small text-muted border-top pt-2">
                    <?= nl2br(htmlspecialchars((string)$ledger['description'], ENT_QUOTES)) ?>
                </div>
                <?php endif; ?>
            </div>

            <?php if (!empty($bankAccounts)): ?>
            <div class="branch-hub-panel mb-3">
                <div class="p-3 border-bottom">
                    <h2 class="h6 mb-0 fw-semibold"><i class="fas fa-building-columns me-1"></i> Linked bank accounts</h2>
                </div>
                <ul class="list-group list-group-flush">
                    <?php foreach ($bankAccounts as $bank): ?>
                    <li class="list-group-item">
                        <a href="<?= BASE_URL ?>bank/show/<?= (int)$bank['id'] ?>" class="fw-semibold text-decoration-none">
                            <?= htmlspecialchars($bank['bank_name'] ?? '', ENT_QUOTES) ?>
                        </a>
                        <div class="small text-muted">
                            <?= htmlspecialchars($bank['account_number'] ?? '', ENT_QUOTES) ?>
                            · Tk <?= number_format((float)($bank['balance'] ?? 0), 2) ?>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <div class="branch-aside-tip">
                <i class="fas fa-info-circle me-1"></i>
                Balance is cumulative from non-reversed journal lines. Use
                <a href="<?= $trialBalanceUrl ?>">Trial Balance</a> or
                <a href="<?= $generalLedgerUrl ?>">General Ledger</a> for full account history.
            </div>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';
