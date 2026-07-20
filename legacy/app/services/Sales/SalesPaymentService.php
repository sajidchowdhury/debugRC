<?php
// app/services/Sales/SalesPaymentService.php — Phase 6 customer payments from sales

require_once __DIR__ . '/../../../core/Database.php';
require_once __DIR__ . '/../../helpers/Helper.php';
require_once __DIR__ . '/traits/SalesServiceSupportTrait.php';
require_once __DIR__ . '/traits/SalesPaymentOperationsTrait.php';

class SalesPaymentService extends Helper
{
    use SalesServiceSupportTrait;
    use SalesPaymentOperationsTrait;

    public function __construct(?Database $db = null)
    {
        parent::__construct($db);
    }
}