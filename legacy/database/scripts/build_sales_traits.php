<?php
/**
 * One-off helper: extracts SalesModel method blocks into traits (Phase 6).
 * Usage: php database/scripts/build_sales_traits.php
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$src = file($root . '/app/models/SalesModel.php');
if ($src === false) {
    fwrite(STDERR, "Cannot read SalesModel.php\n");
    exit(1);
}

$line = fn(int $n) => $src[$n - 1] ?? '';

$slice = static function (int $from, int $to) use ($src): string {
    return implode('', array_slice($src, $from - 1, $to - $from + 1));
};

$support = <<<'HDR'
<?php
// app/services/Sales/traits/SalesServiceSupportTrait.php — shared stock validation

require_once __DIR__ . '/../../Stock/StockService.php';

trait SalesServiceSupportTrait
{
    protected ?StockService $stockService = null;

    protected function stock(): StockService
    {
        if ($this->stockService === null) {
            $this->stockService = new StockService($this->db);
        }

        return $this->stockService;
    }

    protected function validateCartStockAvailability(array $items, int $branch_id, ?int $excludeInvoiceId = null): array
    {
        $qtyByProduct = [];
        foreach ($items as $item) {
            $pid = (int)($item['product_id'] ?? 0);
            if ($pid <= 0) {
                continue;
            }
            $qtyByProduct[$pid] = ($qtyByProduct[$pid] ?? 0) + (float)($item['qty'] ?? 0);
        }

        return $this->stock()->assertBranchProductsAvailable($branch_id, $qtyByProduct, $excludeInvoiceId);
    }

    protected function getCustomerRunningBalance($customer_id)
    {
        return $this->Get_Customer_Now_Due($customer_id);
    }
}

HDR;

$wrapTrait = static function (string $name, string $body): string {
    $body = preg_replace('/^    /m', '    ', $body);
    return "<?php\n// app/services/Sales/traits/{$name}.php — Phase 6 (extracted from SalesModel)\n\ntrait {$name}\n{\n" . $body . "\n}\n";
};

$traitDir = $root . '/app/services/Sales/traits';
if (!is_dir($traitDir)) {
    mkdir($traitDir, 0755, true);
}

file_put_contents($traitDir . '/SalesServiceSupportTrait.php', $support);

$cartBody = $slice(80, 231);
file_put_contents(
    $traitDir . '/SalesCartOperationsTrait.php',
    $wrapTrait('SalesCartOperationsTrait', $cartBody)
);

$invoiceBody = $slice(237, 981) . "\n" . $slice(1459, 1687) . "\n" . $slice(1711, 1778);
file_put_contents(
    $traitDir . '/SalesInvoiceOperationsTrait.php',
    $wrapTrait('SalesInvoiceOperationsTrait', $invoiceBody)
);

$paymentBody = $slice(984, 1394);
file_put_contents(
    $traitDir . '/SalesPaymentOperationsTrait.php',
    $wrapTrait('SalesPaymentOperationsTrait', $paymentBody)
);

echo "Traits written under app/services/Sales/traits/\n";