<?php
// core/Telegram.php — Telegram Bot API client (sendMessage and helpers).

require_once __DIR__ . '/Logger.php';

class Telegram
{
    private const API_BASE = 'https://api.telegram.org/bot';

    /**
     * Whether bot token is configured and alerts are enabled.
     */
    public static function isConfigured(): bool
    {
        if (defined('TELEGRAM_ALERTS_ENABLED') && !TELEGRAM_ALERTS_ENABLED) {
            return false;
        }

        return self::botToken() !== '';
    }

    public static function botToken(): string
    {
        if (defined('TELEGRAM_BOT_TOKEN')) {
            return trim((string)TELEGRAM_BOT_TOKEN);
        }

        $fromEnv = getenv('TELEGRAM_BOT_TOKEN');

        return $fromEnv !== false ? trim((string)$fromEnv) : '';
    }

    /**
     * Send a text message to a Telegram chat (personal or group).
     *
     * @param int|string $chatId Telegram chat_id / user_id from @userinfobot or getUpdates
     * @param array{parse_mode?: string, disable_web_page_preview?: bool, disable_notification?: bool} $options
     * @return array{ok: bool, message_id: ?int, error: ?string, http_code: int, skipped: bool}
     */
    public static function sendMessage(int|string $chatId, string $text, array $options = []): array
    {
        $chatId = trim((string)$chatId);
        $text = trim($text);

        if ($chatId === '' || $text === '') {
            return self::result(false, null, 'Chat id and message text are required.', 0, false);
        }

        if (!self::isConfigured()) {
            self::logEvent('skipped', 'Telegram send skipped: bot not configured or alerts disabled.', [
                'chat_id' => $chatId,
            ]);

            return self::result(false, null, 'Telegram bot is not configured.', 0, true);
        }

        $payload = [
            'chat_id'    => $chatId,
            'text'       => $text,
            'parse_mode' => (string)($options['parse_mode'] ?? 'HTML'),
        ];

        if (array_key_exists('disable_web_page_preview', $options)) {
            $payload['disable_web_page_preview'] = (bool)$options['disable_web_page_preview'];
        }
        if (array_key_exists('disable_notification', $options)) {
            $payload['disable_notification'] = (bool)$options['disable_notification'];
        }

        $url = self::API_BASE . self::botToken() . '/sendMessage';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
        ]);

        $raw = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            self::logEvent('error', 'Telegram curl error', [
                'chat_id' => $chatId,
                'error'   => $curlError,
            ]);

            return self::result(false, null, $curlError ?: 'Network error', $httpCode, false);
        }

        $decoded = json_decode((string)$raw, true);
        if (!is_array($decoded)) {
            self::logEvent('error', 'Telegram invalid JSON response', [
                'chat_id'   => $chatId,
                'http_code' => $httpCode,
                'response'  => substr((string)$raw, 0, 400),
            ]);

            return self::result(false, null, 'Invalid Telegram API response', $httpCode, false);
        }

        if (!empty($decoded['ok'])) {
            $messageId = isset($decoded['result']['message_id'])
                ? (int)$decoded['result']['message_id']
                : null;

            self::logEvent('sent', 'Telegram message sent', [
                'chat_id'    => $chatId,
                'message_id' => $messageId,
            ]);

            return self::result(true, $messageId, null, $httpCode, false);
        }

        $apiError = (string)($decoded['description'] ?? 'Unknown Telegram API error');
        self::logEvent('error', 'Telegram API error', [
            'chat_id'   => $chatId,
            'http_code' => $httpCode,
            'error'     => $apiError,
        ]);

        return self::result(false, null, $apiError, $httpCode, false);
    }

    /**
     * Escape dynamic text for Telegram HTML parse mode.
     */
    public static function escapeHtml(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * @return array{ok: bool, message_id: ?int, error: ?string, http_code: int, skipped: bool}
     */
    private static function result(
        bool $ok,
        ?int $messageId,
        ?string $error,
        int $httpCode,
        bool $skipped
    ): array {
        return [
            'ok'         => $ok,
            'message_id' => $messageId,
            'error'      => $error,
            'http_code'  => $httpCode,
            'skipped'    => $skipped,
        ];
    }

    private static function logEvent(string $level, string $message, array $context = []): void
    {
        $line = json_encode([
            'timestamp' => date('Y-m-d H:i:s'),
            'level'     => $level,
            'message'   => $message,
            'context'   => $context,
        ], JSON_UNESCAPED_UNICODE);

        $root = defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__);
        $logDir = $root . '/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }

        @file_put_contents($logDir . '/telegram.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);

        match ($level) {
            'error'   => Logger::error($message, $context),
            'skipped' => Logger::info($message, $context),
            default   => Logger::info($message, $context),
        };
    }
}
