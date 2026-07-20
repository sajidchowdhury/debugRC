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

$customerLabel = trim($invoice['shop_name'] ?? '') ?: trim($invoice['customer_name'] ?? '—');
$dispatcherNames = !empty($invoice['dispatchers'])
    ? implode(', ', array_map(static fn($d) => $d['name'] ?? '', $invoice['dispatchers']))
    : '—';

$statusBn = $isChallanDone ? 'চালান সম্পন্ন' : ($status === 'godown_issued' ? 'গোডাউন ইস্যু' : 'গোডাউন বাকি');
$statusClass = $isChallanDone ? 'is-done' : '';

$itemCount = 0;
foreach ($invoice['items'] ?? [] as $_) {
    $itemCount++;
}

$title = 'Godown Copy — ' . ($invoice['invoice_code'] ?? '');
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
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/godown-print.css">
</head>
<body class="invoice-print-body godown-print-body">

<div class="godown-print-toolbar no-print">
    <button type="button" class="btn btn-light" onclick="window.print()">
        <i class="fas fa-print"></i> Print godown copy
    </button>
    <button type="button" class="btn btn-outline-light" onclick="window.close()">Close</button>
</div>

<div class="invoice-print-stack">
<?php foreach ($itemPages as $pageIndex => $pageItems):
    $pageNum = $pageIndex + 1;
    $isLastPage = ($pageNum === $totalPages);
?>
    <article class="invoice-print-page" data-page="<?= $pageNum ?>">
        <span class="invoice-page-badge">পৃষ্ঠা <?= $pageNum ?> / <?= $totalPages ?></span>

        <header class="invoice-print-header">
            <?php
            $branch_id = $branchId;
            $doc_label_bn = 'গোডাউন কপি';
            $doc_label_en = 'GODOWN COPY';
            require __DIR__ . '/../sales/partials/invoice_branch_header.php';
            ?>
            <div class="godown-doc-banner">
                <h2>গোডাউন কপি / GODOWN COPY</h2>
                <p>Warehouse picking list — dispatch full invoice demand per line</p>
                <div class="godown-status-strip">
                    <span class="godown-status-pill <?= $statusClass ?>"><?= htmlspecialchars($statusBn, ENT_QUOTES) ?></span>
                    <span>ইনভয়েস: <strong><?= htmlspecialchars($invoice['invoice_code'] ?? '', ENT_QUOTES) ?></strong></span>
                    <span>মোট আইটেম: <strong><?= (int)$itemCount ?></strong></span>
                </div>
            </div>
            <div class="invoice-meta-grid">
                <div>
                    <div class="meta-label">ইনভয়েস নং</div>
                    <div class="meta-value"><?= htmlspecialchars($invoice['invoice_code'] ?? '', ENT_QUOTES) ?></div>
                </div>
                <div class="text-end">
                    <div class="meta-label">ইনভয়েস তারিখ</div>
                    <div class="meta-value"><?= $formatDate($invoice['invoice_date'] ?? '') ?></div>
                </div>
                <?php if (!empty($invoice['godown_issued_at'])): ?>
                <div>
                    <div class="meta-label">গোডাউন তারিখ</div>
                    <div class="meta-value"><?= $formatDateTime($invoice['godown_issued_at']) ?></div>
                </div>
                <?php endif; ?>
                <?php if (!empty($invoice['salesman_name'])): ?>
                <div class="text-end">
                    <div class="meta-label">বিক্রয়কর্মী</div>
                    <div class="meta-value"><?= htmlspecialchars($invoice['salesman_name'], ENT_QUOTES) ?></div>
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
                <tr>
                    <td class="label">শাখা</td>
                    <td colspan="5"><?= htmlspecialchars($invoice['branch_name'] ?? '', ENT_QUOTES) ?></td>
                </tr>
            </table>
        </section>
        <?php endif; ?>

        <div class="invoice-print-body-main">
            <table class="invoice-lines-table godown-lines-table">
                <thead>
                    <tr>
                        <th class="col-sl">ক্রম</th>
                        <th class="col-name">পণ্যের নাম</th>
                        <th class="col-wh">গুদাম</th>
                        <th class="col-ord">চাহিদা</th>
                        <th class="col-ctn">কার্টন</th>
                        <th class="col-pcs">পিস</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($pageItems as $item):
                    $globalSl++;
                    $demand = (float)($item['qty'] ?? 0);
                    $pcsPerCtn = (float)($item['pcs_per_carton'] ?? 0);
                    $ctn = (float)($item['dispatched_ctn'] ?? 0);
                    if ($ctn <= 0 && $pcsPerCtn > 0 && $demand > 0) {
                        $ctn = round($demand / $pcsPerCtn, 2);
                    }
                    $warehouse = trim($item['warehouse_name'] ?? '') ?: '—';
                    $unit = trim($item['unit'] ?? '') ?: 'Pcs';
                ?>
                    <tr>
                        <td class="col-sl"><?= $globalSl ?></td>
                        <td class="col-name"><?= htmlspecialchars($item['product_name'] ?? '', ENT_QUOTES) ?></td>
                        <td class="col-wh"><?= htmlspecialchars($warehouse, ENT_QUOTES) ?></td>
                        <td class="col-ord"><?= $formatQty($demand) ?></td>
                        <td class="col-ctn"><?= $ctn > 0 ? $formatQty($ctn) : '—' ?></td>
                        <td class="col-pcs"><?= $formatQty($demand) ?> <span class="text-muted" style="font-size:10px"><?= htmlspecialchars($unit, ENT_QUOTES) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($pageItems === []): ?>
                    <tr>
                        <td colspan="6" class="text-center text-muted">কোনো লাইন নেই</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
            <?php if (!$isLastPage): ?>
            <p class="invoice-page-continued">… পরবর্তী পৃষ্ঠায় চালিয়ে যান</p>
            <?php endif; ?>
        </div>

        <?php if ($isLastPage): ?>
        <section class="godown-dispatch-block">
            <div><strong>ডিসপ্যাচার:</strong> <?= htmlspecialchars($dispatcherNames, ENT_QUOTES) ?></div>
            <div class="mt-1 text-muted" style="font-size:10px">
                প্রতিটি লাইনে ইনভয়েস চাহিদার সমান পিস তোলা বাধ্যতামূলক। কার্টন শুধু প্যাকিং রেফারেন্স।
            </div>
        </section>

        <div class="godown-signatures">
            <div>
                <div class="sig-line">ডিসপ্যাচার স্বাক্ষর</div>
            </div>
            <div>
                <div class="sig-line">গোডাউন ম্যানেজার</div>
            </div>
            <div>
                <div class="sig-line">প্রাপক / চালান</div>
            </div>
        </div>

        <p class="godown-print-footer-note">
            Printed <?= date('d-m-Y h:i A') ?> · <?= htmlspecialchars($invoice['invoice_code'] ?? '', ENT_QUOTES) ?> · Remote Center ERP
        </p>
        <?php endif; ?>
    </article>
<?php endforeach; ?>
</div>

</body>
</html>