<?php
$return = $return ?? [];
$itemPages = $item_pages ?? [[]];
$totalPages = count($itemPages);
$branchId = (int)($branch_id ?? $return['branch_id'] ?? 1);
$status = strtolower(trim($return['status'] ?? 'pending'));
$isReversed = !empty($return['is_reversed']);
$isPending = !$isReversed && $status === 'pending';
$isCompleted = !$isReversed && $status === 'completed';

$formatDate = static function ($d) {
    if (!$d) {
        return '—';
    }
    $ts = strtotime($d);
    return $ts ? date('d-m-Y', $ts) : $d;
};

$formatDateTime = static function ($d) {
    if (!$d) {
        return '—';
    }
    $ts = strtotime($d);
    return $ts ? date('d-m-Y h:i A', $ts) : $d;
};

$formatQty = static function ($n) {
    $n = (float)$n;
    return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.') ?: '0';
};

$formatMoney = static function ($n) {
    return 'Tk ' . number_format((float)$n, 2);
};

$customerLabel = trim($return['shop_name'] ?? '') ?: trim($return['customer_name'] ?? '—');
$returnTotal = (float)($return['total_amount'] ?? 0);
$itemCount = count($return['items'] ?? []);
$returnCode = trim($return['return_code'] ?? '');

$statusPill = $isReversed
    ? ['class' => 'reversed', 'text' => 'Reversed']
    : ($isCompleted
        ? ['class' => 'completed', 'text' => 'Warehouse confirmed']
        : ['class' => 'pending', 'text' => 'Pending warehouse confirmation']);

$title = $title ?? ('Sales Return Slip — ' . $returnCode);
$globalSl = 0;
$invoiceStub = [
    'branch_name'    => $return['branch_name'] ?? '',
    'branch_address' => $return['branch_address'] ?? '',
    'branch_phone'   => $return['branch_phone'] ?? '',
    'branch_id'      => $branchId,
];
?>
<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title, ENT_QUOTES) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Bengali:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/invoice-print.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/sales-return-slip-print.css">
</head>
<body class="invoice-print-body sales-return-slip-body">

<div class="sr-slip-toolbar no-print">
    <button type="button" class="btn btn-light" onclick="window.print()">
        <i class="fas fa-print"></i> Print return slip
    </button>
    <button type="button" class="btn btn-outline-light" onclick="window.history.back()">Back</button>
</div>

<div class="invoice-print-stack">
<?php foreach ($itemPages as $pageIndex => $pageItems):
    $pageNum = $pageIndex + 1;
    $isLastPage = ($pageNum === $totalPages);
?>
    <article class="invoice-print-page sales-return-slip-page" data-page="<?= $pageNum ?>">
        <?php if ($isPending): ?>
        <div class="sr-slip-pending-watermark" aria-hidden="true">PENDING CONFIRMATION</div>
        <?php endif; ?>
        <div class="sr-slip-page-inner">
            <span class="invoice-page-badge">পৃষ্ঠা <?= $pageNum ?> / <?= $totalPages ?></span>

            <header class="invoice-print-header">
                <?php
                $branch_id = $branchId;
                $invoice = $invoiceStub;
                $doc_label_bn = 'বিক্রয় ফেরত';
                $doc_label_en = 'SALES RETURN';
                require __DIR__ . '/../sales/partials/invoice_branch_header.php';
                ?>
                <div class="sr-slip-banner">
                    <h2>বিক্রয় ফেরত স্লিপ / SALES RETURN SLIP</h2>
                    <p>Goods returned against invoice — warehouse must confirm receipt &amp; stock</p>
                    <span class="sr-slip-status-pill <?= htmlspecialchars($statusPill['class'], ENT_QUOTES) ?>">
                        <?= htmlspecialchars($statusPill['text'], ENT_QUOTES) ?>
                    </span>
                </div>
                <div class="invoice-meta-grid">
                    <div>
                        <div class="meta-label">ফেরত নং</div>
                        <div class="meta-value"><?= htmlspecialchars($returnCode, ENT_QUOTES) ?></div>
                    </div>
                    <div class="text-end">
                        <div class="meta-label">ফেরত তারিখ</div>
                        <div class="meta-value"><?= $formatDate($return['return_date'] ?? '') ?></div>
                    </div>
                    <div>
                        <div class="meta-label">ইনভয়েস নং</div>
                        <div class="meta-value"><?= htmlspecialchars($return['invoice_code'] ?? '', ENT_QUOTES) ?></div>
                    </div>
                    <div class="text-end">
                        <div class="meta-label">ইনভয়েস তারিখ</div>
                        <div class="meta-value"><?= $formatDate($return['invoice_date'] ?? '') ?></div>
                    </div>
                    <div>
                        <div class="meta-label">তৈরি করেছেন</div>
                        <div class="meta-value"><?= htmlspecialchars($return['created_by_name'] ?? '—', ENT_QUOTES) ?></div>
                    </div>
                    <div class="text-end">
                        <div class="meta-label">প্রিন্ট</div>
                        <div class="meta-value"><?= date('d-m-Y h:i A') ?></div>
                    </div>
                </div>
            </header>

            <?php if ($pageNum === 1): ?>
            <section class="invoice-customer-block">
                <table>
                    <tr>
                        <td class="label">গ্রাহক</td>
                        <td colspan="3"><strong><?= htmlspecialchars($customerLabel, ENT_QUOTES) ?></strong></td>
                        <td class="label">মোবাইল</td>
                        <td><?= htmlspecialchars($return['mobile'] ?? '—', ENT_QUOTES) ?></td>
                    </tr>
                    <?php if (!empty($return['address'])): ?>
                    <tr>
                        <td class="label">ঠিকানা</td>
                        <td colspan="5"><?= htmlspecialchars($return['address'], ENT_QUOTES) ?></td>
                    </tr>
                    <?php endif; ?>
                </table>
            </section>

            <div class="sr-slip-summary">
                <div class="sr-slip-summary-box">
                    <span class="label">Line items</span>
                    <span class="value"><?= (int)$itemCount ?></span>
                </div>
                <div class="sr-slip-summary-box highlight">
                    <span class="label">Return total</span>
                    <span class="value"><?= $formatMoney($returnTotal) ?></span>
                </div>
                <div class="sr-slip-summary-box">
                    <span class="label">Status</span>
                    <span class="value" style="font-size:12px"><?= htmlspecialchars($statusPill['text'], ENT_QUOTES) ?></span>
                </div>
            </div>
            <?php endif; ?>

            <table class="invoice-lines-table sr-slip-lines-table">
                <thead>
                    <tr>
                        <th class="col-sl">#</th>
                        <th class="col-product">পণ্য</th>
                        <th class="col-unit">ইউনিট</th>
                        <th class="col-qty text-end">ফেরত পরিমাণ</th>
                        <th class="col-rate text-end">দর</th>
                        <th class="col-amt text-end">টাকা</th>
                        <th class="col-cond">অবস্থা</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($pageItems === []): ?>
                    <tr>
                        <td colspan="7" class="text-center text-muted">No return lines</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($pageItems as $item):
                        $globalSl++;
                        $cond = trim($item['condition'] ?? 'Good');
                        $condClass = strtolower($cond) === 'damage' ? 'damaged' : '';
                    ?>
                    <tr>
                        <td><?= $globalSl ?></td>
                        <td><?= htmlspecialchars($item['product_name'] ?? '', ENT_QUOTES) ?></td>
                        <td><?= htmlspecialchars($item['unit'] ?? '—', ENT_QUOTES) ?></td>
                        <td class="text-end"><?= $formatQty($item['return_qty'] ?? 0) ?></td>
                        <td class="text-end"><?= $formatMoney($item['rate'] ?? 0) ?></td>
                        <td class="text-end"><strong><?= $formatMoney($item['amount'] ?? 0) ?></strong></td>
                        <td><span class="sr-slip-condition <?= $condClass ?>"><?= htmlspecialchars($cond, ENT_QUOTES) ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
                <?php if ($isLastPage && $pageItems !== []): ?>
                <tfoot>
                    <tr>
                        <td colspan="5" class="text-end"><strong>মোট ফেরত</strong></td>
                        <td class="text-end"><strong><?= $formatMoney($returnTotal) ?></strong></td>
                        <td></td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>

            <?php if ($isLastPage): ?>
            <?php if (!empty($return['reason'])): ?>
            <div class="sr-slip-reason">
                <strong>Return reason:</strong>
                <?= nl2br(htmlspecialchars($return['reason'], ENT_QUOTES)) ?>
            </div>
            <?php endif; ?>

            <?php
            $linkedDamages = $return['linked_damages'] ?? [];
            if ($isLastPage && !empty($linkedDamages)):
            ?>
            <div class="sr-slip-linked-damage no-print" style="margin:0.75rem 0;padding:0.5rem 0.75rem;border:1px dashed #dc2626;border-radius:6px;font-size:0.85rem;">
                <strong><i class="fas fa-heart-crack me-1"></i>Linked damage write-off</strong>
                <ul class="mb-0 mt-1 ps-3">
                    <?php foreach ($linkedDamages as $dmg): ?>
                    <li>
                        <a href="<?= BASE_URL ?>Damage/details/<?= (int)($dmg['id'] ?? 0) ?>" target="_blank" rel="noopener">
                            <?= htmlspecialchars($dmg['damage_code'] ?? '', ENT_QUOTES) ?>
                        </a>
                        — <?= htmlspecialchars($dmg['warehouse_name'] ?? '', ENT_QUOTES) ?>
                        (<?= number_format((float)($dmg['total_value'] ?? 0), 2) ?>)
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <div class="sr-slip-meta-row">
                <?php if ($isCompleted && !empty($return['confirmed_at'])): ?>
                <span><i class="fas fa-check-circle"></i> Confirmed: <?= $formatDateTime($return['confirmed_at']) ?></span>
                <?php if (!empty($return['confirmed_by_name'])): ?>
                <span>By: <?= htmlspecialchars($return['confirmed_by_name'], ENT_QUOTES) ?></span>
                <?php endif; ?>
                <?php endif; ?>
                <?php if ($isReversed): ?>
                <span><i class="fas fa-undo"></i> Reversed</span>
                <?php endif; ?>
            </div>

            <div class="sr-slip-signatures">
                <div>
                    <div class="sig-line">Sales / Creator</div>
                    <?= htmlspecialchars($return['created_by_name'] ?? '', ENT_QUOTES) ?>
                </div>
                <div>
                    <div class="sig-line">Warehouse received</div>
                    <?= $isCompleted ? htmlspecialchars($return['confirmed_by_name'] ?? '—', ENT_QUOTES) : '—' ?>
                </div>
                <div>
                    <div class="sig-line">Customer / Carrier</div>
                    —
                </div>
            </div>

            <p class="sr-slip-footer-note">
                <?php if ($isPending): ?>
                This slip is provisional until warehouse confirms return and updates stock.
                <?php else: ?>
                Official sales return record — attach to invoice file.
                <?php endif; ?>
            </p>
            <?php endif; ?>

            <footer class="invoice-print-footer">
                <span>Remote Center ERP · Sales Return</span>
                <span><?= htmlspecialchars($returnCode, ENT_QUOTES) ?> · <?= date('d-m-Y') ?></span>
            </footer>
        </div>
    </article>
<?php endforeach; ?>
</div>

</body>
</html>