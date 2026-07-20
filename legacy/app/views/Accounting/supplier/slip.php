<?php
$transaction = $transaction ?? [];
$t = $transaction;
$id = (int)($t['id'] ?? 0);
$isReversed = !empty($t['is_reversed']);

$formatDate = static function ($d) {
    if (!$d) {
        return '—';
    }
    $ts = strtotime($d);
    return $ts ? date('d-m-Y', $ts) : $d;
};

$formatMoney = static function ($n) {
    return 'Tk ' . number_format((float)$n, 2);
};

$type = (string)($t['transaction_type'] ?? 'payment');
$typeLabel = match ($type) {
    'payment' => 'Payment to Supplier',
    'advance' => 'Advance Payment',
    'receive' => 'Receive from Supplier',
    default     => ucfirst($type),
};
$typeCls = preg_replace('/[^a-z_]/', '', strtolower($type)) ?: 'payment';

$amountLabel = match ($type) {
    'payment' => 'Amount paid / প্রদত্ত টাকা',
    'advance' => 'Advance paid / অগ্রিম পরিশোধ',
    'receive' => 'Amount received / প্রাপ্ত টাকা',
    default     => 'Transaction amount',
};

$thankYouLine = match ($type) {
    'payment'   => 'Payment recorded — Thank you',
    'advance'   => 'Advance payment acknowledged',
    'receive'   => 'Receipt from supplier recorded',
    default     => 'Transaction recorded — লেনদেন রেকর্ড হয়েছে',
};

$mode = strtolower(trim((string)($t['payment_mode'] ?? 'cash')));
$modeBadgeClass = ($mode === 'bank') ? 'bank' : 'cash';
$modeLabel = strtoupper($mode);

$branchId = (int)($t['branch_id'] ?? $_SESSION['branch_id'] ?? 1);

$invoiceStub = [
    'branch_id'      => $branchId,
    'branch_name'    => $t['branch_name'] ?? ($_SESSION['branch_name'] ?? 'Remote Center'),
    'branch_address' => trim((string)($t['branch_address'] ?? '')),
    'branch_phone'   => trim((string)($t['branch_phone'] ?? '')),
];

$title = $title ?? ('Supplier Payment Slip — ' . ($t['payment_code'] ?? ''));
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
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/entity-voucher-slip-print.css">
</head>
<body class="invoice-print-body entity-voucher-slip-body customer-payment-slip-body supp-payment-slip-body">

<div class="customer-payment-slip-toolbar no-print">
    <button type="button" class="btn btn-light" onclick="window.print()">
        <i class="fas fa-print"></i> Print slip
    </button>
    <a href="<?= BASE_URL ?>SupplierTransaction/details/<?= $id ?>" class="btn btn-outline-light">
        <i class="fas fa-eye"></i> Details
    </a>
    <button type="button" class="btn btn-outline-light" onclick="window.close()">Close</button>
</div>

<div class="invoice-print-stack">
    <article class="invoice-print-page customer-payment-slip-page">
        <div class="cps-slip-page-inner">
            <?php if ($isReversed): ?>
            <div class="cps-reversed-watermark" aria-hidden="true">REVERSED</div>
            <?php endif; ?>

            <header class="invoice-print-header">
                <?php
                $branch_id = $branchId;
                $invoice = $invoiceStub;
                $doc_label_bn = 'সরবরাহকারী পেমেন্ট রসিদ';
                $doc_label_en = 'SUPPLIER PAYMENT';
                require __DIR__ . '/../../sales/partials/invoice_branch_header.php';
                ?>
                <div class="cps-banner">
                    <h2>সরবরাহকারী পেমেন্ট রসিদ / SUPPLIER PAYMENT SLIP</h2>
                    <p>Official acknowledgement of supplier account transaction</p>
                    <span class="cps-voucher-code"><?= htmlspecialchars($t['payment_code'] ?? '', ENT_QUOTES) ?></span>
                    <span class="cps-status-pill <?= $isReversed ? 'reversed' : 'active' ?>">
                        <?= $isReversed ? 'Reversed' : 'Active' ?>
                    </span>
                </div>
                <div class="invoice-meta-grid">
                    <div>
                        <div class="meta-label">তারিখ / Date</div>
                        <div class="meta-value"><?= $formatDate($t['payment_date'] ?? '') ?></div>
                    </div>
                    <div class="text-end">
                        <div class="meta-label">শাখা / Branch</div>
                        <div class="meta-value"><?= htmlspecialchars($invoiceStub['branch_name'], ENT_QUOTES) ?></div>
                    </div>
                </div>
            </header>

            <div class="invoice-print-body-main">
                <?php if ($isReversed): ?>
                <div class="cps-reversed-note">
                    <strong><i class="fas fa-triangle-exclamation"></i> This payment was reversed.</strong>
                    <?php if (!empty($t['reverse_reason'])): ?>
                    <div class="mt-1">Reason: <?= htmlspecialchars($t['reverse_reason'], ENT_QUOTES) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($t['reversed_at'])): ?>
                    <div class="text-muted">
                        <?= date('d-m-Y h:i A', strtotime($t['reversed_at'])) ?>
                        <?php if (!empty($t['reversed_by_name'])): ?>
                        · <?= htmlspecialchars($t['reversed_by_name'], ENT_QUOTES) ?>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <div class="cps-amount-hero <?= htmlspecialchars($typeCls, ENT_QUOTES) ?>">
                    <div class="cps-label"><?= htmlspecialchars($amountLabel, ENT_QUOTES) ?></div>
                    <div class="cps-amount"><?= $formatMoney($t['amount'] ?? 0) ?></div>
                    <span class="cps-type-badge <?= htmlspecialchars($typeCls, ENT_QUOTES) ?>"><?= htmlspecialchars($typeLabel, ENT_QUOTES) ?></span>
                </div>

                <table class="cps-details-table">
                    <tr>
                        <th>Supplier / সরবরাহকারী</th>
                        <td>
                            <strong><?= htmlspecialchars($t['supplier_name'] ?? '—', ENT_QUOTES) ?></strong>
                            <?php if (!empty($t['mobile'])): ?>
                            <br><small>Mobile: <?= htmlspecialchars($t['mobile'], ENT_QUOTES) ?></small>
                            <?php endif; ?>
                            <?php if (!empty($t['supplier_address'])): ?>
                            <br><small><?= htmlspecialchars($t['supplier_address'], ENT_QUOTES) ?></small>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th>Transaction type</th>
                        <td><?= htmlspecialchars($typeLabel, ENT_QUOTES) ?></td>
                    </tr>
                    <tr>
                        <th>Payment mode</th>
                        <td>
                            <span class="cps-method-badge <?= htmlspecialchars($modeBadgeClass, ENT_QUOTES) ?>"><?= htmlspecialchars($modeLabel, ENT_QUOTES) ?></span>
                            <?php if ($mode === 'bank' && !empty($t['bank_name'])): ?>
                            <br><small><?= htmlspecialchars($t['bank_name'], ENT_QUOTES) ?></small>
                            <?php if (!empty($t['bank_account_number'])): ?>
                            <br><small>A/C: <?= htmlspecialchars($t['bank_account_number'], ENT_QUOTES) ?></small>
                            <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th>Paid by</th>
                        <td><?= htmlspecialchars($t['collected_by_name'] ?? '—', ENT_QUOTES) ?></td>
                    </tr>
                    <tr>
                        <th>Recorded by</th>
                        <td><?= htmlspecialchars($t['created_by_name'] ?? '—', ENT_QUOTES) ?></td>
                    </tr>
                    <?php if (!empty($t['journal_entry_id'])): ?>
                    <tr>
                        <th>GL journal</th>
                        <td>JE #<?= (int)$t['journal_entry_id'] ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if (!empty($t['remarks'])): ?>
                    <tr>
                        <th>Narration</th>
                        <td><?= nl2br(htmlspecialchars($t['remarks'], ENT_QUOTES)) ?></td>
                    </tr>
                    <?php endif; ?>
                </table>

                <div class="cps-thank-you">
                    <strong><?= htmlspecialchars($thankYouLine, ENT_QUOTES) ?></strong>
                    <div class="small text-muted mt-1">This is a computer-generated slip. Please retain for your records.</div>
                    <?php if ($isReversed): ?>
                    <div class="small text-danger mt-1">This voucher is reversed and has no accounting effect.</div>
                    <?php endif; ?>
                </div>

                <div class="cps-signatures">
                    <div>
                        <div class="sig-line">Supplier signature</div>
                    </div>
                    <div>
                        <div class="sig-line">Paid by</div>
                    </div>
                    <div>
                        <div class="sig-line">Authorized signature</div>
                    </div>
                </div>

                <div class="cps-footer-note">
                    <?= htmlspecialchars(APP_NAME, ENT_QUOTES) ?>
                    · Printed <?= date('d-m-Y h:i A') ?>
                    · Voucher <?= htmlspecialchars($t['payment_code'] ?? '', ENT_QUOTES) ?>
                    · ID #<?= $id ?>
                </div>
            </div>
        </div>
    </article>
</div>

</body>
</html>