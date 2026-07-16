<?php
$invoice = $invoice ?? [];
$itemPages = $item_pages ?? [[]];
$totalPages = count($itemPages);
$branchId = (int)($branch_id ?? $invoice['branch_id'] ?? 1);

$formatDate = static function ($d) {
    if (!$d) {
        return '—';
    }
    $ts = strtotime($d);
    return $ts ? date('d-m-Y', $ts) : $d;
};

$formatQty = static function ($n) {
    $n = (float)$n;
    return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.') ?: '0';
};

$customerLabel = trim($invoice['shop_name'] ?? '') ?: trim($invoice['customer_name'] ?? '—');
$dispatcherNames = !empty($invoice['dispatchers'])
    ? implode(', ', array_map(static fn($d) => $d['name'] ?? '', $invoice['dispatchers']))
    : '';

$itemCount = count($invoice['items'] ?? []);
$title = 'Blank Godown Copy — ' . ($invoice['invoice_code'] ?? '');
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
<body class="invoice-print-body godown-print-body is-blank">

<div class="godown-print-toolbar no-print">
    <button type="button" class="btn btn-light" onclick="window.print()">
        <i class="fas fa-print"></i> Print blank godown
    </button>
    <button type="button" class="btn btn-outline-light" onclick="window.close()">Close</button>
</div>

<div class="invoice-print-stack">
<?php foreach ($itemPages as $pageIndex => $pageItems):
    $pageNum = $pageIndex + 1;
    $isLastPage = ($pageNum === $totalPages);
?>
    <article class="invoice-print-page" data-page="<?= $pageNum ?>">
        <div class="godown-blank-watermark" aria-hidden="true">BLANK GODOWN</div>
        <div class="godown-print-page-inner">
            <span class="invoice-page-badge">পৃষ্ঠা <?= $pageNum ?> / <?= $totalPages ?></span>

            <header class="invoice-print-header">
                <?php
                $branch_id = $branchId;
                $doc_label_bn = 'খালি গোডাউন';
                $doc_label_en = 'BLANK GODOWN';
                require __DIR__ . '/../sales/partials/invoice_branch_header.php';
                ?>
                <div class="godown-doc-banner is-blank">
                    <h2>খালি গোডাউন কপি / BLANK GODOWN COPY</h2>
                    <p>Handwrite warehouse, carton &amp; pick confirmation — full invoice demand required</p>
                    <div class="godown-status-strip">
                        <span class="godown-status-pill">ম্যানুয়াল পিকিং শিট</span>
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
                    <?php if (!empty($invoice['salesman_name'])): ?>
                    <div>
                        <div class="meta-label">বিক্রয়কর্মী</div>
                        <div class="meta-value"><?= htmlspecialchars($invoice['salesman_name'], ENT_QUOTES) ?></div>
                    </div>
                    <?php endif; ?>
                    <div class="text-end">
                        <div class="meta-label">প্রিন্ট</div>
                        <div class="meta-value"><?= date('d-m-Y') ?></div>
                    </div>
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
                            <th class="col-ord">চাহিদা</th>
                            <th class="col-wh">গুদাম (লিখুন)</th>
                            <th class="col-ctn">কার্টন</th>
                            <th class="col-pcs">পিস তোলা</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($pageItems as $item):
                        $globalSl++;
                        $demand = (float)($item['qty'] ?? 0);
                        $pcsPerCtn = (float)($item['pcs_per_carton'] ?? 0);
                        $suggestedCtn = ($pcsPerCtn > 0 && $demand > 0)
                            ? round($demand / $pcsPerCtn, 2)
                            : 0;
                        $whHint = trim($item['warehouse_name'] ?? '');
                        $unit = trim($item['unit'] ?? '') ?: 'Pcs';
                    ?>
                        <tr>
                            <td class="col-sl"><?= $globalSl ?></td>
                            <td class="col-name"><?= htmlspecialchars($item['product_name'] ?? '', ENT_QUOTES) ?></td>
                            <td class="col-ord"><strong><?= $formatQty($demand) ?></strong></td>
                            <td class="col-wh">
                                <?php if ($whHint !== ''): ?>
                                    <span class="godown-write-cell has-hint"><?= htmlspecialchars($whHint, ENT_QUOTES) ?></span>
                                <?php else: ?>
                                    <span class="godown-write-cell">&nbsp;</span>
                                <?php endif; ?>
                            </td>
                            <td class="col-ctn">
                                <?php if ($suggestedCtn > 0): ?>
                                    <span class="godown-write-cell has-hint" title="Suggested CTN"><?= $formatQty($suggestedCtn) ?></span>
                                <?php else: ?>
                                    <span class="godown-write-cell">&nbsp;</span>
                                <?php endif; ?>
                            </td>
                            <td class="col-pcs">
                                <span class="godown-write-cell has-hint"><?= $formatQty($demand) ?> <?= htmlspecialchars($unit, ENT_QUOTES) ?></span>
                            </td>
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
                <div><strong>ডিসপ্যাচার (সিস্টেম):</strong>
                    <?= $dispatcherNames !== '' ? htmlspecialchars($dispatcherNames, ENT_QUOTES) : '— লিখুন নিচে —' ?>
                </div>
                <div class="godown-dispatch-write">
                    <div class="write-row">
                        <label>ডিসপ্যাচার নাম</label>
                        <div class="write-line"></div>
                    </div>
                    <div class="write-row">
                        <label>তারিখ / সময়</label>
                        <div class="write-line"></div>
                    </div>
                </div>
                <div class="mt-2 text-muted" style="font-size:10px">
                    নোট: পিস তোলা অবশ্যই চাহিদার সমান হতে হবে। কম পিস দেওয়া যাবে না। কার্টন শুধু প্যাকিং।
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
                    <div class="sig-line">যাচাইকারী</div>
                </div>
            </div>

            <p class="godown-print-footer-note">
                Blank godown · <?= date('d-m-Y h:i A') ?> · <?= htmlspecialchars($invoice['invoice_code'] ?? '', ENT_QUOTES) ?>
            </p>
            <?php endif; ?>
        </div>
    </article>
<?php endforeach; ?>
Signature:</strong><br>_________________________</p>
        </div>
    </div>
</div>

</body>
</html>