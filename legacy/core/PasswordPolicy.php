<?php
// core/PasswordPolicy.php — shared password strength rules (Phase 5).

class PasswordPolicy
{
    public const MIN_LENGTH = 8;
    public const MAX_LENGTH = 128;

    /**
     * Returns true on success, or an error message string on failure.
     */
    public static function validate(string $password): true|string
    {
        $len = strlen($password);

        if ($len < self::MIN_LENGTH) {
            return 'Password must be at least ' . self::MIN_LENGTH . ' characters long.';
        }

        if ($len > self::MAX_LENGTH) {
            return 'Password must be at most ' . self::MAX_LENGTH . ' characters long.';
        }

        if (!preg_match('/[A-Za-z]/', $password)) {
            return 'Password must contain at least one letter.';
        }

        if (!preg_match('/[0-9]/', $password)) {
            return 'Password must contain at least one number.';
        }

        if (!preg_match('/[^A-Za-z0-9]/', $password)) {
            return 'Password must contain at least one special character.';
        }

        if (self::isBreached($password)) {
            return 'This password appears in a known data breach. Please choose a different password.';
        }

        return true;
    }

    public static function requirementsText(): string
    {
        return sprintf(
            '%d–%d characters, at least one letter, one number, and one special character.',
            self::MIN_LENGTH,
            self::MAX_LENGTH
        );
    }

    /**
     * Have I Been Pwned k-anonymity check. Fails open when offline or on API errors.
     */
    public static function isBreached(string $password): bool
    {
        if (!function_exists('curl_init')) {
            return false;
        }

        $hash = strtoupper(sha1($password));
        $prefix = substr($hash, 0, 5);
        $suffix = substr($hash, 5);

        $ch = curl_init('https://api.pwnedpasswords.com/range/' . $prefix);
        if ($ch === false) {
            return false;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 3,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_USERAGENT      => 'RemoteCenterERP-PasswordCheck',
        ]);

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            return false;
        }

        foreach (explode("\n", (string)$response) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            [$hashSuffix] = explode(':', $line, 2);
            if (strcasecmp($hashSuffix, $suffix) === 0) {
                return true;
            }
        }

        return false;
    }
}
