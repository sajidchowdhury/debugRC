<?php
// app/services/Security/SecurityAuditService.php — merge user, employee, and login audit streams

require_once __DIR__ . '/../../../core/UserAudit.php';
require_once __DIR__ . '/../../../core/LoginAudit.php';
require_once __DIR__ . '/../../helpers/SecurityAuditHelper.php';

class SecurityAuditService
{
    private UserAudit $userAudit;
    private LoginAudit $loginAudit;

    public function __construct()
    {
        $this->userAudit = new UserAudit();
        $this->loginAudit = new LoginAudit();
    }

    /**
     * @param array{source?: string, outcome?: string, q?: string, limit?: int} $filters
     * @return array{entries: array<int, array<string, mixed>>, stats: array<string, int>}
     */
    public function getUnifiedAudit(array $filters = []): array
    {
        $source = strtolower(trim((string)($filters['source'] ?? 'all')));
        $outcome = strtolower(trim((string)($filters['outcome'] ?? 'all')));
        $query = strtolower(trim((string)($filters['q'] ?? '')));
        $limit = max(50, min(500, (int)($filters['limit'] ?? 300)));

        $perSourceLimit = $source === 'all' ? $limit : $limit;
        $entries = [];

        if ($source === 'all' || $source === SecurityAuditHelper::SOURCE_USER) {
            $logs = $this->userAudit->enrichWithPerformerNames(
                $this->userAudit->getRecentLogs($perSourceLimit, 'user_')
            );
            foreach ($logs as $log) {
                $entries[] = $this->normalizeUserRow($log);
            }
        }

        if ($source === 'all' || $source === SecurityAuditHelper::SOURCE_EMPLOYEE) {
            $logs = $this->userAudit->enrichWithPerformerNames(
                $this->userAudit->getRecentLogs($perSourceLimit, 'employee_')
            );
            foreach ($logs as $log) {
                $entries[] = $this->normalizeEmployeeRow($log);
            }
        }

        if ($source === 'all' || $source === SecurityAuditHelper::SOURCE_LOGIN) {
            foreach ($this->loginAudit->getRecentAttempts($perSourceLimit) as $log) {
                $entries[] = $this->normalizeLoginRow($log);
            }
        }

        if ($outcome !== 'all') {
            $entries = array_values(array_filter($entries, function (array $row) use ($outcome): bool {
                if (($row['source'] ?? '') !== SecurityAuditHelper::SOURCE_LOGIN) {
                    return true;
                }
                $success = !empty($row['login_success']);
                return $outcome === 'success' ? $success : !$success;
            }));
        }

        if ($query !== '') {
            $entries = array_values(array_filter($entries, function (array $row) use ($query): bool {
                $haystack = strtolower(implode(' ', [
                    (string)($row['timestamp'] ?? ''),
                    (string)($row['source'] ?? ''),
                    (string)($row['performed_by_label'] ?? ''),
                    (string)($row['action'] ?? ''),
                    (string)($row['action_label'] ?? ''),
                    (string)($row['target_label'] ?? ''),
                    (string)($row['ip'] ?? ''),
                    json_encode($row['details'] ?? [], JSON_UNESCAPED_UNICODE),
                ]));
                return str_contains($haystack, $query);
            }));
        }

        usort($entries, static function (array $a, array $b): int {
            return strcmp((string)($b['timestamp'] ?? ''), (string)($a['timestamp'] ?? ''));
        });

        $entries = array_slice($entries, 0, $limit);
        $stats = $this->buildStats($entries);

        return [
            'entries' => $entries,
            'stats'   => $stats,
        ];
    }

    /**
     * @param array<string, mixed> $log
     * @return array<string, mixed>
     */
    private function normalizeUserRow(array $log): array
    {
        $action = (string)($log['action'] ?? '');
        $targetId = (int)($log['target_user_id'] ?? 0);
        $details = is_array($log['details'] ?? null) ? $log['details'] : [];

        return [
            'timestamp'           => (string)($log['timestamp'] ?? ''),
            'source'              => SecurityAuditHelper::SOURCE_USER,
            'performed_by'        => (int)($log['performed_by'] ?? 0),
            'performed_by_label'  => UserAudit::performerLabel($log),
            'action'              => $action,
            'action_label'        => SecurityAuditHelper::actionLabel(SecurityAuditHelper::SOURCE_USER, $action),
            'action_class'        => SecurityAuditHelper::actionClass(SecurityAuditHelper::SOURCE_USER, $action),
            'target_id'           => $targetId,
            'target_label'        => $targetId > 0 ? '#' . $targetId : '—',
            'target_url'          => $targetId > 0 ? 'user/edit/' . $targetId : null,
            'details'             => $details,
            'details_html'        => SecurityAuditHelper::renderDetailsHtml(SecurityAuditHelper::SOURCE_USER, $details),
            'ip'                  => (string)($log['ip'] ?? 'unknown'),
            'login_success'       => false,
        ];
    }

    /**
     * @param array<string, mixed> $log
     * @return array<string, mixed>
     */
    private function normalizeEmployeeRow(array $log): array
    {
        $action = (string)($log['action'] ?? '');
        $targetId = (int)($log['target_user_id'] ?? 0);
        $details = is_array($log['details'] ?? null) ? $log['details'] : [];

        return [
            'timestamp'           => (string)($log['timestamp'] ?? ''),
            'source'              => SecurityAuditHelper::SOURCE_EMPLOYEE,
            'performed_by'        => (int)($log['performed_by'] ?? 0),
            'performed_by_label'  => UserAudit::performerLabel($log),
            'action'              => $action,
            'action_label'        => SecurityAuditHelper::actionLabel(SecurityAuditHelper::SOURCE_EMPLOYEE, $action),
            'action_class'        => SecurityAuditHelper::actionClass(SecurityAuditHelper::SOURCE_EMPLOYEE, $action),
            'target_id'           => $targetId,
            'target_label'        => $targetId > 0 ? '#' . $targetId : '—',
            'target_url'          => $targetId > 0 ? 'employee/account/' . $targetId : null,
            'details'             => $details,
            'details_html'        => SecurityAuditHelper::renderDetailsHtml(SecurityAuditHelper::SOURCE_EMPLOYEE, $details),
            'ip'                  => (string)($log['ip'] ?? 'unknown'),
            'login_success'       => false,
        ];
    }

    /**
     * @param array<string, mixed> $log
     * @return array<string, mixed>
     */
    private function normalizeLoginRow(array $log): array
    {
        $username = (string)($log['username'] ?? '');
        $success = !empty($log['success']);
        $reason = (string)($log['reason'] ?? ($success ? 'success' : 'invalid_credentials'));
        $action = $success ? 'login_success' : 'login_' . preg_replace('/[^a-z0-9_]+/', '_', strtolower($reason));

        return [
            'timestamp'           => (string)($log['timestamp'] ?? ''),
            'source'              => SecurityAuditHelper::SOURCE_LOGIN,
            'performed_by'        => 0,
            'performed_by_label'  => $username !== '' ? $username : '—',
            'action'              => $action,
            'action_label'        => SecurityAuditHelper::actionLabel(SecurityAuditHelper::SOURCE_LOGIN, $action, $success),
            'action_class'        => SecurityAuditHelper::actionClass(SecurityAuditHelper::SOURCE_LOGIN, $action, $success),
            'target_id'           => $username,
            'target_label'        => $username !== '' ? $username : '—',
            'target_url'          => null,
            'details'             => [],
            'details_html'        => SecurityAuditHelper::renderDetailsHtml(
                SecurityAuditHelper::SOURCE_LOGIN,
                [],
                [
                    'reason'        => $reason,
                    'rate_limited'  => !empty($log['rate_limited']),
                    'user_agent'    => (string)($log['user_agent'] ?? ''),
                ]
            ),
            'ip'                  => (string)($log['ip'] ?? 'unknown'),
            'login_success'       => $success,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     * @return array<string, int>
     */
    private function buildStats(array $entries): array
    {
        $stats = [
            'total'            => count($entries),
            'login'            => 0,
            'login_success'    => 0,
            'login_failure'    => 0,
            'user'             => 0,
            'employee'         => 0,
        ];

        foreach ($entries as $row) {
            $source = (string)($row['source'] ?? '');
            if ($source === SecurityAuditHelper::SOURCE_LOGIN) {
                $stats['login']++;
                if (!empty($row['login_success'])) {
                    $stats['login_success']++;
                } else {
                    $stats['login_failure']++;
                }
            } elseif ($source === SecurityAuditHelper::SOURCE_USER) {
                $stats['user']++;
            } elseif ($source === SecurityAuditHelper::SOURCE_EMPLOYEE) {
                $stats['employee']++;
            }
        }

        return $stats;
    }
}
