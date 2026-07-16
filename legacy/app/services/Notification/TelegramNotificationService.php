<?php
// app/services/Notification/TelegramNotificationService.php
// High-level Telegram alerts — reusable across modules (challan, stock, reconciliation, etc.).

require_once __DIR__ . '/../../models/NotificationModel.php';
require_once __DIR__ . '/../../../core/Telegram.php';
require_once __DIR__ . '/../../../core/Logger.php';

class TelegramNotificationService
{
    public const ALERT_CHALLAN_FINALIZED = 'challan_finalized';
    public const ALERT_SALES_INVOICE_CREATED = 'sales_invoice_created';
    public const ALERT_SALES_CHALLAN_CREATED = 'sales_challan_created';
    public const ALERT_SALES_RETURN_CREATED = 'sales_return_created';
    public const ALERT_SALES_RETURN_RECEIVED = 'sales_return_received';
    public const ALERT_SALES_PAYMENT_RECEIVED = 'sales_payment_received';
    public const ALERT_LOW_STOCK = 'low_stock';
    public const ALERT_RECON_ISSUE = 'reconciliation_issue';
    public const ALERT_PAYMENT_REMINDER = 'payment_reminder';

    private NotificationModel $notifications;

    public function __construct(?NotificationModel $notifications = null)
    {
        $this->notifications = $notifications ?? new NotificationModel();
    }

    /**
     * Send a prepared HTML message to a merged recipient list (deduped by user id).
     *
     * @param array<int, array<string, mixed>> $recipients
     * @return array{sent: int, skipped_no_chat: int, failed: int, errors: string[]}
     */
    public function deliver(array $recipients, string $message, string $alertType, array $context = []): array
    {
        return $this->deliverToRecipients($recipients, $message, $alertType, $context);
    }

    /**
     * Notify branch warehouse managers when a sales challan is finalized.
     *
     * @return array{sent: int, skipped_no_chat: int, failed: int, errors: string[]}
     * @deprecated Prefer SalesTelegramNotifier::notifyChallanCreated() for full recipient rules.
     */
    public function notifyChallanFinalized(array $payload): array
    {
        $branchId = (int)($payload['branch_id'] ?? 0);
        if ($branchId <= 0) {
            Logger::warning('Challan Telegram skipped: missing branch_id', $payload);

            return $this->emptySummary(['Branch id is required.']);
        }

        $message = $this->buildChallanFinalizedMessage($payload);
        $recipients = $this->notifications->getBranchUsersForTelegram('warehouse_manager', $branchId);

        return $this->deliverToRecipients($recipients, $message, self::ALERT_CHALLAN_FINALIZED, [
            'challan_id'   => (int)($payload['challan_id'] ?? 0),
            'invoice_id'   => (int)($payload['invoice_id'] ?? 0),
            'challan_code' => (string)($payload['challan_code'] ?? ''),
            'branch_id'    => $branchId,
        ]);
    }

    /**
     * Generic alert — use for future modules (low stock, reconciliation, reminders).
     *
     * @param int[] $userIds
     * @return array{sent: int, skipped_no_chat: int, failed: int, errors: string[]}
     */
    public function sendAlertToUserIds(array $userIds, string $message, string $alertType, array $context = []): array
    {
        $recipients = $this->notifications->getUsersTelegramProfilesByIds($userIds);

        return $this->deliverToRecipients($recipients, $message, $alertType, $context);
    }

    /**
     * Notify all users with a designation in a branch who have telegram_user_id set.
     *
     * @return array{sent: int, skipped_no_chat: int, failed: int, errors: string[]}
     */
    public function sendAlertToBranchRole(
        int $branchId,
        string $designation,
        string $message,
        string $alertType,
        array $context = []
    ): array {
        if ($branchId <= 0) {
            return $this->emptySummary(['Branch id is required.']);
        }

        $recipients = $this->notifications->getBranchUsersForTelegram($designation, $branchId);

        return $this->deliverToRecipients($recipients, $message, $alertType, $context);
    }

    /**
     * @param array<int, array<string, mixed>> $recipients
     * @return array{sent: int, skipped_no_chat: int, failed: int, errors: string[]}
     */
    private function deliverToRecipients(array $recipients, string $message, string $alertType, array $context): array
    {
        $summary = [
            'sent'             => 0,
            'skipped_no_chat'  => 0,
            'failed'           => 0,
            'errors'           => [],
        ];

        if ($recipients === []) {
            Logger::info('Telegram alert: no eligible recipients', array_merge($context, [
                'alert_type' => $alertType,
            ]));

            return $summary;
        }

        if (!Telegram::isConfigured()) {
            Logger::info('Telegram alert skipped: bot not configured', array_merge($context, [
                'alert_type' => $alertType,
            ]));

            return $summary;
        }

        foreach ($recipients as $user) {
            $userId = (int)($user['id'] ?? 0);
            $chatId = $user['telegram_user_id'] ?? null;

            if ($userId <= 0) {
                continue;
            }

            if ($chatId === null || $chatId === '' || (int)$chatId === 0) {
                $summary['skipped_no_chat']++;
                Logger::info('Telegram alert skipped: user has no telegram_user_id', array_merge($context, [
                    'alert_type' => $alertType,
                    'user_id'    => $userId,
                    'username'   => (string)($user['username'] ?? ''),
                ]));
                continue;
            }

            $result = Telegram::sendMessage((int)$chatId, $message, [
                'parse_mode'                => 'HTML',
                'disable_web_page_preview'  => false,
            ]);

            if (!empty($result['ok'])) {
                $summary['sent']++;
                continue;
            }

            if (!empty($result['skipped'])) {
                continue;
            }

            $summary['failed']++;
            $summary['errors'][] = sprintf(
                'User #%d (%s): %s',
                $userId,
                (string)($user['username'] ?? ''),
                (string)($result['error'] ?? 'Send failed')
            );
        }

        if ($summary['failed'] > 0) {
            Logger::warning('Telegram alert partial failure', array_merge($context, [
                'alert_type' => $alertType,
                'summary'    => $summary,
            ]));
        }

        return $summary;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function buildChallanFinalizedMessage(array $payload): string
    {
        $challanCode = Telegram::escapeHtml((string)($payload['challan_code'] ?? '—'));
        $customer = Telegram::escapeHtml((string)($payload['customer_name'] ?? '—'));
        $branch = Telegram::escapeHtml((string)($payload['branch_name'] ?? '—'));
        $warehouse = Telegram::escapeHtml((string)($payload['warehouse_label'] ?? '—'));
        $itemCount = (int)($payload['item_count'] ?? 0);
        $total = number_format((float)($payload['total_amount'] ?? 0), 2);
        $when = Telegram::escapeHtml((string)($payload['formatted_at'] ?? date('d M Y, h:i A')));
        $viewUrl = trim((string)($payload['view_url'] ?? ''));

        $lines = [
            '<b>📦 New Sales Challan Finalized</b>',
            '',
            '<b>Challan:</b> ' . $challanCode,
            '<b>Customer:</b> ' . $customer,
            '<b>Items:</b> ' . $itemCount,
            '<b>Total:</b> Tk ' . $total,
            '<b>Branch:</b> ' . $branch,
            '<b>Warehouse:</b> ' . $warehouse,
            '<b>Date &amp; time:</b> ' . $when,
        ];

        if ($viewUrl !== '') {
            $safeUrl = Telegram::escapeHtml($viewUrl);
            $lines[] = '';
            $lines[] = '<a href="' . $safeUrl . '">View challan</a>';
        }

        return implode("\n", $lines);
    }

    /**
     * @param string[] $errors
     * @return array{sent: int, skipped_no_chat: int, failed: int, errors: string[]}
     */
    private function emptySummary(array $errors = []): array
    {
        return [
            'sent'            => 0,
            'skipped_no_chat' => 0,
            'failed'          => count($errors),
            'errors'          => $errors,
        ];
    }
}
