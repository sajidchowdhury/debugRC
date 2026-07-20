<?php
// app/services/Notification/SalesNotificationService.php

require_once __DIR__ . '/../../models/NotificationModel.php';
require_once __DIR__ . '/../../../core/Logger.php';

class SalesNotificationService
{
    private NotificationModel $notifications;

    public function __construct(?NotificationModel $notifications = null)
    {
        $this->notifications = $notifications ?? new NotificationModel();
    }

    /**
     * Notify warehouse managers of a new sales invoice (push + in-app).
     */
    public function notifyNewSalesInvoice(int $invoiceId, string $invoiceCode, float $totalAmount): void
    {
        $recipients = $this->notifications->getUsersForNotification('warehouse_manager');
        if ($recipients === []) {
            return;
        }

        $title = 'New Sales Invoice Created';
        $body  = 'Invoice #' . $invoiceCode . ' - ' . number_format($totalAmount, 2) . ' Tk';

        foreach ($recipients as $user) {
            $userId = (int)($user['id'] ?? 0);
            if ($userId <= 0) {
                continue;
            }

            foreach ($this->notifications->getUserFCMTokens($userId) as $tokenRow) {
                $token = trim($tokenRow['fcm_token'] ?? '');
                if ($token !== '') {
                    $this->sendPush($token, $title, $body, [
                        'type'         => 'new_sales',
                        'invoice_id'   => $invoiceId,
                        'invoice_code' => $invoiceCode,
                        'amount'       => $totalAmount,
                    ]);
                }
            }

            $this->notifications->saveNotification($userId, $title, $body, 'new_sales', $invoiceId);
        }
    }

    private function sendPush(string $token, string $title, string $body, array $data = []): bool
    {
        $serverKey = defined('FCM_SERVER_KEY') ? trim((string)FCM_SERVER_KEY) : '';
        if ($serverKey === '') {
            Logger::warning('FCM Push skipped: FCM_SERVER_KEY not configured.');
            return false;
        }

        $payload = [
            'to'           => $token,
            'notification' => [
                'title' => $title,
                'body'  => $body,
                'icon'  => '/favicon.ico',
            ],
            'data' => array_merge($data, [
                'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
            ]),
            'priority' => 'high',
        ];

        $ch = curl_init('https://fcm.googleapis.com/fcm/send');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: key=' . $serverKey,
                'Content-Type: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT        => 15,
        ]);

        $result = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($result === false) {
            Logger::error('FCM Push curl error', ['error' => curl_error($ch)]);
        }
        curl_close($ch);

        if ($httpCode !== 200) {
            Logger::warning('FCM Push HTTP error', [
                'http_code' => $httpCode,
                'response'  => substr((string)$result, 0, 300),
            ]);
        }

        return $httpCode === 200;
    }
}