<?php
$title = 'Invoice #' . ($invoice['invoice_code'] ?? '');
$due = $due ?? [];
$itemPages = $item_pages ?? [[]];
$totalPages = count($itemPages);
$isReversed = (int)($invoice['is_reversed'] ?? 0) === 1;
$grandTotal = (float)($grand_total ?? 0);
$transport = (float)($transport ?? 0);
$discount = (float)($discount ?? 0);
$subTotal = (float)($subtotal ?? 0);

$formatMoney = static function ($n) {
    return number_format((float)$n, 2);
};

$formatDate = static function ($d) {
    if (!$d) {
        return '';
    }
    $ts = strtotime($d);
    return $ts ? date('d-m-Y', $ts) : $d;
};

$customerLabel = trim($invoice['shop_name'] ?? '') ?: trim($invoice['customer_name'] ?? '');
$globalSl = 0;
?>
<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title, ENT_QUOTES) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Bengali:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/invoice-print.css">
</head>
<body class="invoice-print-body">

<div class="invoice-print-toolbar no-print">
    <button type="button" class="btn btn-light" onclick="window.print()">
        <i class="fas fa-print"></i> Print invoice
    </button>
    <button type="button" class="btn btn-outline-light" onclick="window.close()">Close</button>
</div>

<div class="invoice-print-stack">
<?php foreach ($itemPages as $pageIndex => $pageItems):
    $pageNum = $pageIndex + 1;
    $isLastPage = ($pageNum === $totalPages);
?>
    <article class="invoice-print-page" data-page="<?= $pageNum ?>">
        <?php if ($isReversed): ?>
        <div class="invoice-watermark"><span>INVOICE DELETED</span></div>
        <?php endif; ?>

        <span class="invoice-page-badge">পৃষ্ঠা <?= $pageNum ?> / <?= $totalPages ?></span>

        <header class="invoice-print-header">
            <?php
            $branch_id = (int)($branch_id ?? $invoice['branch_id'] ?? 1);
            require __DIR__ . '/partials/invoice_branch_header.php';
            ?>
            <div class="invoice-meta-grid">
                <div>
                    <div class="meta-label">ইনভয়েস নং</div>
                    <div class="meta-value"><?= htmlspecialchars($invoice['invoice_code'] ?? '', ENT_QUOTES) ?></div>
                </div>
                <div class="text-end">
                    <div class="meta-label">তারিখ</div>
                    <div class="meta-value"><?= $formatDate($invoice['invoice_date'] ?? '') ?></div>
                </div>
                <?php if (!empty($invoice['salesman_name'])): ?>
                <div>
                    <div class="meta-label">বিক্রয়কর্মী</div>
                    <div class="meta-value"><?= htmlspecialchars($invoice['salesman_name'], ENT_QUOTES) ?></div>
                </div>
                <?php endif; ?>
                <?php if (!empty($invoice['sales_person_name'])): ?>
                <div class="text-end">
                    <div class="meta-label">সেলস পার্সন</div>
                    <div class="meta-value"><?= htmlspecialchars($invoice['sales_person_name'], ENT_QUOTES) ?></div>
                </div>
                <?php endif; ?>
            </div>
        </header>

        <?php if ($pageNum === 1): ?>
        <section class="invoice-customer-block">
            <table>
                <tr>
                    <td class="label">নাম</td>
                    <td colspan="3"><strong><?= htmlspecialchars($customerLabel, ENT_QUOTES) ?></strong></td>
                    <td class="label">মোবাইল</td>
                    <td><?= htmlspecialchars($invoice['mobile'] ?? '', ENT_QUOTES) ?></td>
                </tr>
                <tr>
                    <td class="label">ঠিকানা</td>
                    <td colspan="5"><?= htmlspecialchars($invoice['address'] ?? '', ENT_QUOTES) ?></td>
                </tr>
            </table>
        </section>
        <?php endif; ?>

        <div class="invoice-print-body-main">
            <table class="invoice-lines-table">
                <thead>
                    <tr>
                        <th class="col-sl">ক্রম</th>
                        <th class="col-name">পণ্যের নাম</th>
                        <th class="col-qty">পরিমাণ</th>
                        <th class="col-unit">একক</th>
                        <th class="col-rate">দর</th>
                        <th class="col-amt">টাকা</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($pageItems) && $isLastPage): ?>
                    <tr>
                        <td colspan="6" class="text-center text-muted">কোনো পণ্য নেই</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($pageItems as $item):
                        $globalSl++;
                        $lineTotal = (float)($item['qty'] ?? 0) * (float)($item['rate'] ?? 0);
                        $unit = $item['unit'] ?? $item['unit_name'] ?? 'Pcs';
                    ?>
                    <tr>
                        <td class="col-sl"><?= $globalSl ?></td>
                        <td class="col-name"><?= htmlspecialchars($item['product_name'] ?? '', ENT_QUOTES) ?></td>
                        <td class="col-qty"><?= $formatMoney($item['qty'] ?? 0) ?></td>
                        <td class="col-unit"><?= htmlspecialchars($unit, ENT_QUOTES) ?></td>
                        <td class="col-rate"><?= $formatMoney($item['rate'] ?? 0) ?></td>
                        <td class="col-amt"><?= $formatMoney($lineTotal) ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>

            <?php if (!$isLastPage): ?>
            <p class="invoice-page-continued">— পরবর্তী পৃষ্ঠায় চালিয়ে যান —</p>
            <?php endif; ?>
        </div>

        <?php if ($isLastPage): ?>
        <section class="invoice-summary-wrap">
            <table class="invoice-summary-table">
                <tr>
                    <th>মোট (পণ্য)</th>
                    <td><?= $formatMoney($subTotal) ?></td>
                </tr>
                <tr>
                    <th>ডিসকাউন্ট</th>
                    <td><?= $formatMoney($discount) ?></td>
                </tr>
                <tr>
                    <th>ট্রান্সপোর্ট</th>
                    <td><?= $formatMoney($transport) ?></td>
                </tr>
                <tr class="grand-total">
                    <th>সর্বমোট</th>
                    <td><?= $formatMoney($grandTotal) ?></td>
                </tr>
                <tr class="due-pay">
                    <th>এই চালানের পেমেন্ট</th>
                    <td><?= $formatMoney($due['this_invoice_payment'] ?? 0) ?></td>
                </tr>
                <tr class="due-net">
                    <th>এই চালানের বকেয়া</th>
                    <td><?= $formatMoney($due['this_invoice_net'] ?? 0) ?></td>
                </tr>
                <tr class="due-prev">
                    <th>পূর্বের বকেয়া</th>
                    <td><?= $formatMoney($due['previous_due'] ?? 0) ?></td>
                </tr>
                <tr class="due-total">
                    <th>মোট বকেয়া (এ পর্যন্ত)</th>
                    <td><?= $formatMoney($due['cumulative_due'] ?? 0) ?></td>
                </tr>
            </table>
            <p class="text-end mt-1 mb-0" style="font-size:10px;color:#64748b;">
                একই দিনে একাধিক চালানে পূর্বের বকেয়া আগের চালান অনুযায়ী হিসাব করা হয়েছে।
            </p>
        </section>
        <?php endif; ?>

        <footer class="invoice-print-footer">
            <p class="mb-1"><strong>ধন্যবাদ — আবার আসবেন!</strong></p>
            <div class="invoice-signatures">
                <span>ক্রেতার স্বাক্ষর _______________________</span>
                <span>বিক্রেতার স্বাক্ষর _______________________</span>
            </div>
            <small class="d-block mt-2 opacity-75">Remote Center ERP · <?= htmlspecialchars($invoice['branch_name'] ?? '', ENT_QUOTES) ?></small>
        </footer>
    </article>
<?php endforeach; ?>
</div>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</body>
</html>