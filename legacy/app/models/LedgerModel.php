<?php
// app/models/LedgerModel.php

require_once __DIR__ . '/../../core/Database.php';
require_once __DIR__ . '/../helpers/Helper.php';


class LedgerModel extends Helper{

    /** Natures used by JournalPostingService — only one active account each. */
    public const CRITICAL_NATURES = [
        'customer_receivable',
        'supplier_payable',
        'employee_payable',
        'cash_bank',
        'inventory',
        'sales_revenue',
        'cogs',
    ];

    protected $db;

    public function __construct(?Database $db = null) {
        parent::__construct($db);
    }

    /**
     * Expected account_type + normal_balance for each ledger_nature.
     *
     * @return array<string, array{type: string, normal: string}>
     */
    public static function natureAccountRules(): array
    {
        return [
            'cash_bank'                 => ['type' => 'Asset',     'normal' => 'debit'],
            'customer_receivable'       => ['type' => 'Asset',     'normal' => 'debit'],
            'supplier_payable'          => ['type' => 'Liability', 'normal' => 'credit'],
            'employee_payable'          => ['type' => 'Asset',     'normal' => 'debit'],
            'inventory'                 => ['type' => 'Asset',     'normal' => 'debit'],
            'sales_revenue'             => ['type' => 'Income',    'normal' => 'credit'],
            'other_income'              => ['type' => 'Income',    'normal' => 'credit'],
            'sales_return'              => ['type' => 'Income',    'normal' => 'debit'],
            'cogs'                      => ['type' => 'Expense',   'normal' => 'debit'],
            'operating_expense'         => ['type' => 'Expense',   'normal' => 'debit'],
            'payroll_expense'           => ['type' => 'Expense',   'normal' => 'debit'],
            'depreciation'              => ['type' => 'Expense',   'normal' => 'debit'],
            'financial_expense'         => ['type' => 'Expense',   'normal' => 'debit'],
            'tax_payable'               => ['type' => 'Liability', 'normal' => 'credit'],
            'tax_receivable'            => ['type' => 'Asset',     'normal' => 'debit'],
            'fixed_asset'               => ['type' => 'Asset',     'normal' => 'debit'],
            'accumulated_depreciation'  => ['type' => 'Asset',     'normal' => 'credit'],
            'prepaid_expense'           => ['type' => 'Asset',     'normal' => 'debit'],
            'accrued_expense'           => ['type' => 'Liability', 'normal' => 'credit'],
            'long_term_liability'       => ['type' => 'Liability', 'normal' => 'credit'],
            'owner_equity'              => ['type' => 'Equity',    'normal' => 'credit'],
            'retained_earnings'         => ['type' => 'Equity',    'normal' => 'credit'],
            'drawings'                  => ['type' => 'Equity',    'normal' => 'debit'],
            'other_asset'               => ['type' => 'Asset',     'normal' => 'debit'],
            'other_liability'           => ['type' => 'Liability', 'normal' => 'credit'],
            'manual_adjustment'         => ['type' => 'Expense',   'normal' => 'debit'],
            'inventory_shrinkage'       => ['type' => 'Expense',   'normal' => 'debit'],
            'inventory_surplus'         => ['type' => 'Income',    'normal' => 'credit'],
            'sales_discount'            => ['type' => 'Income',    'normal' => 'debit'],
            'interbranch_receivable'    => ['type' => 'Asset',     'normal' => 'debit'],
            'interbranch_payable'       => ['type' => 'Liability', 'normal' => 'credit'],
        ];
    }

    public static function defaultNormalBalanceForType(string $accountType): string
    {
        return in_array($accountType, ['Liability', 'Equity', 'Income'], true) ? 'credit' : 'debit';
    }

    /**
     * Validate and normalize create/update payload.
     */
    public function validateLedgerPayload(array $input, ?int $excludeId = null): array
    {
        $name = trim((string)($input['ledger_name'] ?? ''));
        $accountType = trim((string)($input['account_type'] ?? 'Expense'));
        $nature = trim((string)($input['ledger_nature'] ?? ''));
        $normalBalance = strtolower(trim((string)($input['normal_balance'] ?? '')));
        $parentId = (int)($input['parent_id'] ?? 0);
        $isControl = !empty($input['is_control_account']) ? 1 : 0;
        $controlType = trim((string)($input['control_account_type'] ?? ''));
        $description = trim((string)($input['description'] ?? ''));
        $sortOrder = (int)($input['sort_order'] ?? 0);
        $isActive = !empty($input['is_active']) ? 1 : 0;

        $allowedTypes = ['Asset', 'Liability', 'Equity', 'Income', 'Expense'];

        if ($name === '') {
            return ['ok' => false, 'error' => 'Ledger name is required.'];
        }
        if (strlen($name) > 150) {
            return ['ok' => false, 'error' => 'Ledger name must be at most 150 characters.'];
        }
        if (!in_array($accountType, $allowedTypes, true)) {
            return ['ok' => false, 'error' => 'Invalid account type.'];
        }
        if ($nature === '') {
            return ['ok' => false, 'error' => 'Ledger nature is required — it drives automated journal posting.'];
        }

        $rules = self::natureAccountRules();
        if (isset($rules[$nature])) {
            $rule = $rules[$nature];
            if ($accountType !== $rule['type']) {
                return [
                    'ok'    => false,
                    'error' => "Nature \"{$nature}\" requires account type {$rule['type']}, not {$accountType}.",
                ];
            }
            if ($normalBalance === '') {
                $normalBalance = $rule['normal'];
            } elseif ($normalBalance !== $rule['normal']) {
                return [
                    'ok'    => false,
                    'error' => "Nature \"{$nature}\" requires normal balance {$rule['normal']}.",
                ];
            }
        } else {
            if ($normalBalance === '') {
                $normalBalance = self::defaultNormalBalanceForType($accountType);
            }
            $expectedNormal = self::defaultNormalBalanceForType($accountType);
            if ($normalBalance !== $expectedNormal && !in_array($normalBalance, ['debit', 'credit'], true)) {
                return ['ok' => false, 'error' => 'Normal balance must be debit or credit.'];
            }
            if ($normalBalance !== $expectedNormal) {
                return [
                    'ok'    => false,
                    'error' => "Account type {$accountType} normally has a {$expectedNormal} balance.",
                ];
            }
        }

        if (!in_array($normalBalance, ['debit', 'credit'], true)) {
            return ['ok' => false, 'error' => 'Normal balance must be debit or credit.'];
        }

        if ($isControl && $controlType === '') {
            return ['ok' => false, 'error' => 'Select a control account type when marking as control account.'];
        }

        if ($excludeId !== null && $parentId === $excludeId) {
            return ['ok' => false, 'error' => 'A ledger cannot be its own parent.'];
        }

        if ($parentId > 0) {
            $parent = $this->getLedgerById($parentId);
            if (!$parent) {
                return ['ok' => false, 'error' => 'Selected parent ledger does not exist.'];
            }
        }

        if ($isActive && in_array($nature, self::CRITICAL_NATURES, true)) {
            $existingId = $this->findActiveLedgerIdByNature($nature, $excludeId);
            if ($existingId !== null) {
                $existing = $this->getLedgerById($existingId);
                $label = $existing['ledger_name'] ?? ('ID ' . $existingId);
                return [
                    'ok'    => false,
                    'error' => "An active \"{$nature}\" account already exists ({$label}). Only one is allowed for automated posting.",
                ];
            }
        }

        return [
            'ok'   => true,
            'data' => [
                'ledger_name'           => $name,
                'parent_id'             => $parentId,
                'account_type'          => $accountType,
                'ledger_nature'         => $nature,
                'normal_balance'        => $normalBalance,
                'is_control_account'    => $isControl,
                'control_account_type'  => $controlType !== '' ? $controlType : null,
                'description'           => $description !== '' ? $description : null,
                'sort_order'            => $sortOrder,
                'is_active'             => $isActive,
                'is_system'             => !empty($input['is_system']) ? 1 : 0,
            ],
        ];
    }

    public function findActiveLedgerIdByNature(string $nature, ?int $excludeId = null): ?int
    {
        $sql = 'SELECT id FROM ledgers WHERE ledger_nature = :nature AND is_active = 1';
        if ($excludeId !== null) {
            $sql .= ' AND id != :exclude_id';
        }
        $sql .= ' ORDER BY is_system DESC, sort_order ASC, id ASC LIMIT 1';

        $this->db->query($sql);
        $this->db->bind(':nature', $nature);
        if ($excludeId !== null) {
            $this->db->bind(':exclude_id', $excludeId);
        }
        $row = $this->db->single();

        return !empty($row['id']) ? (int)$row['id'] : null;
    }

    /**
     * Reason deactivation/toggle-off is blocked, or null if allowed.
     */
    public function getToggleBlockReason(int $id): ?string
    {
        if ($this->isSystemLedger($id)) {
            return 'System ledgers cannot be deactivated.';
        }

        $ledger = $this->getLedgerById($id);
        if (!$ledger) {
            return 'Ledger not found.';
        }

        if (empty($ledger['is_active'])) {
            return null;
        }

        $usage = $this->getLedgerUsage($id);

        if ($usage['journal_lines'] > 0) {
            return 'Cannot deactivate: this account has '
                . $usage['journal_lines']
                . ' journal line(s). Historical GL data must be preserved.';
        }

        if ($usage['children'] > 0) {
            return 'Cannot deactivate: this account has '
                . $usage['children']
                . ' child account(s). Reassign or deactivate them first.';
        }

        $nature = (string)($ledger['ledger_nature'] ?? '');
        if ($nature !== '' && $this->isOnlyActiveLedgerForNature($nature, $id)) {
            return 'Cannot deactivate: this is the only active account for nature "'
                . str_replace('_', ' ', $nature)
                . '". Automated posting requires it.';
        }

        return null;
    }

    public function isOnlyActiveLedgerForNature(string $nature, int $ledgerId): bool
    {
        if ($nature === '') {
            return false;
        }

        $this->db->query('
            SELECT COUNT(*) AS c FROM ledgers
            WHERE ledger_nature = :nature AND is_active = 1 AND id != :id
        ');
        $this->db->bind(':nature', $nature);
        $this->db->bind(':id', $ledgerId);

        return (int)($this->db->single()['c'] ?? 0) === 0;
    }

    public function getAllLedgers() {
        return $this->Get_All_Ledger();
    }

    public function getLedgerById($id) {
        return $this->Get_All_Ledger_By_Id($id);
    }

    /**
     * Get all active ledgers (improved version)
     */
    public function getActiveLedgers() {
        $this->db->query("
            SELECT * FROM ledgers 
            WHERE is_active = 1 
            ORDER BY sort_order ASC, ledger_name ASC
        ");
        return $this->db->resultSet();
    }

    /**
     * Get ledgers by nature (e.g. 'cash_bank', 'customer_receivable', 'sales_revenue', 'cogs', 'inventory')
     * Used by JournalPostingService and automated posting logic.
     */
    public function getLedgersByNature($nature) {
        $this->db->query("
            SELECT * FROM ledgers 
            WHERE ledger_nature = :nature AND is_active = 1
            ORDER BY sort_order ASC, ledger_name ASC
        ");
        $this->db->bind(':nature', $nature);
        return $this->db->resultSet();
    }

    /**
     * Get control accounts (e.g. Accounts Receivable, Accounts Payable)
     */
    public function getControlAccounts() {
        $this->db->query("
            SELECT * FROM ledgers 
            WHERE is_control_account = 1 AND is_active = 1
            ORDER BY ledger_name ASC
        ");
        return $this->db->resultSet();
    }

    /**
     * Get ledgers for dropdown (with indentation for parents)
     */
    public function getLedgersForDropdown() {
        $this->db->query("
            SELECT id, ledger_code, ledger_name, parent_id, account_type 
            FROM ledgers 
            WHERE is_active = 1 
            ORDER BY sort_order ASC, ledger_name ASC
        ");
        return $this->db->resultSet();
    }

    private function generateLedgerCode(): string
    {
        $this->db->query("
            SELECT ledger_code FROM ledgers
            WHERE ledger_code REGEXP '^L-[0-9]+$'
            ORDER BY CAST(SUBSTRING(ledger_code, 3) AS UNSIGNED) DESC
            LIMIT 1
        ");
        $row = $this->db->single();
        $next = 1;
        if (!empty($row['ledger_code']) && preg_match('/^L-(\d+)$/', $row['ledger_code'], $m)) {
            $next = (int)$m[1] + 1;
        }

        return 'L-' . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
    }

    public function createLedger(array $data)
    {
        $ledger_code = $this->generateLedgerCode();

        $this->db->query("
            INSERT INTO ledgers 
            (ledger_code, ledger_name, description, parent_id, account_type, ledger_nature, 
             normal_balance, is_active, is_system, is_control_account, control_account_type,
             sort_order, created_by)
            VALUES 
            (:ledger_code, :ledger_name, :description, :parent_id, :account_type, :ledger_nature, 
             :normal_balance, :is_active, :is_system, :is_control_account, :control_account_type,
             :sort_order, :created_by)
        ");

        $this->db->bind(':ledger_code', $ledger_code);
        $this->db->bind(':ledger_name', $data['ledger_name']);
        $this->db->bind(':description', $data['description'] ?? null);
        $this->db->bind(':parent_id', $data['parent_id'] ?? 0);
        $this->db->bind(':account_type', $data['account_type'] ?? 'Expense');
        $this->db->bind(':ledger_nature', $data['ledger_nature'] ?? null);
        $this->db->bind(':normal_balance', $data['normal_balance'] ?? 'debit');
        $this->db->bind(':is_active', $data['is_active'] ?? 1);
        $this->db->bind(':is_system', $data['is_system'] ?? 0);
        $this->db->bind(':is_control_account', $data['is_control_account'] ?? 0);
        $this->db->bind(':control_account_type', $data['control_account_type'] ?? null);
        $this->db->bind(':sort_order', $data['sort_order'] ?? 0);
        $this->db->bind(':created_by', $_SESSION['user_id'] ?? 1);

        if (!$this->db->execute()) {
            return false;
        }

        return (int)$this->db->lastInsertId();
    }

    public function updateLedger($id, array $data)
    {
        if ($this->isSystemLedger($id)) {
            return false;
        }

        $this->db->query("
            UPDATE ledgers SET 
                ledger_name = :ledger_name,
                description = :description,
                parent_id = :parent_id,
                account_type = :account_type,
                ledger_nature = :ledger_nature,
                normal_balance = :normal_balance,
                is_control_account = :is_control_account,
                control_account_type = :control_account_type,
                sort_order = :sort_order,
                is_active = :is_active,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");

        $this->db->bind(':ledger_name', $data['ledger_name']);
        $this->db->bind(':description', $data['description'] ?? null);
        $this->db->bind(':parent_id', $data['parent_id'] ?? 0);
        $this->db->bind(':account_type', $data['account_type'] ?? 'Expense');
        $this->db->bind(':ledger_nature', $data['ledger_nature'] ?? null);
        $this->db->bind(':normal_balance', $data['normal_balance'] ?? 'debit');
        $this->db->bind(':is_control_account', $data['is_control_account'] ?? 0);
        $this->db->bind(':control_account_type', $data['control_account_type'] ?? null);
        $this->db->bind(':sort_order', $data['sort_order'] ?? 0);
        $this->db->bind(':is_active', $data['is_active'] ?? 1);
        $this->db->bind(':id', $id);

        return $this->db->execute();
    }

    public function toggleStatus($id)
    {
        if ($this->getToggleBlockReason((int)$id) !== null) {
            return false;
        }

        $ledger = $this->getLedgerById($id);
        if (!$ledger) {
            return false;
        }

        if (!empty($ledger['is_active'])) {
            return $this->setActiveStatus((int)$id, false);
        }

        return $this->setActiveStatus((int)$id, true);
    }

    public function setActiveStatus(int $id, bool $active): bool
    {
        if (!$active) {
            $block = $this->getToggleBlockReason($id);
            if ($block !== null) {
                return false;
            }
        } else {
            $ledger = $this->getLedgerById($id);
            if (!$ledger) {
                return false;
            }
            $nature = (string)($ledger['ledger_nature'] ?? '');
            if ($nature !== '' && in_array($nature, self::CRITICAL_NATURES, true)) {
                $existingId = $this->findActiveLedgerIdByNature($nature, $id);
                if ($existingId !== null) {
                    return false;
                }
            }
        }

        $this->db->query('UPDATE ledgers SET is_active = :active, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
        $this->db->bind(':active', $active ? 1 : 0);
        $this->db->bind(':id', $id);

        return $this->db->execute();
    }

    /**
     * Soft delete (deactivate) a ledger. System ledgers are blocked.
     */
    public function softDeleteLedger($id)
    {
        return $this->setActiveStatus((int)$id, false);
    }

    /**
     * Check if a ledger is a system ledger (protected)
     */
    public function isSystemLedger($id): bool {
        $this->db->query("SELECT is_system FROM ledgers WHERE id = :id LIMIT 1");
        $this->db->bind(':id', $id);
        $row = $this->db->single();
        return !empty($row['is_system']);
    }

    /**
     * Server-side DataTables for Ledgers (supports new accounting columns)
     */
    public function getLedgersForDataTable(array $params): array
    {
        $start       = (int)($params['start'] ?? 0);
        $length      = (int)($params['length'] ?? 25);
        $searchValue = trim($params['search']['value'] ?? '');
        $orderColumn = (int)($params['order'][0]['column'] ?? 0);
        $orderDir    = $params['order'][0]['dir'] ?? 'asc';

        $filterAccountType   = $params['filterAccountType'] ?? '';
        $filterLedgerNature  = $params['filterLedgerNature'] ?? '';
        $filterIsControl     = $params['filterIsControl'] ?? '';
        $filterIsSystem      = $params['filterIsSystem'] ?? '';
        $filterStatus        = $params['filterStatus'] ?? ''; // 'active', 'inactive'

        $showInactive = !empty($params['showInactive']);
        $viewHierarchy = !empty($params['viewHierarchy']);

        $columns = [
            'l.ledger_code',
            'l.ledger_name',
            'p.ledger_name',
            'l.account_type',
            'l.ledger_nature',
            'l.normal_balance',
            'l.is_control_account',
            'l.is_system',
            'l.is_active'
        ];

        $baseQuery = "
            FROM ledgers l
            LEFT JOIN ledgers p ON l.parent_id = p.id
        ";

        $where = [];
        $bindParams = [];

        // Account Type filter
        if ($filterAccountType) {
            $where[] = "l.account_type = :account_type";
            $bindParams[':account_type'] = $filterAccountType;
        }

        // Ledger Nature filter
        if ($filterLedgerNature) {
            $where[] = "l.ledger_nature = :ledger_nature";
            $bindParams[':ledger_nature'] = $filterLedgerNature;
        }

        // Control Account filter
        if ($filterIsControl === '1') {
            $where[] = "l.is_control_account = 1";
        } elseif ($filterIsControl === '0') {
            $where[] = "l.is_control_account = 0";
        }

        // System filter
        if ($filterIsSystem === '1') {
            $where[] = "l.is_system = 1";
        } elseif ($filterIsSystem === '0') {
            $where[] = "l.is_system = 0";
        }

        // Status filter
        if ($filterStatus === 'active') {
            $where[] = "l.is_active = 1";
        } elseif ($filterStatus === 'inactive') {
            $where[] = "l.is_active = 0";
        } elseif ($showInactive) {
            $where[] = "l.is_active = 0";
        } else {
            $where[] = "l.is_active = 1";
        }

        // Global search
        if ($searchValue !== '') {
            $where[] = "(l.ledger_code LIKE :search OR l.ledger_name LIKE :search OR p.ledger_name LIKE :search)";
            $bindParams[':search'] = "%{$searchValue}%";
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        // Total records
        $totalQuery = "SELECT COUNT(l.id) as total {$baseQuery} {$whereSql}";
        $this->db->query($totalQuery);
        foreach ($bindParams as $key => $val) {
            $this->db->bind($key, $val);
        }
        $totalResult = $this->db->single();
        $recordsTotal = $totalResult['total'] ?? 0;

        // Filtered count
        $filteredQuery = "SELECT COUNT(l.id) as total {$baseQuery} {$whereSql}";
        $this->db->query($filteredQuery);
        foreach ($bindParams as $key => $val) {
            $this->db->bind($key, $val);
        }
        $filteredResult = $this->db->single();
        $recordsFiltered = $filteredResult['total'] ?? 0;

        // Data query
        if ($viewHierarchy) {
            $orderBy = 'COALESCE(NULLIF(l.parent_id, 0), l.id), l.parent_id ASC, l.sort_order ASC, l.ledger_name ASC';
            $orderDir = 'ASC';
        } else {
            $orderBy = $columns[$orderColumn] ?? 'l.sort_order, l.ledger_name';
        }
        $dataQuery = "
            SELECT 
                l.*,
                p.ledger_name as parent_name
            {$baseQuery}
            {$whereSql}
            ORDER BY {$orderBy} {$orderDir}
            LIMIT {$start}, {$length}
        ";

        $this->db->query($dataQuery);
        foreach ($bindParams as $key => $val) {
            $this->db->bind($key, $val);
        }
        $data = $this->db->resultSet();

        $parentMap = $this->getLedgerParentMap();
        foreach ($data as &$row) {
            $row['hierarchy_depth'] = $this->computeHierarchyDepth($row, $parentMap);
        }
        unset($row);

        return [
            'draw'            => (int)($params['draw'] ?? 1),
            'recordsTotal'    => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data'            => $data
        ];
    }

    /**
     * Hero stat tiles for ledger index.
     */
    public function getLedgerIndexStats(): array
    {
        $stats = [
            'active'   => 0,
            'inactive' => 0,
            'system'   => 0,
            'control'  => 0,
        ];

        $this->db->query('SELECT COUNT(*) AS c FROM ledgers WHERE is_active = 1');
        $stats['active'] = (int)($this->db->single()['c'] ?? 0);

        $this->db->query('SELECT COUNT(*) AS c FROM ledgers WHERE is_active = 0');
        $stats['inactive'] = (int)($this->db->single()['c'] ?? 0);

        $this->db->query('SELECT COUNT(*) AS c FROM ledgers WHERE is_system = 1 AND is_active = 1');
        $stats['system'] = (int)($this->db->single()['c'] ?? 0);

        $this->db->query('SELECT COUNT(*) AS c FROM ledgers WHERE is_control_account = 1 AND is_active = 1');
        $stats['control'] = (int)($this->db->single()['c'] ?? 0);

        return $stats;
    }

    /**
     * Snapshot for edit sidebar (journal usage, child accounts).
     */
    public function getLedgerUsage(int $ledgerId): array
    {
        $this->db->query('SELECT is_active, is_system, is_control_account, ledger_code, ledger_name FROM ledgers WHERE id = :id');
        $this->db->bind(':id', $ledgerId);
        $ledger = $this->db->single() ?: [];

        $this->db->query('SELECT COUNT(*) AS c FROM journal_lines WHERE ledger_id = :id');
        $this->db->bind(':id', $ledgerId);
        $journalLines = (int)($this->db->single()['c'] ?? 0);

        $this->db->query('SELECT COUNT(*) AS c FROM ledgers WHERE parent_id = :id');
        $this->db->bind(':id', $ledgerId);
        $children = (int)($this->db->single()['c'] ?? 0);

        return [
            'journal_lines'      => $journalLines,
            'children'           => $children,
            'is_active'          => !empty($ledger['is_active']),
            'is_system'          => !empty($ledger['is_system']),
            'is_control_account' => !empty($ledger['is_control_account']),
            'ledger_code'        => $ledger['ledger_code'] ?? '',
            'ledger_name'        => $ledger['ledger_name'] ?? '',
        ];
    }

    /**
     * Grouped nature labels for filters and forms.
     *
     * @return array<string, array<string, string>>
     */
    public static function getNatureOptionGroups(): array
    {
        return [
            'Control & Sub-Ledger Accounts' => [
                'cash_bank'            => 'Cash & Bank',
                'customer_receivable'  => 'Customer Receivable (AR)',
                'supplier_payable'     => 'Supplier Payable (AP)',
                'employee_payable'     => 'Employee Payable / Receivable',
            ],
            'Revenue' => [
                'sales_revenue' => 'Sales Revenue',
                'other_income'  => 'Other Income',
                'sales_return'  => 'Sales Returns & Allowances',
                'sales_discount'=> 'Sales Discount',
                'inventory_surplus' => 'Inventory Surplus',
            ],
            'Cost of Sales' => [
                'inventory' => 'Inventory / Stock',
                'cogs'      => 'Cost of Goods Sold (COGS)',
            ],
            'Expenses' => [
                'operating_expense' => 'Operating / Administrative Expense',
                'payroll_expense'   => 'Payroll & Salaries',
                'depreciation'      => 'Depreciation & Amortization',
                'financial_expense' => 'Financial Expense (Interest, Bank Charges)',
                'inventory_shrinkage' => 'Inventory Shrinkage',
            ],
            'Tax & Statutory' => [
                'tax_payable'    => 'Tax Payable (VAT/GST Output)',
                'tax_receivable' => 'Tax Receivable (Input VAT)',
            ],
            'Balance Sheet Specific' => [
                'fixed_asset'               => 'Fixed Assets (PPE)',
                'accumulated_depreciation'  => 'Accumulated Depreciation',
                'prepaid_expense'           => 'Prepaid Expenses',
                'accrued_expense'           => 'Accrued Expenses / Liabilities',
                'long_term_liability'       => 'Long Term Liability',
                'owner_equity'              => "Owner's Capital / Equity",
                'retained_earnings'         => 'Retained Earnings',
                'drawings'                  => "Owner's Drawings",
                'interbranch_receivable'    => 'Inter-branch Receivable',
                'interbranch_payable'       => 'Inter-branch Payable',
            ],
            'General' => [
                'other_asset'        => 'Other Asset',
                'other_liability'    => 'Other Liability',
                'manual_adjustment'  => 'Manual Journal Adjustment',
            ],
        ];
    }

    /**
     * Flat list for parent picker with indentation depth.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getHierarchicalParentOptions(?int $excludeId = null): array
    {
        $this->db->query('
            SELECT id, ledger_code, ledger_name, parent_id, is_active
            FROM ledgers
            ORDER BY sort_order ASC, ledger_name ASC
        ');
        $all = $this->db->resultSet();
        $byParent = [];
        foreach ($all as $row) {
            $pid = (int)($row['parent_id'] ?? 0);
            $byParent[$pid][] = $row;
        }

        $out = [];
        $walk = function (int $parentId, int $depth) use (&$walk, &$out, $byParent, $excludeId): void {
            foreach ($byParent[$parentId] ?? [] as $row) {
                $id = (int)$row['id'];
                if ($excludeId !== null && $id === $excludeId) {
                    continue;
                }
                $prefix = $depth > 0 ? str_repeat('— ', $depth) : '';
                $row['picker_depth'] = $depth;
                $row['picker_label'] = $prefix . ($row['ledger_name'] ?? '');
                $out[] = $row;
                $walk($id, $depth + 1);
            }
        };
        $walk(0, 0);

        return $out;
    }

    /** @return array<int, int> id => parent_id */
    private function getLedgerParentMap(): array
    {
        $this->db->query('SELECT id, parent_id FROM ledgers');
        $map = [];
        foreach ($this->db->resultSet() as $row) {
            $map[(int)$row['id']] = (int)($row['parent_id'] ?? 0);
        }
        return $map;
    }

    /** @param array<string, mixed> $row */
    private function computeHierarchyDepth(array $row, array $parentMap): int
    {
        $depth = 0;
        $current = (int)($row['parent_id'] ?? 0);
        $seen = [];
        while ($current > 0 && !isset($seen[$current])) {
            $seen[$current] = true;
            $depth++;
            $current = (int)($parentMap[$current] ?? 0);
            if ($depth > 12) {
                break;
            }
        }
        return $depth;
    }

    public function getLedgerWithParent(int $ledgerId): ?array
    {
        $this->db->query('
            SELECT l.*, p.ledger_name AS parent_name, p.ledger_code AS parent_code
            FROM ledgers l
            LEFT JOIN ledgers p ON l.parent_id = p.id
            WHERE l.id = :id
            LIMIT 1
        ');
        $this->db->bind(':id', $ledgerId);
        $row = $this->db->single();
        return $row ?: null;
    }

    public function getLedgerGlBalance(int $ledgerId): array
    {
        $ledger = $this->getLedgerById($ledgerId);
        if (!$ledger) {
            return [
                'total_debit'  => 0,
                'total_credit' => 0,
                'line_count'   => 0,
                'net'          => 0,
                'balance'      => 0,
                'balance_side' => 'Dr',
            ];
        }

        $this->db->query('
            SELECT
                COALESCE(SUM(jl.debit), 0) AS total_debit,
                COALESCE(SUM(jl.credit), 0) AS total_credit,
                COUNT(jl.id) AS line_count
            FROM journal_lines jl
            INNER JOIN journal_entries je ON je.id = jl.journal_entry_id
            WHERE jl.ledger_id = :id
              AND COALESCE(je.is_reversed, 0) = 0
        ');
        $this->db->bind(':id', $ledgerId);
        $sums = $this->db->single() ?: [];

        $debit = (float)($sums['total_debit'] ?? 0);
        $credit = (float)($sums['total_credit'] ?? 0);
        $net = round($debit - $credit, 2);

        if (($ledger['normal_balance'] ?? 'debit') === 'debit') {
            $balance = $net;
            $side = $net >= 0 ? 'Dr' : 'Cr';
        } else {
            $balance = -$net;
            $side = $net <= 0 ? 'Cr' : 'Dr';
        }

        return [
            'total_debit'  => round($debit, 2),
            'total_credit' => round($credit, 2),
            'line_count'   => (int)($sums['line_count'] ?? 0),
            'net'          => $net,
            'balance'      => round(abs($balance), 2),
            'balance_side' => $side,
        ];
    }

    public function getRecentJournalLinesForLedger(int $ledgerId, int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        $this->db->query("
            SELECT
                jl.id AS line_id,
                jl.debit,
                jl.credit,
                jl.description AS line_description,
                je.id AS journal_entry_id,
                je.entry_no,
                je.entry_date,
                je.description AS entry_description,
                je.reference_type,
                je.reference_id,
                COALESCE(je.is_reversed, 0) AS is_reversed
            FROM journal_lines jl
            INNER JOIN journal_entries je ON je.id = jl.journal_entry_id
            WHERE jl.ledger_id = :id
            ORDER BY je.entry_date DESC, je.id DESC, jl.id DESC
            LIMIT {$limit}
        ");
        $this->db->bind(':id', $ledgerId);
        return $this->db->resultSet() ?: [];
    }

    public function getChildLedgers(int $ledgerId): array
    {
        $this->db->query('
            SELECT id, ledger_code, ledger_name, account_type, ledger_nature, is_active
            FROM ledgers
            WHERE parent_id = :id
            ORDER BY sort_order ASC, ledger_name ASC
        ');
        $this->db->bind(':id', $ledgerId);
        return $this->db->resultSet() ?: [];
    }

    public function getBankAccountsForLedger(int $ledgerId): array
    {
        try {
            $this->db->query("SHOW TABLES LIKE 'bank_ledger_mappings'");
            if (!$this->db->single()) {
                return [];
            }
        } catch (Throwable $e) {
            return [];
        }

        $this->db->query('
            SELECT b.id, b.bank_name, b.account_number, b.is_active, b.balance
            FROM bank_ledger_mappings blm
            INNER JOIN banks b ON b.id = blm.bank_id
            WHERE blm.ledger_id = :lid
            ORDER BY b.bank_name ASC
        ');
        $this->db->bind(':lid', $ledgerId);
        return $this->db->resultSet() ?: [];
    }

    public function updateSystemLedgerMetadata(int $id, array $data): bool
    {
        if (!$this->isSystemLedger($id)) {
            return false;
        }

        $description = trim((string)($data['description'] ?? ''));

        $this->db->query('
            UPDATE ledgers SET
                description = :description,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ');
        $this->db->bind(':description', $description !== '' ? $description : null);
        $this->db->bind(':id', $id);

        return $this->db->execute();
    }
}