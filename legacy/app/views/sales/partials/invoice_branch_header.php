<?php
/**
 * Branch-specific SVG invoice header.
 * @var int $branch_id
 * @var array $invoice
 */
$branchId = (int)($branch_id ?? $invoice['branch_id'] ?? 1);
$docLabelBn = htmlspecialchars($doc_label_bn ?? 'বিক্রয় চালান', ENT_QUOTES);
$docLabelEn = htmlspecialchars($doc_label_en ?? 'SALES INVOICE', ENT_QUOTES);
$branchName = htmlspecialchars($invoice['branch_name'] ?? 'Remote Center', ENT_QUOTES);
$branchAddr = htmlspecialchars($invoice['branch_address'] ?? '', ENT_QUOTES);
$branchPhone = htmlspecialchars($invoice['branch_phone'] ?? '', ENT_QUOTES);

$themes = [
    1 => ['primary' => '#c62828', 'secondary' => '#ff8f00', 'accent' => '#b71c1c', 'label' => 'Head Office'],
    2 => ['primary' => '#1565c0', 'secondary' => '#42a5f5', 'accent' => '#0d47a1', 'label' => 'Patuatuli'],
    3 => ['primary' => '#2e7d32', 'secondary' => '#66bb6a', 'accent' => '#1b5e20', 'label' => 'Nowabpur'],
    4 => ['primary' => '#ef6c00', 'secondary' => '#ffb74d', 'accent' => '#e65100', 'label' => 'Tarabo Factory'],
];
$t = $themes[$branchId] ?? ['primary' => '#4f46e5', 'secondary' => '#818cf8', 'accent' => '#3730a3', 'label' => $branchName];
$p = $t['primary'];
$s = $t['secondary'];
$a = $t['accent'];
?>
<svg class="invoice-branch-svg" viewBox="0 0 720 100" preserveAspectRatio="xMidYMid meet" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="<?= $branchName ?>">
    <defs>
        <linearGradient id="hdrGrad<?= $branchId ?>" x1="0%" y1="0%" x2="100%" y2="0%">
            <stop offset="0%" style="stop-color:<?= $p ?>"/>
            <stop offset="55%" style="stop-color:<?= $s ?>"/>
            <stop offset="100%" style="stop-color:<?= $a ?>"/>
        </linearGradient>
    </defs>
    <rect width="720" height="100" rx="8" fill="url(#hdrGrad<?= $branchId ?>)"/>
    <path d="M0,72 Q180,28 360,72 T720,72 L720,100 L0,100 Z" fill="rgba(255,255,255,0.12)"/>
    <circle cx="52" cy="50" r="28" fill="rgba(255,255,255,0.95)"/>
    <text x="52" y="56" text-anchor="middle" font-family="Arial,sans-serif" font-size="22" font-weight="bold" fill="<?= $p ?>">★</text>
    <text x="95" y="38" font-family="'Noto Sans Bengali',Arial,sans-serif" font-size="22" font-weight="700" fill="#fff">রিমোট সেন্টার</text>
    <text x="95" y="58" font-family="Arial,sans-serif" font-size="14" font-weight="600" fill="rgba(255,255,255,0.95)" letter-spacing="2">REMOTE CENTER</text>
    <text x="95" y="78" font-family="'Noto Sans Bengali',Arial,sans-serif" font-size="11" fill="rgba(255,255,255,0.9)"><?= $branchName ?></text>
    <text x="700" y="32" text-anchor="end" font-family="'Noto Sans Bengali',Arial,sans-serif" font-size="10" fill="rgba(255,255,255,0.85)"><?= $docLabelBn ?></text>
    <text x="700" y="48" text-anchor="end" font-family="Arial,sans-serif" font-size="10" fill="rgba(255,255,255,0.85)"><?= $docLabelEn ?></text>
</svg>
<?php if ($branchAddr || $branchPhone): ?>
<p class="invoice-branch-meta">
    <?php if ($branchAddr): ?><span><?= $branchAddr ?></span><?php endif; ?>
    <?php if ($branchPhone): ?><span><?= $branchPhone ?></span><?php endif; ?>
</p>
<?php endif; ?>