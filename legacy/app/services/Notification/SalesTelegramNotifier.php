<?php
// app/services/Notification/SalesTelegramNotifier.php
// Sales-module Telegram alerts — recipient rules per business event.

require_once __DIR__ . '/TelegramNotificationService.php';
require_once __DIR__ . '/../../models/NotificationModel.php';
require_once __DIR__ . '/../../models/ChallanModel.php';
require_once __DIR__ . '/../../../core/Telegram.php';
require_once __DIR__ . '/../../../core/Logger.php';
require_once __DIR__ . '/../../../core/Database.php';

class SalesTelegramNotifier
{
    private TelegramNotificationService $telegram;
    private NotificationModel $notifications;
    private Database $db;

    public function __construct(
        ?TelegramNotificationService $telegram = null,
        ?NotificationModel $notifications = null,
        ?Database $db = null
    ) {
        $this->telegram = $telegram ?? new TelegramNotificationService();
        $this->notifications = $notifications ?? new NotificationModel();
        $this->db = $db ?? new Database();
    }

    /**
     * Run notifier without affecting the parent transaction/response.
     */
    public static function safe(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $e) {
            error_log('Sales Telegram notification failed: ' . $e->getMessage());
        }
    }

    /** 1. New sales invoice → branch warehouse managers. */
    public function notifyInvoiceCreated(int $invoiceId): array
    {
        $ctx = $this->fetchInvoiceContext($invoiceId);
        if (!$ctx) {
            return $this->emptySummary();
        }

        $branchId = (int)$ctx['branch_id'];
        $recipients = $this->notifications->getBranchUsersForTelegram('warehouse_manager', $branchId);
        $message = $this->buildInvoiceCreatedMessage($ctx);

        return $this->telegram->deliver($recipients, $message, TelegramNotificationService::ALERT_SALES_INVOICE_CREATED, [
            'invoice_id'   => $invoiceId,
            'invoice_code' => (string)($ctx['invoice_code'] ?? ''),
            'branch_id'    => $branchId,
        ]);
    }

    /**
     * 2. Challan finalized → invoice salesman, sales-by employee, branch warehouse managers.
     *
     * @param array<string, mixed> $payload From ChallanModel::getChallanTelegramPayload()
     */
    public function notifyChallanCreated(array $payload): array
    {
        $branchId = (int)($payload['branch_id'] ?? 0);
        if ($branchId <= 0) {
            return $this->emptySummary();
        }

        $employeeIds = array_filter([
            (int)($payload['salesman_id'] ?? 0),
            (int)($payload['sales_person'] ?? 0),
        ], static fn($id) => $id > 0);

        $recipients = $this->notifications->mergeTelegramRecipients(
            $this->notifications->getBranchUsersForTelegram('warehouse_manager', $branchId),
            $this->notifications->getUsersByEmployeeIds($employeeIds)
        );

        $message = $this->buildChallanCreatedMessage($payload);

        return $this->telegram->deliver($recipients, $message, TelegramNotificationService::ALERT_SALES_CHALLAN_CREATED, [
            'challan_id'   => (int)($payload['challan_id'] ?? 0),
            'invoice_id'   => (int)($payload['invoice_id'] ?? 0),
            'challan_code' => (string)($payload['challan_code'] ?? ''),
            'branch_id'    => $branchId,
        ]);
    }

    /** 3. Sales return created (pending) → all admins. */
    public function notifyReturnCreated(int $returnId): array
    {
        $ctx = $this->fetchReturnContext($returnId);
        if (!$ctx) {
            return $this->emptySummary();
        }

        $recipients = $this->notifications->getUsersForTelegramByRoles(['admin']);
        $message = $this->buildReturnCreatedMessage($ctx);

        return $this->telegram->deliver($recipients, $message, TelegramNotificationService::ALERT_SALES_RETURN_CREATED, [
            'return_id'   => $returnId,
            'return_code' => (string)($ctx['return_code'] ?? ''),
        ]);
    }

    /**
     * 4. Sales return received (warehouse confirm) → admin, branch warehouse managers, receiver.
     */
    public function notifyReturnReceived(int $returnId, int $confirmedByUserId): array
    {
        $ctx = $this->fetchReturnContext($returnId);
        if (!$ctx) {
            return $this->emptySummary();
        }

        $branchId = (int)($ctx['branch_id'] ?? 0);
        $receiver = $confirmedByUserId > 0
            ? $this->notifications->getUsersTelegramProfilesByIds([$confirmedByUserId])
            : [];

        $recipients = $this->notifications->mergeTelegramRecipients(
            $this->notifications->getUsersForTelegramByRoles(['admin']),
            $branchId > 0 ? $this->notifications->getBranchUsersForTelegram('warehouse_manager', $branchId) : [],
            $receiver
        );

        $message = $this->buildReturnReceivedMessage($ctx, $confirmedByUserId);

        return $this->telegram->deliver($recipients, $message, TelegramNotificationService::ALERT_SALES_RETURN_RECEIVED, [
            'return_id'   => $returnId,
            'return_code' => (string)($ctx['return_code'] ?? ''),
            'branch_id'   => $branchId,
        ]);
    }

    /**
     * 5. Payment received against a today-dated invoice → admin + accountants.
     */
    public function notifyTodayInvoicePayment(int $paymentId, int $invoiceId): array
    {
        if ($paymentId <= 0 || $invoiceId <= 0) {
            return $this->emptySummary();
        }

        $ctx = $this->fetchPaymentContext($paymentId, $invoiceId);
        if (!$ctx || empty($ctx['is_today_invoice'])) {
            return $this->emptySummary();
        }

        $recipients = $this->notifications->getUsersForTelegramByRoles(['admin', 'accountant']);
        $message = $this->buildPaymentReceivedMessage($ctx);

        return $this->telegram->deliver($recipients, $message, TelegramNotificationService::ALERT_SALES_PAYMENT_RECEIVED, [
            'payment_id'   => $paymentId,
            'payment_code' => (string)($ctx['payment_code'] ?? ''),
            'invoice_id'   => $invoiceId,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchInvoiceContext(int $invoiceId): ?array
    {
        $this->db->query("
            SELECT
                si.id,
                si.invoice_code,
                si.invoice_date,
                si.total_amount,
                si.branch_id,
                si.salesman_id,
                si.sales_person,
                COALESCE(NULLIF(TRIM(c.shop_name), ''), NULLIF(TRIM(c.customer_name), ''), 'Customer') AS customer_name,
                b.branch_name,
                (SELECT COUNT(*) FROM sales_invoice_items sii WHERE sii.sales_invoice_id = si.id) AS item_count
            FROM sales_invoices si
            JOIN customers c ON c.id = si.customer_id
            JOIN branches b ON b.id = si.branch_id
            WHERE si.id = :id AND si.is_reversed = 0
            LIMIT 1
        ");
        $this->db->bind(':id', $invoiceId, PDO::PARAM_INT);
        $row = $this->db->single();

        return $row ?: null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchReturnContext(int $returnId): ?array
    {
        $this->db->query("
            SELECT
                sr.id,
                sr.return_code,
                sr.return_date,
                sr.total_amount,
                sr.status,
                sr.reason,
                si.invoice_code,
                si.branch_id,
                COALESCE(NULLIF(TRIM(c.shop_name), ''), NULLIF(TRIM(c.customer_name), ''), 'Customer') AS customer_name,
                b.branch_name,
                (SELECT COUNT(*) FROM sales_return_items sri WHERE sri.sales_return_id = sr.id) AS item_count
            FROM sales_returns sr
            JOIN sales_invoices si ON si.id = sr.sales_invoice_id
            JOIN customers c ON c.id = sr.customer_id
            JOIN branches b ON b.id = si.branch_id
            WHERE sr.id = :id
            LIMIT 1
        ");
        $this->db->bind(':id', $returnId, PDO::PARAM_INT);
        $row = $this->db->single();

        return $row ?: null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchPaymentContext(int $paymentId, int $invoiceId): ?array
    {
        $this->db->query("
            SELECT
                cp.id AS payment_id,
                cp.payment_code,
                cp.payment_date,
                cp.amount,
                cp.payment_mode,
                cp.created_by,
                si.id AS invoice_id,
                si.invoice_code,
                si.invoice_date,
                si.total_amount AS invoice_total,
                si.branch_id,
                COALESCE(NULLIF(TRIM(c.shop_name), ''), NULLIF(TRIM(c.customer_name), ''), 'Customer') AS customer_name,
                b.branch_name,
                u.username AS received_by_username,
                e.name AS received_by_name,
                CASE WHEN si.invoice_date = CURDATE() THEN 1 ELSE 0 END AS is_today_invoice
            FROM customer_payments cp
            JOIN sales_invoices si ON si.id = :invoice_id
            JOIN customers c ON c.id = si.customer_id
            JOIN branches b ON b.id = si.branch_id
            LEFT JOIN users u ON u.id = cp.created_by
            LEFT JOIN employees e ON e.id = u.employee_id
            WHERE cp.id = :payment_id AND COALESCE(cp.is_reversed, 0) = 0
            LIMIT 1
        ");
        $this->db->bind(':payment_id', $paymentId, PDO::PARAM_INT);
        $this->db->bind(':invoice_id', $invoiceId, PDO::PARAM_INT);
        $row = $this->db->single();

        if (!$row) {
            return null;
        }

        $row['is_today_invoice'] = !empty($row['is_today_invoice']);

        return $row;
    }

    /**
     * @param array<string, mixed> $ctx
     */
    private function buildInvoiceCreatedMessage(array $ctx): string
    {
        $publicBase = defined('PUBLIC_URL') ? rtrim(PUBLIC_URL, '/') . '/' : rtrim(BASE_URL, '/') . '/';
        $invoiceId = (int)($ctx['id'] ?? 0);

        return $this->formatMessage(
            '🧾 New Sales Invoice',
            [
                'Invoice'  => (string)($ctx['invoice_code'] ?? '—'),
                'Customer' => (string)($ctx['customer_name'] ?? '—'),
                'Items'    => (string)(int)($ctx['item_count'] ?? 0),
                'Total'    => 'Tk ' . number_format((float)($ctx['total_amount'] ?? 0), 2),
                'Branch'   => (string)($ctx['branch_name'] ?? '—'),
                'Date'     => $this->formatDate($ctx['invoice_date'] ?? null),
            ],
            $publicBase . 'Challan/create/' . $invoiceId,
            'Open godown / challan'
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function buildChallanCreatedMessage(array $payload): string
    {
        return $this->formatMessage(
            '📦 Sales Challan Finalized',
            [
                'Challan'    => (string)($payload['challan_code'] ?? '—'),
                'Invoice'    => (string)($payload['invoice_code'] ?? '—'),
                'Customer'   => (string)($payload['customer_name'] ?? '—'),
                'Items'      => (string)(int)($payload['item_count'] ?? 0),
                'Total'      => 'Tk ' . number_format((float)($payload['total_amount'] ?? 0), 2),
                'Branch'     => (string)($payload['branch_name'] ?? '—'),
                'Warehouse'  => (string)($payload['warehouse_label'] ?? '—'),
                'Date & time'=> (string)($payload['formatted_at'] ?? date('d M Y, h:i A')),
            ],
            (string)($payload['view_url'] ?? ''),
            'View challan'
        );
    }

    /**
     * @param array<string, mixed> $ctx
     */
    private function buildReturnCreatedMessage(array $ctx): string
    {
        $publicBase = defined('PUBLIC_URL') ? rtrim(PUBLIC_URL, '/') . '/' : rtrim(BASE_URL, '/') . '/';
        $returnId = (int)($ctx['id'] ?? 0);

        $fields = [
            'Return'   => (string)($ctx['return_code'] ?? '—'),
            'Invoice'  => (string)($ctx['invoice_code'] ?? '—'),
            'Customer' => (string)($ctx['customer_name'] ?? '—'),
            'Items'    => (string)(int)($ctx['item_count'] ?? 0),
            'Amount'   => 'Tk ' . number_format((float)($ctx['total_amount'] ?? 0), 2),
            'Branch'   => (string)($ctx['branch_name'] ?? '—'),
            'Status'   => 'Pending warehouse confirmation',
        ];
        if (!empty($ctx['reason'])) {
            $fields['Reason'] = (string)$ctx['reason'];
        }

        return $this->formatMessage(
            '↩️ Sales Return Created',
            $fields,
            $publicBase . 'SalesReturn/confirm/' . $returnId,
            'Confirm return'
        );
    }

    /**
     * @param array<string, mixed> $ctx
     */
    private function buildReturnReceivedMessage(array $ctx, int $confirmedByUserId): string
    {
        $publicBase = defined('PUBLIC_URL') ? rtrim(PUBLIC_URL, '/') . '/' : rtrim(BASE_URL, '/') . '/';
        $returnId = (int)($ctx['id'] ?? 0);
        $receiverLabel = 'User #' . $confirmedByUserId;
        if ($confirmedByUserId > 0) {
            $profiles = $this->notifications->getUsersTelegramProfilesByIds([$confirmedByUserId]);
            if (!empty($profiles[0]['employee_name'])) {
                $receiverLabel = (string)$profiles[0]['employee_name'];
            } elseif (!empty($profiles[0]['username'])) {
                $receiverLabel = (string)$profiles[0]['username'];
            }
        }

        return $this->formatMessage(
            '✅ Sales Return Received',
            [
                'Return'     => (string)($ctx['return_code'] ?? '—'),
                'Invoice'    => (string)($ctx['invoice_code'] ?? '—'),
                'Customer'   => (string)($ctx['customer_name'] ?? '—'),
                'Amount'     => 'Tk ' . number_format((float)($ctx['total_amount'] ?? 0), 2),
                'Branch'     => (string)($ctx['branch_name'] ?? '—'),
                'Received by'=> $receiverLabel,
                'Date & time'=> date('d M Y, h:i A'),
            ],
            $publicBase . 'SalesReturn/slip/' . $returnId,
            'View return slip'
        );
    }

    /**
     * @param array<string, mixed> $ctx
     */
    private function buildPaymentReceivedMessage(array $ctx): string
    {
        $publicBase = defined('PUBLIC_URL') ? rtrim(PUBLIC_URL, '/') . '/' : rtrim(BASE_URL, '/') . '/';
        $receiver = trim((string)($ctx['received_by_name'] ?? $ctx['received_by_username'] ?? '—'));

        return $this->formatMessage(
            '💰 Payment Received (Today Invoice)',
            [
                'Payment'    => (string)($ctx['payment_code'] ?? '—'),
                'Invoice'    => (string)($ctx['invoice_code'] ?? '—'),
                'Customer'   => (string)($ctx['customer_name'] ?? '—'),
                'Amount'     => 'Tk ' . number_format((float)($ctx['amount'] ?? 0), 2),
                'Mode'       => strtoupper((string)($ctx['payment_mode'] ?? '')),
                'Branch'     => (string)($ctx['branch_name'] ?? '—'),
                'Received by'=> $receiver !== '' ? $receiver : '—',
                'Date & time'=> date('d M Y, h:i A'),
            ],
            $publicBase . 'sales/today',
            'Open today invoices'
        );
    }

    /**
     * @param array<string, string> $fields
     */
    private function formatMessage(string $title, array $fields, string $linkUrl = '', string $linkLabel = 'Open'): string
    {
        $lines = ['<b>' . Telegram::escapeHtml($title) . '</b>', ''];

        foreach ($fields as $label => $value) {
            $lines[] = '<b>' . Telegram::escapeHtml($label) . ':</b> ' . Telegram::escapeHtml($value);
        }

        $linkUrl = trim($linkUrl);
        if ($linkUrl !== '') {
            $lines[] = '';
            $lines[] = '<a href="' . Telegram::escapeHtml($linkUrl) . '">' . Telegram::escapeHtml($linkLabel) . '</a>';
        }

        return implode("\n", $lines);
    }

    private function formatDate(?string $date): string
    {
        if (!$date) {
            return date('d M Y');
        }
        $ts = strtotime($date);

        return $ts ? date('d M Y', $ts) : $date;
    }

    /**
     * @return array{sent: int, skipped_no_chat: int, failed: int, errors: string[]}
     */
    private function emptySummary(): array
    {
        return ['sent' => 0, 'skipped_no_chat' => 0, 'failed' => 0, 'errors' => []];
    }
}
