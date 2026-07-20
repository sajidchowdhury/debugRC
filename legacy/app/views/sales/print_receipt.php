<?php
$invoice = $invoice ?? [];
$payments = $payments ?? [];
$paidTotal = (float)($paid_total ?? 0);
$balanceDue = (float)($balance_due ?? 0);
$isFullyPaid = !empty($is_fully_paid);
$branchId = (int)($branch_id ?? $invoice['branch_id'] ?? 1);
$highlightPaymentId = (int)($highlight_payment_id ?? 0);

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

$formatMoney = static function ($n) {
    return 'Tk ' . number_format((float)$n, 2);
};

$formatPaymentMode = static function (array $row) {
    $mode = strtolower(trim($row['payment_mode'] ?? 'cash'));
    if ($mode === 'cash' || $mode === '') {
        return ['label' => 'Cash', 'class' => 'cash', 'detail' => ''];
    }
    $bank = trim($row['bank_name'] ?? '');
    $acct = trim($row['account_number'] ?? '');
    $detail = $bank;
    if ($acct !== '') {
        $detail .= ($detail !== '' ? ' · ' : '') . $acct;
    }
    return ['label' => 'Bank', 'class' => 'bank', 'detail' => $detail ?: 'Bank transfer'];
};

$customerLabel = trim($invoice['shop_name'] ?? '') ?: trim($invoice['customer_name'] ?? '—');
$invoiceTotal = (float)($invoice['total_amount'] ?? 0);
$transport = (float)($invoice['transport_cost'] ?? 0);
$discount = (float)($invoice['discount'] ?? 0);

$statusPill = $paidTotal <= 0
    ? ['class' => 'none', 'text' => 'No payment recorded']
    : ($isFullyPaid
        ? ['class' => 'paid', 'text' => 'Fully paid']
        : ['class' => 'partial', 'text' => 'Partially paid']);

$title = $title ?? ('Payment Receipt — ' . ($invoice['invoice_code'] ?? ''));
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
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/payment-receipt-print.css">
</head>
<body class="invoice-print-body payment-receipt-print-body">

<div class="payment-receipt-toolbar no-print">
    <button type="button" class="btn btn-light" onclick="window.print()">
        <i class="fas fa-print"></i> Print receipt
    </button>
    <button type="button" class="btn btn-outline-light" onclick="window.close()">Close</button>
</div>

<div class="invoice-print-stack">
    <article class="invoice-print-page payment-receipt-page">
        <header class="invoice-print-header">
            <?php
            $branch_id = $branchId;
            $doc_label_bn = 'পেমেন্ট রসিদ';
            $doc_label_en = 'PAYMENT RECEIPT';
            require __DIR__ . '/partials/invoice_branch_header.php';
            ?>
            <div class="payment-receipt-banner">
                <h2>পেমেন্ট রসিদ / PAYMENT RECEIPT</h2>
                <p>Official acknowledgement of payment received against invoice</p>
                <span class="pr-status-pill <?= htmlspecialchars($statusPill['class'], ENT_QUOTES) ?>">
                    <?= htmlspecialchars($statusPill['text'], ENT_QUOTES) ?>
                </span>
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
                    <div class="meta-label">রসিদ প্রিন্ট</div>
                    <div class="meta-value"><?= date('d-m-Y h:i A') ?></div>
                </div>
            </div>
        </header>

        <section class="invoice-customer-block">
            <table>
                <tr>
                    <td class="label">গ্রাহক</td>
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

        <div class="pr-summary-grid">
            <div class="pr-summary-card">
                <span class="pr-label">Invoice total</span>
                <span class="pr-value"><?= $formatMoney($invoiceTotal) ?></span>
            </div>
            <div class="pr-summary-card is-paid">
                <span class="pr-label">Total received</span>
                <span class="pr-value"><?= $formatMoney($paidTotal) ?></span>
            </div>
            <div class="pr-summary-card <?= $balanceDue > 0.01 ? 'is-due' : 'is-paid' ?>">
                <span class="pr-label">Balance due</span>
                <span class="pr-value"><?= $formatMoney($balanceDue) ?></span>
            </div>
        </div>

        <?php if ($transport > 0 || $discount > 0): ?>
        <p class="text-muted mb-2" style="font-size:11px">
            <?php if ($transport > 0): ?>Transport: <?= $formatMoney($transport) ?><?php endif; ?>
            <?php if ($discount > 0): ?> · Discount: <?= $formatMoney($discount) ?><?php endif; ?>
        </p>
        <?php endif; ?>

        <div class="invoice-print-body-main">
            <h3 style="font-size:13px;font-weight:700;margin:0 0 8px;color:#047857">
                <i class="fas fa-list"></i> Payment history (this invoice)
            </h3>

            <?php if (empty($payments)): ?>
            <div class="pr-empty-payments">
                <i class="fas fa-info-circle"></i>
                No payments have been recorded for this invoice yet.
            </div>
            <?php else: ?>
            <table class="invoice-lines-table pr-payments-table">
                <thead>
                    <tr>
                        <th class="col-sl">#</th>
                        <th>Payment no.</th>
                        <th>Date</th>
                        <th class="col-mode">Method</th>
                        <th class="text-end">Allocated</th>
                        <th>Received by</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($payments as $i => $pay):
                    $mode = $formatPaymentMode($pay);
                    $isHighlight = $highlightPaymentId > 0 && (int)$pay['payment_id'] === $highlightPaymentId;
                ?>
                    <tr class="<?= $isHighlight ? 'is-highlight' : '' ?>">
                        <td class="col-sl"><?= $i + 1 ?></td>
                        <td>
                            <strong><?= htmlspecialchars($pay['payment_code'] ?? '—', ENT_QUOTES) ?></strong>
                            <?php if (!empty($pay['reference_no'])): ?>
                            <br><small class="text-muted">Ref: <?= htmlspecialchars($pay['reference_no'], ENT_QUOTES) ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?= $formatDate($pay['payment_date'] ?? '') ?></td>
                        <td class="col-mode">
                            <span class="pr-method-badge <?= $mode['class'] ?>"><?= htmlspecialchars($mode['label'], ENT_QUOTES) ?></span>
                            <?php if ($mode['detail'] !== ''): ?>
                            <br><small><?= htmlspecialchars($mode['detail'], ENT_QUOTES) ?></small>
                            <?php endif; ?>
                        </td>
                        <td class="text-end"><strong><?= $formatMoney($pay['allocated_amount'] ?? 0) ?></strong></td>
                        <td><?= htmlspecialchars($pay['received_by_name'] ?? '—', ENT_QUOTES) ?></td>
                    </tr>
                    <?php if (!empty($pay['remarks'])): ?>
                    <tr>
                        <td></td>
                        <td colspan="5" style="font-size:10px;color:#64748b">
                            Note: <?= htmlspecialchars($pay['remarks'], ENT_QUOTES) ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <th colspan="4" class="text-end">Total allocated to this invoice</th>
                        <th class="text-end"><?= $formatMoney($paidTotal) ?></th>
                        <th></th>
                    </tr>
                </tfoot>
            </table>
            <?php endif; ?>
        </div>

        <div class="pr-thank-you">
            <strong>ধন্যবাদ — Thank you for your payment</strong>
            <p class="mb-0 mt-1" style="font-size:11px;color:#475569">
                This is a computer-generated receipt. Please retain for your records.
                <?php if ($balanceDue > 0.01): ?>
                <br>Outstanding balance on this invoice: <strong><?= $formatMoney($balanceDue) ?></strong>
                <?php endif; ?>
            </p>
        </div>

        <div class="pr-signatures">
            <div>
                <div class="sig-line">Customer / payer signature</div>
            </div>
            <div>
                <div class="sig-line">Authorized signature</div>
            </div>
        </div>

        <p class="pr-footer-note">
            <?= htmlspecialchars($invoice['invoice_code'] ?? '', ENT_QUOTES) ?>
            · <?= htmlspecialchars(APP_NAME, ENT_QUOTES) ?>
            · Printed <?= date('d-m-Y h:i A') ?>
        </p>
    </article>
</div>

</body>
</html>