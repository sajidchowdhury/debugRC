<?php
// app/services/Sales/SalesInvoiceService.php — Phase 6 invoice finalize, edit, today list

require_once __DIR__ . '/../../../core/Database.php';
require_once __DIR__ . '/../../helpers/Helper.php';
require_once __DIR__ . '/traits/SalesServiceSupportTrait.php';
require_once __DIR__ . '/traits/SalesInvoiceOperationsTrait.php';

class SalesInvoiceService extends Helper
{
    use SalesServiceSupportTrait;
    use SalesInvoiceOperationsTrait;

    public function __construct(?Database $db = null)
    {
        parent::__construct($db);
    }
}