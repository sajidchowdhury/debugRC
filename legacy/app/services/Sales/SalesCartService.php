<?php
// app/services/Sales/SalesCartService.php — Phase 6 session/DB draft carts

require_once __DIR__ . '/../../../core/Database.php';
require_once __DIR__ . '/../../helpers/Helper.php';
require_once __DIR__ . '/traits/SalesServiceSupportTrait.php';
require_once __DIR__ . '/traits/SalesCartOperationsTrait.php';

class SalesCartService extends Helper
{
    use SalesServiceSupportTrait;
    use SalesCartOperationsTrait;

    public function __construct(?Database $db = null)
    {
        parent::__construct($db);
    }
}