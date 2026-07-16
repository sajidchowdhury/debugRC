<?php
// app/services/Notification/FcmTokenService.php

require_once __DIR__ . '/../../models/NotificationModel.php';

class FcmTokenService
{
    private NotificationModel $notifications;

    public function __construct(?NotificationModel $notifications = null)
    {
        $this->notifications = $notifications ?? new NotificationModel();
    }

    /**
     * Persist browser FCM token for the logged-in user.
     *
     * @return array{status: string, message: string}
     */
    public function saveToken(int $userId, string $token, string $deviceInfo = ''): array
    {
        $token = trim($token);
        if ($userId <= 0) {
            return ['status' => 'error', 'message' => 'Authentication required.'];
        }
        if ($token === '') {
            return ['status' => 'error', 'message' => 'Missing device token.'];
        }

        $db = $this->notifications->db;

        $db->query('DELETE FROM fcm_tokens WHERE user_id = ? AND fcm_token = ?');
        $db->bind(1, $userId, PDO::PARAM_INT);
        $db->bind(2, $token);
        $db->execute();

        $db->query('INSERT INTO fcm_tokens (user_id, fcm_token, device_info, created_at, updated_at)
                    VALUES (?, ?, ?, NOW(), NOW())
                    ON DUPLICATE KEY UPDATE device_info = VALUES(device_info), updated_at = NOW()');
        $db->bind(1, $userId, PDO::PARAM_INT);
        $db->bind(2, $token);
        $db->bind(3, $deviceInfo);

        if ($db->execute()) {
            return ['status' => 'success', 'message' => 'Token saved successfully'];
        }

        return ['status' => 'error', 'message' => 'Could not save device token.'];
    }
}