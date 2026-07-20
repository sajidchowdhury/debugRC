<?php
// app/helpers/AccountingNavHelper.php — Phase 8B accounting navigation hub

require_once __DIR__ . '/../../core/Auth.php';

class AccountingNavHelper
{
    /** Controllers that show accounting breadcrumb + quick nav context. */
    public const ACCOUNTING_CONTROLLERS = [
        'AccountingController',
        'LedgerController',
        'ManualJournalController',
        'OtherExpenseController',
        'OtherIncomeController',
        'MoneyTransferController',
        'CustomerTransactionController',
        'SupplierTransactionController',
        'EmployeeTransactionController',
        'BankController',
        'ReconciliationController',
        'AccountingPeriodController',
    ];

    public static function hubUrl(): string
    {
        return (defined('BASE_URL') ? BASE_URL : '/') . 'Accounting/index';
    }

    public static function reportsFinanceUrl(): string
    {
        return (defined('BASE_URL') ? BASE_URL : '/') . 'Report/index#cat-finance';
    }

    public static function reportsHubUrl(): string
    {
        return (defined('BASE_URL') ? BASE_URL : '/') . 'Report/index';
    }

    public static function canSeeAccountingNav(): bool
    {
        if (!Auth::isLoggedIn()) {
            return false;
        }
        $role = $_SESSION['role'] ?? '';
        return in_array($role, ['admin', 'manager', 'accountant', 'superadmin'], true);
    }

    /**
     * Quick nav — operational modules only; all reports live under Reports hub.
     *
     * @return array<int, array{label: string, route: string, icon: string, group?: string}>
     */
    public static function quickNavItems(): array
    {
        return [
            ['label' => 'Accounting home', 'route' => 'Accounting/index', 'icon' => 'fa-house', 'group' => 'hub'],
            ['label' => 'Chart of accounts', 'route' => 'ledger', 'icon' => 'fa-book', 'group' => 'coa'],
            ['label' => 'GL reconciliation', 'route' => 'Reconciliation/index', 'icon' => 'fa-scale-balanced', 'group' => 'control'],
            ['label' => 'Customer payments', 'route' => 'CustomerTransaction/index', 'icon' => 'fa-hand-holding-dollar', 'group' => 'money'],
            ['label' => 'Supplier payments', 'route' => 'SupplierTransaction/index', 'icon' => 'fa-truck', 'group' => 'money'],
            ['label' => 'Employee money', 'route' => 'EmployeeTransaction/index', 'icon' => 'fa-user-tie', 'group' => 'money'],
            ['label' => 'Bank accounts', 'route' => 'bank', 'icon' => 'fa-building-columns', 'group' => 'money'],
            ['label' => 'Other income', 'route' => 'OtherIncome/index', 'icon' => 'fa-circle-plus', 'group' => 'vouchers'],
            ['label' => 'Other expense', 'route' => 'OtherExpense/index', 'icon' => 'fa-circle-minus', 'group' => 'vouchers'],
            ['label' => 'Money transfer', 'route' => 'MoneyTransfer/index', 'icon' => 'fa-right-left', 'group' => 'vouchers'],
            ['label' => 'Manual journals', 'route' => 'ManualJournal/index', 'icon' => 'fa-pen-to-square', 'group' => 'journals'],
            ['label' => 'Period close', 'route' => 'AccountingPeriod/index', 'icon' => 'fa-lock', 'group' => 'period'],
            ['label' => 'Year-end checklist', 'route' => 'AccountingPeriod/year_end', 'icon' => 'fa-calendar-check', 'group' => 'period'],
            ['label' => 'Financial reports', 'route' => 'Report/index#cat-finance', 'icon' => 'fa-table-cells-large', 'group' => 'reports'],
            ['label' => 'All reports', 'route' => 'Report/index', 'icon' => 'fa-chart-bar', 'group' => 'reports'],
        ];
    }

    /**
     * Sidebar menu groups (2 levels under Accounting).
     *
     * @return array<int, array{label: string, icon: string, items: array<int, array{label: string, route: string, icon: string}>}>
     */
    public static function sidebarGroups(): array
    {
        return [
     
    
        ];
    }

    /**
     * Hub landing tiles (grouped).
     *
     * @return array<int, array{title: string, items: array<int, array{label: string, route: string, icon: string, blurb?: string}>}>
     */
    public static function hubSections(): array
    {
        return [
            [
                'title' => 'Core GL',
                'items' => [
                    ['label' => 'Chart of accounts', 'route' => 'ledger', 'icon' => 'fa-book', 'blurb' => 'CoA, control heads, nature rules'],
                    ['label' => 'GL reconciliation', 'route' => 'Reconciliation/index', 'icon' => 'fa-scale-balanced', 'blurb' => 'AR/AP/cash vs sub-ledgers'],
                    ['label' => 'Manual journals', 'route' => 'ManualJournal/index', 'icon' => 'fa-pen-to-square', 'blurb' => 'Adjusting entries'],
                ],
            ],
            [
                'title' => 'Money modules',
                'items' => [
                    ['label' => 'Customer payments', 'route' => 'CustomerTransaction/index', 'icon' => 'fa-hand-holding-dollar'],
                    ['label' => 'Supplier payments', 'route' => 'SupplierTransaction/index', 'icon' => 'fa-truck'],
                    ['label' => 'Employee transactions', 'route' => 'EmployeeTransaction/index', 'icon' => 'fa-user-tie'],
                    ['label' => 'Bank accounts', 'route' => 'bank', 'icon' => 'fa-building-columns'],
                ],
            ],
            [
                'title' => 'Vouchers',
                'items' => [
                    ['label' => 'Other income', 'route' => 'OtherIncome/index', 'icon' => 'fa-circle-plus'],
                    ['label' => 'Other expense', 'route' => 'OtherExpense/index', 'icon' => 'fa-circle-minus'],
                    ['label' => 'Money transfer', 'route' => 'MoneyTransfer/index', 'icon' => 'fa-right-left'],
                ],
            ],
            [
                'title' => 'Period & statements',
                'items' => [
                    ['label' => 'Period close', 'route' => 'AccountingPeriod/index', 'icon' => 'fa-lock'],
                    ['label' => 'Year-end checklist', 'route' => 'AccountingPeriod/year_end', 'icon' => 'fa-calendar-check'],
                    ['label' => 'Financial reports', 'route' => 'Report/index#cat-finance', 'icon' => 'fa-table-cells-large', 'blurb' => 'TB, P&L, BS, cash flow, aging'],
                    ['label' => 'All reports', 'route' => 'Report/index', 'icon' => 'fa-chart-bar', 'blurb' => 'GL audits, ops control reports'],
                ],
            ],
        ];
    }

    public static function isAccountingController(?string $controller): bool
    {
        return in_array($controller ?? '', self::ACCOUNTING_CONTROLLERS, true);
    }

    /**
     * @return array<int, array{label: string, url?: string}>
     */
    public static function breadcrumbTrail(?string $controller, ?string $action, ?string $pageTitle = null): array
    {
        $trail = [
            ['label' => 'Accounting', 'url' => self::hubUrl()],
        ];

        $routeKey = self::normalizeRouteKey($controller, $action);
        $labels = self::routeLabels();
        if (isset($labels[$routeKey])) {
            $trail[] = ['label' => $labels[$routeKey]['section'] ?? 'Module', 'url' => null];
            if (!empty($labels[$routeKey]['url'])) {
                $trail[] = ['label' => $labels[$routeKey]['label'], 'url' => $labels[$routeKey]['url']];
            } else {
                $trail[] = ['label' => $labels[$routeKey]['label'], 'url' => null];
            }
        } elseif ($pageTitle) {
            $trail[] = ['label' => $pageTitle, 'url' => null];
        }

        if ($pageTitle && ($trail[array_key_last($trail)]['label'] ?? '') !== $pageTitle) {
            $last = $trail[array_key_last($trail)];
            if (($last['url'] ?? null) !== null) {
                $trail[] = ['label' => $pageTitle, 'url' => null];
            }
        }

        return $trail;
    }

    public static function isLinkActive(string $route): bool
    {
        $route = strtolower(trim($route, '/'));
        $route = preg_replace('/#.*$/', '', $route);
        $current = self::currentPath();

        if ($route === $current) {
            return true;
        }

        if (!str_contains($route, '/') && str_starts_with($current, $route . '/')) {
            return true;
        }

        return false;
    }

    public static function isAccountingSectionActive(): bool
    {
        $current = self::currentPath();
        foreach (self::quickNavItems() as $item) {
            $route = preg_replace('/#.*$/', '', $item['route']);
            if (self::isLinkActive($route)) {
                return true;
            }
        }
        return str_starts_with($current, 'accounting/');
    }

    public static function linkHref(string $route): string
    {
        $base = defined('BASE_URL') ? BASE_URL : '/';
        if (str_contains($route, '#')) {
            [$path, $hash] = explode('#', $route, 2);
            return $base . $path . '#' . $hash;
        }
        return $base . $route;
    }

    private static function currentPath(): string
    {
        $currentPath = strtolower(trim(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '', '/'));
        $scriptName = strtolower(trim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/'));
        if ($scriptName && str_starts_with($currentPath, $scriptName)) {
            $currentPath = trim(substr($currentPath, strlen($scriptName)), '/');
        }
        $currentPath = preg_replace('#^public/?#', '', $currentPath);
        return trim($currentPath, '/');
    }

    /**
     * @return array<string, array{label: string, section?: string, url?: string}>
     */
    private static function routeLabels(): array
    {
        $base = defined('BASE_URL') ? BASE_URL : '/';
        return [
            'accounting/index'              => ['label' => 'Home', 'section' => 'Overview', 'url' => $base . 'Accounting/index'],
            'ledger/index'                  => ['label' => 'Chart of accounts', 'section' => 'Core GL', 'url' => $base . 'ledger'],
            'ledger/create'                 => ['label' => 'New ledger', 'section' => 'Core GL'],
            'ledger/edit'                   => ['label' => 'Edit ledger', 'section' => 'Core GL'],
            'ledger/show'                   => ['label' => 'Ledger hub', 'section' => 'Core GL'],
            'ledger/audit'                  => ['label' => 'CoA audit', 'section' => 'Core GL'],
            'manualjournal/index'           => ['label' => 'Manual journals', 'section' => 'Journals', 'url' => $base . 'ManualJournal/index'],
            'manualjournal/create'          => ['label' => 'New journal', 'section' => 'Journals'],
            'manualjournal/details'         => ['label' => 'Journal detail', 'section' => 'Journals'],
            'manualjournal/audit'           => ['label' => 'Journal audit', 'section' => 'Journals'],
            'reconciliation/index'          => ['label' => 'GL reconciliation', 'section' => 'Control', 'url' => $base . 'Reconciliation/index'],
            'accountingperiod/index'        => ['label' => 'Period close', 'section' => 'Period', 'url' => $base . 'AccountingPeriod/index'],
            'accountingperiod/year_end'     => ['label' => 'Year-end checklist', 'section' => 'Period', 'url' => $base . 'AccountingPeriod/year_end'],
            'customertransaction/index'     => ['label' => 'Customer payments', 'section' => 'Money', 'url' => $base . 'CustomerTransaction/index'],
            'customertransaction/create'    => ['label' => 'New receipt', 'section' => 'Money'],
            'customertransaction/details'     => ['label' => 'Payment detail', 'section' => 'Money'],
            'suppliertransaction/index'       => ['label' => 'Supplier payments', 'section' => 'Money', 'url' => $base . 'SupplierTransaction/index'],
            'suppliertransaction/create'    => ['label' => 'New payment', 'section' => 'Money'],
            'suppliertransaction/details'   => ['label' => 'Payment detail', 'section' => 'Money'],
            'employeetransaction/index'     => ['label' => 'Employee transactions', 'section' => 'Money', 'url' => $base . 'EmployeeTransaction/index'],
            'bank/index'                    => ['label' => 'Bank accounts', 'section' => 'Money', 'url' => $base . 'bank'],
            'otherincome/index'             => ['label' => 'Other income', 'section' => 'Vouchers', 'url' => $base . 'OtherIncome/index'],
            'otherexpense/index'            => ['label' => 'Other expense', 'section' => 'Vouchers', 'url' => $base . 'OtherExpense/index'],
            'moneytransfer/index'           => ['label' => 'Money transfer', 'section' => 'Vouchers', 'url' => $base . 'MoneyTransfer/index'],
        ];
    }

    private static function normalizeRouteKey(?string $controller, ?string $action): string
    {
        $controller = strtolower(str_replace('Controller', '', (string)$controller));
        $action = strtolower((string)($action ?: 'index'));
        return $controller . '/' . $action;
    }
}
