<?php
// core/Mail.php — plain-text mail with optional dev logging.

class Mail
{
    public static function fromAddress(): string
    {
        if (defined('MAIL_FROM') && filter_var(MAIL_FROM, FILTER_VALIDATE_EMAIL)) {
            return (string)MAIL_FROM;
        }

        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $host = preg_replace('/[^a-zA-Z0-9.-]/', '', (string)$host) ?: 'localhost';

        return 'noreply@' . $host;
    }

    /**
     * @return array{sent: bool, error: string|null}
     */
    public static function sendPlain(string $to, string $subject, string $body): array
    {
        $to = trim($to);
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return ['sent' => false, 'error' => 'Invalid recipient address.'];
        }

        $from = self::fromAddress();
        $headers = implode("\r\n", [
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'From: ' . APP_NAME . ' <' . $from . '>',
            'Reply-To: ' . $from,
            'X-Mailer: PHP/' . PHP_VERSION,
        ]);

        $sent = @mail($to, $subject, $body, $headers);
        if ($sent) {
            return ['sent' => true, 'error' => null];
        }

        $error = 'mail() returned false';
        self::logFailure($to, $subject, $error);

        return ['sent' => false, 'error' => $error];
    }

    public static function logInvestigationOtp(string $to, string $otp, int $minutes): void
    {
        if (!defined('APP_DEBUG') || !APP_DEBUG) {
            return;
        }

        $line = sprintf(
            "[%s] Investigation deactivation OTP for %s: %s (valid %d min)\n",
            date('Y-m-d H:i:s'),
            $to,
            $otp,
            $minutes
        );

        $path = dirname(__DIR__) . '/logs/investigation_otp.log';
        @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
    }

    private static function logFailure(string $to, string $subject, string $error): void
    {
        if (!defined('APP_DEBUG') || !APP_DEBUG) {
            return;
        }

        $line = sprintf(
            "[%s] Mail failed to %s subject=%s error=%s\n",
            date('Y-m-d H:i:s'),
            $to,
            $subject,
            $error
        );

        $path = dirname(__DIR__) . '/logs/mail.log';
        @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
    }
}
