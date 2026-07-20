<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Password Policy — Phase 3.
 * Replicates legacy core/PasswordPolicy.php behavior.
 *
 * Rules:
 *   - 8-128 characters
 *   - At least one letter
 *   - At least one number
 *   - At least one special character
 *   - Not in the HIBP Pwned Passwords database (k-anonymity query)
 *
 * Returns true on success, or an error message string on failure.
 */
class PasswordPolicy
{
    public static function validate(string $password): true|string
    {
        if (strlen($password) < 8) {
            return 'Password must be at least 8 characters long.';
        }

        if (strlen($password) > 128) {
            return 'Password must not exceed 128 characters.';
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

        // HIBP Pwned Passwords k-anonymity check.
        if (!self::isSafeFromHIBP($password)) {
            return 'This password has appeared in a known data breach. Please choose a different password.';
        }

        return true;
    }

    /**
     * Check the password against the HIBP Pwned Passwords API using k-anonymity.
     * Only the first 5 characters of the SHA-1 hash are sent to the API.
     *
     * @see https://haveibeenpwned.com/API/v3#PwnedPasswords
     */
    private static function isSafeFromHIBP(string $password): bool
    {
        try {
            $hash = strtoupper(sha1($password));
            $prefix = substr($hash, 0, 5);
            $suffix = substr($hash, 5);

            $response = Http::withHeaders(['Add-Padding' => 'true'])
                ->timeout(5)
                ->get("https://api.pwnedpasswords.com/range/{$prefix}");

            if (!$response->successful()) {
                // If HIBP is unreachable, allow the password (fail-open).
                // Log the issue for monitoring.
                Log::warning('HIBP Pwned Passwords API unreachable, failing open', [
                    'status' => $response->status(),
                ]);
                return true;
            }

            // Check if our suffix appears in the response (with count > 0).
            $lines = explode("\n", $response->body());
            foreach ($lines as $line) {
                $parts = explode(':', trim($line), 2);
                if (count($parts) === 2 && $parts[0] === $suffix) {
                    $count = (int) $parts[1];
                    if ($count > 0) {
                        return false; // Password is compromised
                    }
                }
            }

            return true; // Password not found in breach database
        } catch (\Throwable $e) {
            Log::warning('HIBP check failed, failing open', [
                'error' => $e->getMessage(),
            ]);
            return true;
        }
    }

    /**
     * Validate a username (Phase 0 addition).
     * Min 4 chars, alphanumeric + underscore only.
     */
    public static function validateUsername(string $username): true|string
    {
        $username = trim($username);
        if ($username === '') {
            return 'Username is required.';
        }
        if (strlen($username) < 4) {
            return 'Username must be at least 4 characters long.';
        }
        if (strlen($username) > 50) {
            return 'Username must not exceed 50 characters.';
        }
        if (!preg_match('/^[A-Za-z0-9_]+$/', $username)) {
            return 'Username may only contain letters, numbers, and underscores.';
        }
        return true;
    }
}
