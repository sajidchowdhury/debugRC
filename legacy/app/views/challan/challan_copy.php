<?php
$invoice = $invoice ?? [];
$itemPages = $item_pages ?? [[]];
$totalPages = count($itemPages);
$branchId = (int)($branch_id ?? $invoice['branch_id'] ?? 1);
$status = $invoice['status'] ?? 'draft';
$isChallanDone = $status === 'challan_completed';

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

$customerLabel = trim($invoice['shop_name'] ?? '') ?: trim($invoice['customer_name'] ?? '—');
$dispatcherNames = !empty($invoice['dispatchers'])
    ? implode(', ', array_map(static fn($d) => $d['name'] ?? '', $invoice['dispatchers']))
    : '—';

$challanCode = trim($invoice['challan_code'] ?? '');
$transport = (float)($invoice['transport_cost'] ?? 0);
$grandTotal = (float)($invoice['total_amount'] ?? 0);
$itemCount = count($invoice['items'] ?? []);

$title = 'Challan Copy' . ($challanCode !== '' ? ' — ' . $challanCode : '') . ' · ' . ($invoice['invoice_code'] ?? '');
$globalSl = 0;
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
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/challan-print.css">
</head>
<body class="invoice-print-body challan-print-body">

<div class="challan-print-toolbar no-print">
    <button type="button" class="btn btn-light" onclick="window.print()">
        <i class="fas fa-print"></i> Print challan
    </button>
    <button type="button" class="btn btn-outline-light" onclick="window.close()">Close</button>
</div>

<div class="invoice-print-stack">
<?php foreach ($itemPages as $pageIndex => $pageItems):
    $pageNum = $pageIndex + 1;
    $isLastPage = ($pageNum === $totalPages);
?>
    <article class="invoice-print-page" data-page="<?= $pageNum ?>">
        <?php if (!$isChallanDone): ?>
        <div class="challan-pending-watermark" aria-hidden="true">NOT FINALIZED</div>
        <?php endif; ?>
        <div class="challan-print-page-inner">
            <span class="invoice-page-badge">পৃষ্ঠা <?= $pageNum ?> / <?= $totalPages ?></span>

            <header class="invoice-print-header">
                <?php
                $branch_id = $branchId;
                $doc_label_bn = 'ডেলিভারি চালান';
                $doc_label_en = 'DELIVERY CHALLAN';
                require __DIR__ . '/../sales/partials/invoice_branch_header.php';
                ?>
                <div class="challan-doc-banner <?= $isChallanDone ? '' : 'is-pending' ?>">
                    <h2>ডেলিভারি চালান / DELIVERY CHALLAN</h2>
                    <p>Goods delivered to customer — keep for transport &amp; receiving</p>
                    <?php if (!$isChallanDone): ?>
                    <p class="text-warning mb-0 mt-1" style="font-size:10px"><strong>Preview only</strong> — finalize challan in warehouse to confirm dispatch</p>
                    <?php endif; ?>
                </div>
                <div class="invoice-meta-grid">
                    <div>
                        <div class="meta-label">চালান নং</div>
                        <div class="meta-value"><?= $challanCode !== '' ? htmlspecialchars($challanCode, ENT_QUOTES) : '—' ?></div>
                    </div>
                    <div class="text-end">
                        <div class="meta-label">চালান তারিখ</div>
                        <div class="meta-value"><?= $formatDate($invoice['challan_date'] ?? $invoice['challan_completed_at'] ?? '') ?></div>
                    </div>
                    <div>
                        <div class="meta-label">ইনভয়েস নং</div>
                        <div class="meta-value"><?= htmlspecialchars($invoice['invoice_code'] ?? '', ENT_QUOTES) ?></div>
                    </div>
                    <div class="text-end">
                        <div class="meta-label">ইনভয়েস তারিখ</div>
                        <div class="meta-value"><?= $formatDate($invoice['invoice_date'] ?? '') ?></div>
                    </div>
                    <?php if (!empty($invoice['salesman_name'])): ?>
                    <div>
                        <div class="meta-label">বিক্রয়কর্মী</div>
                        <div class="meta-value"><?= htmlspecialchars($invoice['salesman_name'], ENT_QUOTES) ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($invoice['challan_completed_at'])): ?>
                    <div class="text-end">
                        <div class="meta-label">সম্পন্ন</div>
                        <div class="meta-value"><?= $formatDateTime($invoice['challan_completed_at']) ?></div>
                    </div>
                    <?php endif; ?>
                </div>
            </header>

            <?php if ($pageNum === 1): ?>
            <section class="invoice-customer-block">
                <table>
                    <tr>
                        <td class="label">প্রাপক</td>
                        <td colspan="3"><strong><?= htmlspecialchars($customerLabel, ENT_QUOTES) ?></strong></td>
                        <td class="label">মোবাইল</td>
                        <td><?= htmlspecialchars($invoice['mobile'] ?? '', ENT_QUOTES) ?></td>
                    </tr>
                    <tr>
                        <td class="label">ডেলিভারি ঠিকানা</td>
                        <td colspan="5"><?= htmlspecialchars($invoice['address'] ?? '', ENT_QUOTES) ?></td>
                    </tr>
                    <tr>
                        <td class="label">শাখা</td>
                        <td colspan="3"><?= htmlspecialchars($invoice['branch_name'] ?? '', ENT_QUOTES) ?></td>
                        <td class="label">ডিসপ্যাচার</td>
                        <td><?= htmlspecialchars($dispatcherNames, ENT_QUOTES) ?></td>
                    </tr>
                </table>
            </section>
            <?php endif; ?>

            <div class="invoice-print-body-main">
                <table class="invoice-lines-table challan-lines-table">
                    <thead>
                        <tr>
                            <th class="col-sl">ক্রম</th>
                            <th class="col-name">পণ্যের নাম</th>
                            <th class="col-qty">পরিমাণ</th>
                            <th class="col-unit">একক</th>
                            <th class="col-ctn">কার্টন</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($pageItems as $item):
                        $globalSl++;
                        $demand = (float)($item['qty'] ?? 0);
                        $dispatched = (float)($item['dispatched_qty'] ?? 0);
                        $qty = $dispatched > 0 ? $dispatched : $demand;
                        $pcsPerCtn = (float)($item['pcs_per_carton'] ?? 0);
                        $ctn = (float)($item['dispatched_ctn'] ?? 0);
                        if ($ctn <= 0 && $pcsPerCtn > 0 && $qty > 0) {
                            $ctn = round($qty / $pcsPerCtn, 2);
                        }
                        $unit = trim($item['unit'] ?? '') ?: 'Pcs';
                    ?>
                        <tr>
                            <td class="col-sl"><?= $globalSl ?></td>
                            <td class="col-name"><?= htmlspecialchars($item['product_name'] ?? '', ENT_QUOTES) ?></td>
                            <td class="col-qty"><strong><?= $formatQty($qty) ?></strong></td>
                            <td class="col-unit"><?= htmlspecialchars($unit, ENT_QUOTES) ?></td>
                            <td class="col-ctn"><?= $ctn > 0 ? $formatQty($ctn) : '—' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($pageItems === []): ?>
                        <tr>
                            <td colspan="5" class="text-center text-muted">কোনো লাইন নেই</td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
                <?php if (!$isLastPage): ?>
                <p class="invoice-page-continued">… পরবর্তী পৃষ্ঠায় চালিয়ে যান</p>
                <?php endif; ?>
            </div>

            <?php if ($isLastPage): ?>
            <div class="challan-summary-strip">
                <div class="challan-summary-box">
                    <span class="label">মোট আইটেম</span>
                    <span class="value"><?= (int)$itemCount ?> line(s)</span>
                </div>
                <div class="challan-summary-box">
                    <span class="label">পরিবহন</span>
                    <span class="value"><?= $formatMoney($transport) ?></span>
                </div>
                <div class="challan-summary-box">
                    <span class="label">ইনভয়েস মোট</span>
                    <span class="value"><?= $formatMoney($grandTotal) ?></span>
                </div>
            </div>

            <div class="challan-delivery-note">
                উপরোক্ত পণ্য যথাযথ অবস্থায় গ্রহণ করা হলে প্রাপকের স্বাক্ষর করুন।
                কোনো ত্রুটি থাকলে তৎক্ষণাৎ জানান।
            </div>

            <div class="challan-signatures">
                <div>
                    <div class="sig-line">প্রাপকের স্বাক্ষর ও সীল</div>
                </div>
                <div>
                    <div class="sig-line">অনুমোদিত / বিক্রয় প্রতিনিধি</div>
                </div>
            </div>

            <p class="challan-print-footer-note">
                Printed <?= date('d-m-Y h:i A') ?>
                · <?= htmlspecialchars($challanCode ?: $invoice['invoice_code'] ?? '', ENT_QUOTES) ?>
                · Remote Center ERP
            </p>
            <?php endif; ?>
        </div>
    </article>
<?php endforeach; ?>
</div>

</body>
</html>