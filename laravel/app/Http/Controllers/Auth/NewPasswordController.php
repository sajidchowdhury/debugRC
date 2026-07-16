<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\CredentialVersion;
use App\Services\Auth\PasswordPolicy;
use App\Services\Auth\RememberMeManager;
use App\Services\Auth\UserAuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * New Password Controller — Phase 3.
 *
 * Handles the password reset form (POST). Validates the token, checks
 * the new password against the policy, updates the hash, invalidates
 * the token, bumps credential_version, and revokes remember-me tokens.
 */
class NewPasswordController extends Controller
{
    public function __construct(
        private RememberMeManager $rememberMe
    ) {}

    /**
     * Show the reset form.
     */
    public function create(Request $request, string $token)
    {
        $valid = $this->validateToken($token);
        if ($valid === null) {
            return redirect()->route('password.request')
                ->with('error', 'This reset link is invalid or has expired.');
        }

        return view('auth.reset', [
            'title' => 'Reset Password — Remote Center ERP',
            'token' => $token,
            'email' => $valid->email ?? '',
        ]);
    }

    /**
     * Store the new password.
     */
    public function store(Request $request)
    {
        $request->validate([
            'token' => ['required', 'string'],
            'password' => ['required', 'string'],
            'confirm_password' => ['required', 'string'],
        ]);

        $token = $request->input('token');
        $password = $request->input('password');
        $confirm = $request->input('confirm_password');

        if ($password !== $confirm) {
            throw ValidationException::withMessages([
                'password' => 'New password and confirmation do not match.',
            ]);
        }

        // Validate password policy.
        $policyResult = PasswordPolicy::validate($password);
        if ($policyResult !== true) {
            throw ValidationException::withMessages([
                'password' => $policyResult,
            ]);
        }

        $valid = $this->validateToken($token);
        if ($valid === null) {
            return redirect()->route('password.request')
                ->with('error', 'This reset link is invalid or has expired.');
        }

        $userId = (int) $valid->user_id;

        // Update password hash.
        DB::table('users')
            ->where('id', $userId)
            ->update([
                'password_hash' => Hash::make($password),
                'failed_login_count' => 0,
                'locked_until' => null,
                'updated_at' => now(),
            ]);

        // Invalidate the token (single-use).
        DB::table('password_reset_tokens')->where('user_id', $userId)->delete();

        // Bump credential_version (invalidates all other sessions).
        CredentialVersion::bump($userId);

        // Revoke all remember-me tokens.
        $this->rememberMe->revokeAllForUser($userId);

        UserAuditLogger::log($userId, 'password_reset');

        return redirect()->route('login')
            ->with('success', 'Your password has been reset. Please sign in.');
    }

    /**
     * Validate a reset token. Returns the token row or null.
     */
    private function validateToken(string $token): ?object
    {
        $tokenHash = hash('sha256', $token);

        return DB::table('password_reset_tokens')
            ->where('token_hash', $tokenHash)
            ->where('expires_at', '>', now())
            ->first();
    }
}
