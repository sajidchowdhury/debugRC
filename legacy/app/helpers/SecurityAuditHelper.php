<?php
// app/helpers/SecurityAuditHelper.php — labels and rendering for unified security audit UI

class SecurityAuditHelper
{
    public const SOURCE_LOGIN = 'login';
    public const SOURCE_USER = 'user';
    public const SOURCE_EMPLOYEE = 'employee';

    /** @return array<string, string> */
    public static function sourceOptions(): array
    {
        return [
            'all'      => 'All sources',
            self::SOURCE_LOGIN    => 'Login attempts',
            self::SOURCE_USER     => 'User accounts',
            self::SOURCE_EMPLOYEE => 'Employees',
        ];
    }

    /** @return array<string, string> */
    public static function outcomeOptions(): array
    {
        return [
            'all'     => 'All outcomes',
            'success' => 'Success only',
            'failure' => 'Failed only',
        ];
    }

    public static function sourceLabel(string $source): string
    {
        return match ($source) {
            self::SOURCE_LOGIN => 'Login',
            self::SOURCE_USER => 'User',
            self::SOURCE_EMPLOYEE => 'Employee',
            default => ucfirst($source),
        };
    }

    public static function sourceChipClass(string $source): string
    {
        return match ($source) {
            self::SOURCE_LOGIN => 'login',
            self::SOURCE_USER => 'user',
            self::SOURCE_EMPLOYEE => 'employee',
            default => 'other',
        };
    }

    public static function actionClass(string $source, string $action, bool $loginSuccess = false): string
    {
        if ($source === self::SOURCE_LOGIN) {
            if ($loginSuccess) {
                return 'created';
            }
            if (str_contains($action, 'rate_limited') || str_contains($action, 'locked')) {
                return 'status';
            }
            return 'other';
        }

        if (str_contains($action, 'created')) {
            return 'created';
        }
        if (str_contains($action, 'updated') || str_contains($action, 'password')) {
            return 'updated';
        }
        if (str_contains($action, 'permission') || str_contains($action, '2fa')
            || str_contains($action, 'status') || str_contains($action, 'deleted')
            || str_contains($action, 'restored')) {
            return 'status';
        }

        return 'other';
    }

    public static function actionLabel(string $source, string $action, bool $loginSuccess = false): string
    {
        if ($source === self::SOURCE_LOGIN) {
            return match (true) {
                $loginSuccess => 'Login success',
                str_contains($action, 'rate_limited') => 'Rate limited',
                str_contains($action, 'account_locked'), str_contains($action, 'locked') => 'Account locked',
                str_contains($action, 'awaiting_2fa') => 'Awaiting 2FA',
                str_contains($action, 'invalid_credentials') => 'Invalid credentials',
                default => 'Login failed',
            };
        }

        if ($source === self::SOURCE_USER) {
            return match (true) {
                str_contains($action, 'user_created') => 'User created',
                str_contains($action, 'user_updated') => 'User updated',
                str_contains($action, 'user_status_changed') => 'Status changed',
                str_contains($action, 'permissions_updated') => 'Permissions updated',
                str_contains($action, 'user_2fa_enabled') => '2FA enabled',
                str_contains($action, 'user_2fa_disabled') => '2FA disabled',
                str_contains($action, 'user_2fa_admin_disabled') => '2FA admin recovery',
                str_contains($action, 'soft_deleted') => 'User deleted',
                str_contains($action, 'restored') => 'User restored',
                str_contains($action, 'password') => 'Password change',
                default => $action,
            };
        }

        return match (true) {
            str_contains($action, 'employee_created') => 'Employee created',
            str_contains($action, 'employee_updated') => 'Employee updated',
            str_contains($action, 'employee_status_changed') => 'Status changed',
            str_contains($action, 'soft_deleted') => 'Employee deleted',
            str_contains($action, 'restored') => 'Employee restored',
            default => $action,
        };
    }

    /**
     * @param array<string, mixed> $details
     */
    public static function renderDetailsHtml(string $source, array $details, array $extra = []): string
    {
        if ($source === self::SOURCE_LOGIN) {
            $parts = [];
            $reason = trim((string)($extra['reason'] ?? $details['reason'] ?? ''));
            if ($reason !== '' && $reason !== 'success') {
                $parts[] = '<strong>Reason:</strong> ' . htmlspecialchars($reason, ENT_QUOTES);
            }
            if (!empty($extra['rate_limited'])) {
                $parts[] = '<strong>Rate limited</strong>';
            }
            $ua = trim((string)($extra['user_agent'] ?? $details['user_agent'] ?? ''));
            if ($ua !== '') {
                $parts[] = '<span class="text-muted" title="' . htmlspecialchars($ua, ENT_QUOTES) . '">'
                    . htmlspecialchars(mb_substr($ua, 0, 48) . (mb_strlen($ua) > 48 ? '…' : ''), ENT_QUOTES)
                    . '</span>';
            }
            if ($parts === []) {
                return '<span class="text-muted">—</span>';
            }
            return '<div class="branch-audit-details">' . implode(' · ', $parts) . '</div>';
        }

        if (empty($details) || !is_array($details)) {
            return '<span class="text-muted">—</span>';
        }

        $parts = [];
        foreach (['username', 'name', 'menu_count', 'new_status'] as $key) {
            if (!isset($details[$key]) || $details[$key] === '') {
                continue;
            }
            $label = match ($key) {
                'username' => 'User',
                'name' => 'Name',
                'menu_count' => 'Menus',
                'new_status' => 'Status',
                default => ucfirst($key),
            };
            $value = $details[$key];
            if ($key === 'new_status') {
                $value = $value === 'active' || $value === true || $value === 1 ? 'active' : 'inactive';
            }
            $parts[] = '<strong>' . $label . ':</strong> ' . htmlspecialchars((string)$value, ENT_QUOTES);
        }

        if (!empty($details['changes']) && is_array($details['changes'])) {
            $changeBits = [];
            foreach ($details['changes'] as $field => $change) {
                if (!is_array($change)) {
                    continue;
                }
                $from = htmlspecialchars((string)($change['from'] ?? '—'), ENT_QUOTES);
                $to = htmlspecialchars((string)($change['to'] ?? '—'), ENT_QUOTES);
                $label = htmlspecialchars((string)($change['label'] ?? $field), ENT_QUOTES);
                $changeBits[] = "{$label}: {$from} → {$to}";
            }
            if ($changeBits !== []) {
                $parts[] = implode(' · ', $changeBits);
            }
        }

        if ($parts === []) {
            $json = json_encode($details, JSON_UNESCAPED_UNICODE);
            return '<span class="branch-audit-details">' . htmlspecialchars((string)$json, ENT_QUOTES) . '</span>';
        }

        return '<div class="branch-audit-details">' . implode(' · ', $parts) . '</div>';
    }
}
