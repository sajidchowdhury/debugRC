<?php
// app/helpers/MasterDataAuditHelper.php — shared audit enrichment for branch/warehouse master data

require_once __DIR__ . '/../models/UserModel.php';

class MasterDataAuditHelper
{
    /** @var array<string, string> */
    public const BRANCH_FIELDS = [
        'branch_code' => 'Code',
        'branch_name' => 'Name',
        'phone'       => 'Phone',
        'email'       => 'Email',
        'address'     => 'Address',
        'is_active'   => 'Status',
    ];

    /** @var array<string, string> */
    public const WAREHOUSE_FIELDS = [
        'warehouse_code' => 'Code',
        'warehouse_name' => 'Name',
        'branch_id'      => 'Branch',
        'address'        => 'Address',
        'is_active'      => 'Status',
    ];

    /** @var array<string, string> */
    public const CUSTOMER_FIELDS = [
        'shop_name'       => 'Shop name',
        'customer_name'   => 'Contact',
        'mobile'          => 'Mobile',
        'address'         => 'Address',
        'sales_person_id' => 'Sales person',
        'credit_limit'    => 'Credit limit',
        'is_active'       => 'Status',
    ];

    /** @var array<string, string> */
    public const BANK_FIELDS = [
        'bank_name'      => 'Bank name',
        'account_number' => 'Account number',
        'branch_name'    => 'Branch',
        'is_active'      => 'Status',
    ];

    /** @var array<string, string> */
    public const SUPPLIER_FIELDS = [
        'supplier_name' => 'Name',
        'mobile'        => 'Mobile',
        'address'       => 'Address',
        'is_active'     => 'Status',
    ];

    /** @var array<string, string> */
    public const LEDGER_FIELDS = [
        'ledger_name'          => 'Name',
        'ledger_nature'        => 'Nature',
        'account_type'         => 'Type',
        'normal_balance'       => 'Normal balance',
        'is_control_account'   => 'Control account',
        'control_account_type' => 'Control type',
        'parent_id'            => 'Parent',
        'description'          => 'Description',
        'sort_order'           => 'Sort order',
        'is_active'            => 'Status',
    ];

    /**
     * @param array<int, array<string, mixed>> $logs
     * @return array<int, array<string, mixed>>
     */
    public static function enrichLogsWithUserNames(array $logs): array
    {
        if ($logs === []) {
            return $logs;
        }

        $userModel = new UserModel();

        foreach ($logs as &$log) {
            $uid = (int)($log['performed_by'] ?? 0);
            if ($uid > 0) {
                $user = $userModel->getUserById($uid);
                $log['performed_by_name'] = $user['username'] ?? ('User #' . $uid);
            } else {
                $log['performed_by_name'] = '—';
            }
        }
        unset($log);

        return $logs;
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @param array<string, string> $fieldLabels
     * @param array<string, string> $displayOverrides Optional map field => [from, to] display strings
     * @return array<string, mixed>
     */
    public static function buildUpdateDetails(
        array $before,
        array $after,
        array $fieldLabels,
        array $displayOverrides = []
    ): array {
        $codeKey = match (true) {
            isset($after['ledger_code']) => 'ledger_code',
            isset($after['supplier_code']) => 'supplier_code',
            isset($after['customer_code']) => 'customer_code',
            isset($after['branch_code']) => 'branch_code',
            isset($after['account_number']) => 'account_number',
            default => 'warehouse_code',
        };
        $nameKey = match (true) {
            isset($after['ledger_name']) && !isset($after['bank_name']) => 'ledger_name',
            isset($after['bank_name']) => 'bank_name',
            isset($after['supplier_name']) => 'supplier_name',
            isset($after['shop_name']) => 'shop_name',
            isset($after['branch_name']) && !isset($after['warehouse_name']) => 'branch_name',
            default => 'warehouse_name',
        };

        $details = [
            $codeKey => (string)($after[$codeKey] ?? $before[$codeKey] ?? ''),
            $nameKey => (string)($after[$nameKey] ?? $before[$nameKey] ?? ''),
        ];

        if (!empty($after['branch_name']) && $nameKey === 'warehouse_name') {
            $details['branch_name'] = (string)$after['branch_name'];
        }

        $changes = [];
        foreach ($fieldLabels as $field => $label) {
            $oldRaw = $before[$field] ?? null;
            $newRaw = $after[$field] ?? null;
            $old = self::formatValue($field, $oldRaw);
            $new = self::formatValue($field, $newRaw);

            if (isset($displayOverrides[$field])) {
                $old = $displayOverrides[$field]['from'] ?? $old;
                $new = $displayOverrides[$field]['to'] ?? $new;
            }

            if ($old !== $new) {
                $changes[] = [
                    'field' => $field,
                    'label' => $label,
                    'from'  => $old,
                    'to'    => $new,
                ];
            }
        }

        if ($changes !== []) {
            $details['changes'] = $changes;
        }

        return $details;
    }

    /**
     * @param array<string, mixed> $details
     */
    public static function renderDetailsHtml(array $details): string
    {
        if ($details === []) {
            return '<span class="text-muted">—</span>';
        }

        $parts = [];

        if (!empty($details['branch_code'])) {
            $parts[] = '<strong>Code:</strong> ' . htmlspecialchars((string)$details['branch_code'], ENT_QUOTES);
        }
        if (!empty($details['ledger_code'])) {
            $parts[] = '<strong>Code:</strong> ' . htmlspecialchars((string)$details['ledger_code'], ENT_QUOTES);
        }
        if (!empty($details['warehouse_code'])) {
            $parts[] = '<strong>Code:</strong> ' . htmlspecialchars((string)$details['warehouse_code'], ENT_QUOTES);
        }
        if (!empty($details['customer_code'])) {
            $parts[] = '<strong>Code:</strong> ' . htmlspecialchars((string)$details['customer_code'], ENT_QUOTES);
        }
        if (!empty($details['supplier_code'])) {
            $parts[] = '<strong>Code:</strong> ' . htmlspecialchars((string)$details['supplier_code'], ENT_QUOTES);
        }
        if (!empty($details['account_number']) && empty($details['customer_code']) && empty($details['supplier_code'])) {
            $parts[] = '<strong>Account:</strong> ' . htmlspecialchars((string)$details['account_number'], ENT_QUOTES);
        }
        if (!empty($details['bank_name']) && empty($details['shop_name']) && empty($details['supplier_name'])) {
            $parts[] = '<strong>Bank:</strong> ' . htmlspecialchars((string)$details['bank_name'], ENT_QUOTES);
        }
        if (!empty($details['supplier_name']) && empty($details['shop_name'])) {
            $parts[] = '<strong>Supplier:</strong> ' . htmlspecialchars((string)$details['supplier_name'], ENT_QUOTES);
        }
        if (!empty($details['shop_name'])) {
            $parts[] = '<strong>Shop:</strong> ' . htmlspecialchars((string)$details['shop_name'], ENT_QUOTES);
        }
        if (!empty($details['customer_name']) && empty($details['changes'])) {
            $parts[] = '<strong>Contact:</strong> ' . htmlspecialchars((string)$details['customer_name'], ENT_QUOTES);
        }
        if (!empty($details['mobile']) && empty($details['changes'])) {
            $parts[] = '<strong>Mobile:</strong> ' . htmlspecialchars((string)$details['mobile'], ENT_QUOTES);
        }
        if (!empty($details['branch_name']) && empty($details['warehouse_name']) && empty($details['ledger_name'])) {
            $parts[] = '<strong>Name:</strong> ' . htmlspecialchars((string)$details['branch_name'], ENT_QUOTES);
        }
        if (!empty($details['ledger_name'])) {
            $parts[] = '<strong>Ledger:</strong> ' . htmlspecialchars((string)$details['ledger_name'], ENT_QUOTES);
        }
        if (!empty($details['ledger_nature']) && empty($details['changes'])) {
            $parts[] = '<strong>Nature:</strong> ' . htmlspecialchars(str_replace('_', ' ', (string)$details['ledger_nature']), ENT_QUOTES);
        }
        if (!empty($details['account_type']) && empty($details['changes'])) {
            $parts[] = '<strong>Type:</strong> ' . htmlspecialchars((string)$details['account_type'], ENT_QUOTES);
        }
        if (!empty($details['normal_balance']) && empty($details['changes'])) {
            $parts[] = '<strong>Normal:</strong> ' . htmlspecialchars((string)$details['normal_balance'], ENT_QUOTES);
        }
        if (!empty($details['warehouse_name'])) {
            $parts[] = '<strong>Warehouse:</strong> ' . htmlspecialchars((string)$details['warehouse_name'], ENT_QUOTES);
        }
        if (!empty($details['branch_name']) && !empty($details['warehouse_name'])) {
            $parts[] = '<strong>Branch:</strong> ' . htmlspecialchars((string)$details['branch_name'], ENT_QUOTES);
        }
        if (!empty($details['phone'])) {
            $parts[] = '<strong>Phone:</strong> ' . htmlspecialchars((string)$details['phone'], ENT_QUOTES);
        }
        if (!empty($details['email'])) {
            $parts[] = '<strong>Email:</strong> ' . htmlspecialchars((string)$details['email'], ENT_QUOTES);
        }
        if (!empty($details['address']) && empty($details['changes'])) {
            $parts[] = '<strong>Address:</strong> ' . htmlspecialchars((string)$details['address'], ENT_QUOTES);
        }

        if (!empty($details['changes']) && is_array($details['changes'])) {
            foreach ($details['changes'] as $change) {
                if (!is_array($change)) {
                    continue;
                }
                $label = htmlspecialchars((string)($change['label'] ?? $change['field'] ?? 'Field'), ENT_QUOTES);
                $from = htmlspecialchars((string)($change['from'] ?? '—'), ENT_QUOTES);
                $to = htmlspecialchars((string)($change['to'] ?? '—'), ENT_QUOTES);
                $parts[] = '<strong>' . $label . ':</strong> ' . $from . ' → ' . $to;
            }
        }

        if (!empty($details['old_status']) && !empty($details['new_status'])) {
            $parts[] = '<strong>Status:</strong> '
                . htmlspecialchars((string)$details['old_status'], ENT_QUOTES)
                . ' → '
                . htmlspecialchars((string)$details['new_status'], ENT_QUOTES);
        } elseif (!empty($details['from']) && !empty($details['to']) && empty($details['changes'])) {
            $parts[] = '<strong>Status:</strong> '
                . htmlspecialchars((string)$details['from'], ENT_QUOTES)
                . ' → '
                . htmlspecialchars((string)$details['to'], ENT_QUOTES);
        } elseif (!empty($details['new_status'])) {
            $parts[] = '<strong>Status:</strong> ' . htmlspecialchars((string)$details['new_status'], ENT_QUOTES);
        }

        if ($parts === []) {
            $json = json_encode($details, JSON_UNESCAPED_UNICODE);
            return '<span class="branch-audit-details">' . htmlspecialchars($json ?: '', ENT_QUOTES) . '</span>';
        }

        return '<div class="branch-audit-details">' . implode(' · ', $parts) . '</div>';
    }

    private static function formatValue(string $field, mixed $value): string
    {
        if ($field === 'is_active') {
            return (!empty($value) || $value === 1 || $value === '1') ? 'active' : 'inactive';
        }

        if ($field === 'credit_limit') {
            return number_format((float)($value ?? 0), 2);
        }

        $text = trim((string)($value ?? ''));

        return $text === '' ? '—' : $text;
    }
}
