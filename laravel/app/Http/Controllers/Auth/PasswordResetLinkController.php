<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\LoginRateLimiter;
use App\Services\Auth\UserAuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Password Reset Link Controller — Phase 3.
 *
 * Replicates legacy core/PasswordReset.php behavior (SHA-256 hashed tokens,
 * 1hr expiry, single-use). Uses a `password_reset_tokens` table.
 *
 * NOTE: In Phase 3, mail sending may not be configured yet. The reset link
 * is logged + shown in dev mode. On the VPS, configure MAIL_* in .env.
 */
class PasswordResetLinkController extends Controller
{
    public function __construct(
        private LoginRateLimiter $rateLimiter
    ) {}

    /**
     * Show the forgot-password form.
     */
    public function create(Request $request)
    {
        return view('auth.forgot', [
            'title' => 'Forgot Password — Remote Center ERP',
        ]);
    }

    /**
     * Send a reset link.
     */
    public function store(Request $request)
    {
        $request->validate([
            'username' => ['required', 'string'],
        ]);

        $username = trim($request->input('username'));

        // Rate-limit reset requests (3 per 15 minutes).
        if ($this->rateLimiter->isLimited('forgot:' . $username)) {
            return back()->withErrors([
                'username' => 'Too many reset requests. Please try again later.',
            ])->withInput(['username' => $username]);
        }

        $user = User::active()->where('username', $username)->first();

        // Generic message (prevents user enumeration).
        $genericMessage = 'If an account exists for that username, reset instructions have been sent when possible.';

        if ($user) {
            $this->createResetToken($user);
        }

        $this->rateLimiter->recordFailure('forgot:' . $username);

        return redirect()->route('login')->with('success', $genericMessage);
    }

    /**
     * Create a password reset token and send the email.
     */
    private function createResetToken(User $user): void
    {
        $token = Str::random(60);
        $tokenHash = hash('sha256', $token);
        $expiresAt = now()->addHours(config('auth.reset_token_hours', 1));

        try {
            // Invalidate any existing tokens for this user.
            DB::table('password_reset_tokens')->where('user_id', $user->id)->delete();

            DB::table('password_reset_tokens')->insert([
                'user_id' => $user->id,
                'token_hash' => $tokenHash,
                'expires_at' => $expiresAt,
                'created_at' => now(),
            ]);

            // Send email (if mail is configured).
            $resetUrl = route('password.reset', ['token' => $token]);

            if (config('mail.mailers.smtp.host')) {
                try {
                    Mail::raw(
                        "Reset your RC_ERP password:\n\n{$resetUrl}\n\nThis link expires in 1 hour.",
                        function ($message) use ($user) {
                            $message->to($user->employee?->email ?? '')
                                ->subject('RC_ERP Password Reset');
                        }
                    );
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('Password reset email failed', [
                        'user_id' => $user->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Always log the reset URL in dev mode (for testing).
            if (app()->environment('local', 'development')) {
                \Illuminate\Support\Facades\Log::info('Password reset link', [
                    'user_id' => $user->id,
                    'url' => $resetUrl,
                ]);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Failed to create password reset token', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
