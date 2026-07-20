<?php
$return = $return ?? [];
$reversal = $reversal ?? [];
$branchName = $session_branch_name ?? 'Branch';

if (empty($return)) {
    ob_start();
    echo '<div class="container-fluid py-4"><div class="alert alert-danger">Return not found or access denied.</div>';
    echo '<a href="' . BASE_URL . 'SalesReturn" class="btn btn-secondary">Back to list</a></div>';
    $content = ob_get_clean();
    require_once '../app/views/layouts/main.php';
    return;
}

$returnCode = $return['return_code'] ?? '';
$status = (string)($return['status'] ?? 'pending');
$isReversed = !empty($return['is_reversed']);
$isCompleted = $status === 'completed';
$canReverse = !empty($reversal['can_reverse']);
$items = $return['items'] ?? [];
$stockLines = $reversal['stock_lines'] ?? [];

$formatMoney = static fn ($n) => 'Tk ' . number_format((float)$n, 2);
$formatQty = static function ($n) {
    $v = (float)$n;
    return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.') ?: '0';
};

$title = 'Reverse return — ' . $returnCode;
ob_start();
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/sales-return-index.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/sales-return-reverse.css">
<meta name="theme-color" content="#e11d48">

<div id="sr-reverse-app" class="sr-reverse-app container-fluid py-2">
    <header class="sr-reverse-hero">
        <div>
            <h1><i class="fas fa-undo me-2"></i>Reverse sales return</h1>
            <p>Undo return effects — stock, customer balance, and general ledger</p>
            <span class="sales-return-branch-tag"><i class="fas fa-map-marker-alt me-1"></i><?= htmlspecialchars($branchName, ENT_QUOTES) ?></span>
        </div>
        <div class="sr-reverse-hero-actions">
            <a href="<?= BASE_URL ?>SalesReturn/slip/<?= (int)($return['id'] ?? 0) ?>" class="btn btn-light btn-sm" target="_blank" rel="noopener">
                <i class="fas fa-print"></i> Slip
            </a>
            <a href="<?= BASE_URL ?>SalesReturn" class="btn btn-light btn-sm">
                <i class="fas fa-list"></i> List
            </a>
        </div>
    </header>

    <?php if ($isReversed): ?>
    <div class="alert alert-secondary">
        <i class="fas fa-ban me-1"></i> This return is already reversed and cannot be processed again.
    </div>
    <?php endif; ?>

    <?php if (!$canReverse): ?>
    <div class="alert alert-danger">
        <i class="fas fa-exclamation-triangle me-1"></i>
        <?= htmlspecialchars($reversal['block_reason'] ?? 'This return cannot be reversed.', ENT_QUOTES) ?>
    </div>
    <?php endif; ?>

    <?php if ($canReverse): ?>

    <div class="sr-reverse-layout">
        <section class="sr-reverse-panel">
            <div class="sr-reverse-summary">
                <div class="sr-reverse-stat">
                    <span class="label">Return</span>
                    <span class="value"><?= htmlspecialchars($returnCode, ENT_QUOTES) ?></span>
                </div>
                <div class="sr-reverse-stat">
                    <span class="label">Invoice</span>
                    <span class="value"><?= htmlspecialchars($return['invoice_code'] ?? '—', ENT_QUOTES) ?></span>
                </div>
                <div class="sr-reverse-stat">
                    <span class="label">Customer</span>
                    <span class="value"><?= htmlspecialchars($return['shop_name'] ?? '—', ENT_QUOTES) ?></span>
                    <span class="sub"><?= htmlspecialchars($return['mobile'] ?? '', ENT_QUOTES) ?></span>
                </div>
                <div class="sr-reverse-stat highlight">
                    <span class="label">Amount</span>
                    <span class="value"><?= $formatMoney($return['total_amount'] ?? 0) ?></span>
                    <span class="sub">
                        <span class="badge <?= $isCompleted ? 'bg-success' : 'bg-warning text-dark' ?>">
                            <?= $isCompleted ? 'Completed' : 'Pending warehouse' ?>
                        </span>
                    </span>
                </div>
            </div>

            <?php if (!empty($return['reason'])): ?>
            <div class="sr-reverse-original-reason">
                <strong>Original reason:</strong>
                <?= nl2br(htmlspecialchars($return['reason'], ENT_QUOTES)) ?>
            </div>
            <?php endif; ?>

            <form id="srReverseForm" method="POST" action="<?= BASE_URL ?>SalesReturn/reverse/<?= (int)($return['id'] ?? 0) ?>">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>">

                <div class="mb-3">
                    <label class="form-label" for="reverse_reason">Reversal reason <span class="text-danger">*</span></label>
                    <textarea id="reverse_reason" name="reason" class="form-control" rows="4" required minlength="5"
                              maxlength="500"
                              placeholder="Why is this return being reversed? (min 5 characters)"></textarea>
                </div>

                <div class="sr-reverse-form-actions">
                    <a href="<?= BASE_URL ?>SalesReturn" class="btn btn-outline-secondary">Cancel</a>
                    <button type="submit" class="btn btn-danger" id="btnConfirmReverse">
                        <i class="fas fa-undo me-1"></i> Confirm reversal
                    </button>
                </div>
            </form>
        </section>

        <aside class="sr-reverse-side">
            <div class="sr-reverse-effects">
                <h3><i class="fas fa-bolt me-1"></i>What will happen</h3>
                <?php if ($isCompleted): ?>
                <ul>
                    <li><strong>GL:</strong> Reversing journal entry
                        <?php if (!empty($return['journal_entry_id'])): ?>
                        #<?= (int)$return['journal_entry_id'] ?>
                        <?php else: ?>
                        <span class="text-warning">(lookup by return reference)</span>
                        <?php endif; ?>
                    </li>
                    <li><strong>Customer AR:</strong> Debit <?= $formatMoney($reversal['ledger_amount'] ?? $return['total_amount']) ?> — restores amount customer owes</li>
                    <?php if (!empty($reversal['cogs_amount'])): ?>
                    <li><strong>COGS / inventory:</strong> Reverses cost layer (~<?= $formatMoney($reversal['cogs_amount']) ?>) via linked journal</li>
                    <?php endif; ?>
                    <li><strong>Stock:</strong> Remove <?= (int)($reversal['stock_line_count'] ?? 0) ?> warehouse IN line(s) (Good items only)</li>
                    <li>Return flagged <strong>reversed</strong> (cannot confirm or edit)</li>
                </ul>
                <?php else: ?>
                <ul>
                    <li>No stock or GL was posted yet (return was <strong>pending</strong>)</li>
                    <li>Return will be marked <strong>reversed</strong> and removed from warehouse queue</li>
                    <li>Invoice lines become returnable again for a new return</li>
                </ul>
                <?php endif; ?>
            </div>

            <?php if ($isCompleted && !empty($stockLines)): ?>
            <div class="sr-reverse-stock-preview">
                <h3>Stock to remove</h3>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered mb-0">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Warehouse</th>
                                <th class="text-end">Qty</th>
                                <th class="text-end">On hand</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($stockLines as $line):
                            $needQty = (float)($line['qty'] ?? 0);
                            $onHand = (float)($line['physical_qty'] ?? 0);
                            $short = $needQty > $onHand + 0.0001;
                            ?>
                            <tr class="<?= $short ? 'table-danger' : '' ?>">
                                <td><?= htmlspecialchars($line['product_name'] ?? '', ENT_QUOTES) ?></td>
                                <td><?= htmlspecialchars($line['warehouse_name'] ?? '—', ENT_QUOTES) ?></td>
                                <td class="text-end"><?= $formatQty($needQty) ?></td>
                                <td class="text-end">
                                    <?= $formatQty($onHand) ?>
                                    <?php if ($short): ?>
                                    <span class="badge bg-danger ms-1">Short</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!empty($items)): ?>
            <div class="sr-reverse-items">
                <h3>Return lines</h3>
                <ul class="list-unstyled mb-0 small">
                    <?php foreach ($items as $item): ?>
                    <li class="mb-1">
                        <?= htmlspecialchars($item['product_name'] ?? '', ENT_QUOTES) ?>
                        · <?= $formatQty($item['return_qty'] ?? 0) ?>
                        <?= htmlspecialchars($item['unit'] ?? '', ENT_QUOTES) ?>
                        <?php if (!empty($item['warehouse_name'])): ?>
                        <span class="text-muted">→ <?= htmlspecialchars($item['warehouse_name'], ENT_QUOTES) ?></span>
                        <?php endif; ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
        </aside>
    </div>
    <?php endif; ?>

    <?php if (!$canReverse && !empty($stockLines)): ?>
    <div class="sr-reverse-layout mt-2">
        <aside class="sr-reverse-side w-100">
            <div class="sr-reverse-stock-preview">
                <h3>Stock lines (preview)</h3>
                <p class="small text-muted mb-2">On-hand must cover return qty before reversal can proceed.</p>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered mb-0">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Warehouse</th>
                                <th class="text-end">To remove</th>
                                <th class="text-end">On hand</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($stockLines as $line):
                            $needQty = (float)($line['qty'] ?? 0);
                            $onHand = (float)($line['physical_qty'] ?? 0);
                            $short = $needQty > $onHand + 0.0001;
                            ?>
                            <tr class="<?= $short ? 'table-danger' : '' ?>">
                                <td><?= htmlspecialchars($line['product_name'] ?? '', ENT_QUOTES) ?></td>
                                <td><?= htmlspecialchars($line['warehouse_name'] ?? '—', ENT_QUOTES) ?></td>
                                <td class="text-end"><?= $formatQty($needQty) ?></td>
                                <td class="text-end"><?= $formatQty($onHand) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </aside>
    </div>
    <?php endif; ?>
</div>

<script>
window.SR_REVERSE_BOOT = <?= json_encode([
    'returnCode' => $returnCode,
    'isCompleted' => $isCompleted,
    'totalFormatted' => $formatMoney($return['total_amount'] ?? 0),
    'stockLineCount' => (int)($reversal['stock_line_count'] ?? 0),
], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
</script>
<script src="<?= BASE_URL ?>assets/js/sales-return-reverse.js"></script>
<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';