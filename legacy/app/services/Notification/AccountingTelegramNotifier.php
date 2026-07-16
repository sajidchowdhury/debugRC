<?php
// app/services/Notification/AccountingTelegramNotifier.php
// Scheduled accounting alerts — GL reconciliation, audit failures (Phase 4C).

require_once __DIR__ . '/TelegramNotificationService.php';
require_once __DIR__ . '/../../models/NotificationModel.php';
require_once __DIR__ . '/../../../core/Telegram.php';
require_once __DIR__ . '/../../../core/Logger.php';

class AccountingTelegramNotifier
{
    private TelegramNotificationService $telegram;
    private NotificationModel $notifications;

    public function __construct(
        ?TelegramNotificationService $telegram = null,
        ?NotificationModel $notifications = null
    ) {
        $this->telegram = $telegram ?? new TelegramNotificationService();
        $this->notifications = $notifications ?? new NotificationModel();
    }

    /**
     * Run notifier without affecting the parent cron job exit code.
     */
    public static function safe(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $e) {
            error_log('Accounting Telegram notification failed: ' . $e->getMessage());
        }
    }

    /**
     * GL reconciliation cron found issues — notify admin + accountant (one message per run).
     *
     * @param array<int, array<string, mixed>> $reports Branch-scoped reports with has_issues = true
     * @return array{sent: int, skipped_no_chat: int, failed: int, errors: string[]}
     */
    public function notifyReconciliationIssues(array $reports): array
    {
        $reports = array_values(array_filter($reports, static fn(array $r): bool => !empty($r['has_issues'])));
        if ($reports === []) {
            return $this->emptySummary();
        }

        $recipients = $this->notifications->getUsersForTelegramByRoles(['admin', 'accountant']);
        $message = $this->buildReconciliationIssuesMessage($reports);

        return $this->telegram->deliver($recipients, $message, TelegramNotificationService::ALERT_RECON_ISSUE, [
            'branch_count' => count($reports),
            'issue_count'  => $this->countIssues($reports),
        ]);
    }

    /**
     * @param array<int, array<string, mixed>> $reports
     */
    private function buildReconciliationIssuesMessage(array $reports): string
    {
        $first = $reports[0];
        $fromDate = Telegram::escapeHtml((string)($first['from_date'] ?? date('Y-m-01')));
        $toDate = Telegram::escapeHtml((string)($first['to_date'] ?? date('Y-m-d')));
        $reconcileUrl = $this->reconcileUrl();

        $lines = [
            '<b>⚠️ GL Reconciliation Issues</b>',
            '',
            '<b>Period:</b> ' . $fromDate . ' → ' . $toDate,
            '<b>Branches affected:</b> ' . count($reports),
            '',
        ];

        foreach ($reports as $report) {
            $branchName = Telegram::escapeHtml((string)($report['branch_name'] ?? ('Branch ' . ($report['branch_id'] ?? '?'))));
            $lines[] = '<b>' . $branchName . '</b>';

            foreach ($report['issues'] ?? [] as $issue) {
                $lines[] = '• ' . Telegram::escapeHtml((string)$issue);
            }

            $ar = $report['ar'] ?? [];
            $ap = $report['ap'] ?? [];
            $emp = $report['employee'] ?? [];
            $cash = $report['cash_bank'] ?? [];
            $inv = $report['inventory'] ?? [];
            $cogs = $report['cogs'] ?? [];
            $lines[] = sprintf(
                '  AR diff: %s · AP diff: %s · Emp diff: %s · Cash diff: %s · Inv diff: %s · COGS diff: %s',
                Telegram::escapeHtml(number_format((float)($ar['difference'] ?? 0), 2)),
                Telegram::escapeHtml(number_format((float)($ap['difference'] ?? 0), 2)),
                Telegram::escapeHtml(number_format((float)($emp['difference'] ?? 0), 2)),
                Telegram::escapeHtml(number_format((float)($cash['difference'] ?? 0), 2)),
                Telegram::escapeHtml(number_format((float)($inv['difference'] ?? 0), 2)),
                Telegram::escapeHtml(number_format((float)($cogs['difference'] ?? 0), 2))
            );
            $lines[] = '';
        }

        if ($reconcileUrl !== '') {
            $lines[] = '<a href="' . Telegram::escapeHtml($reconcileUrl) . '">Open GL reconciliation</a>';
        }

        return implode("\n", $lines);
    }

    private function reconcileUrl(): string
    {
        $publicBase = defined('PUBLIC_URL') ? rtrim(PUBLIC_URL, '/') . '/' : rtrim(BASE_URL, '/') . '/';

        return $publicBase . 'Reconciliation/index';
    }

    /**
     * @param array<int, array<string, mixed>> $reports
     */
    private function countIssues(array $reports): int
    {
        $count = 0;
        foreach ($reports as $report) {
            $count += count($report['issues'] ?? []);
        }

        return $count;
    }

    /**
     * @return array{sent: int, skipped_no_chat: int, failed: int, errors: string[]}
     */
    private function emptySummary(): array
    {
        return [
            'sent'            => 0,
            'skipped_no_chat' => 0,
            'failed'          => 0,
            'errors'          => [],
        ];
    }
}
