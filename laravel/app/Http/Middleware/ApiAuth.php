<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 13 — simple bearer-token API auth (mobile/AI sidecar).
 *
 * Reads the `Authorization: Bearer {token}` header, looks up the matching
 * user via `User::findByApiToken()` (which hashes the plain token and
 * matches it against the `users.api_token` column), and logs the user in
 * on the web guard so RBAC role checks work identically to web sessions.
 *
 * On failure: 401 JSON `{"message": "Unauthenticated."}`.
 *
 * Optional `$role` args (set on the route via `->middleware('api.auth:admin')`)
 * restrict the endpoint to users with one of the listed roles. On role
 * mismatch: 403 JSON `{"message": "Forbidden."}`.
 *
 * This middleware pairs with the `api` route group registered in
 * bootstrap/app.php — see routes/api.php.
 */
class ApiAuth
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        // 1. Extract bearer token.
        $token = $this->bearerToken($request);

        if ($token === '') {
            return $this->unauthorized('Missing or invalid Authorization header.');
        }

        // 2. Look up the user by hashed token.
        $user = User::findByApiToken($token);

        if ($user === null) {
            return $this->unauthorized('Invalid or expired API token.');
        }

        // 3. Log the user in on the web guard so RBAC checks work.
        Auth::login($user);

        // 4. Optional role enforcement (e.g. ->middleware('api.auth:admin')).
        if ($roles !== []) {
            $userRole = $user->getRole();

            // Superadmin bypass.
            $passes = ($userRole === 'superadmin')
                || in_array($userRole, $roles, true)
                // Allow admin-tier users when 'admin' is among the allowed roles.
                || (in_array('admin', $roles, true) && $userRole === 'admin');

            if (!$passes) {
                return response()->json([
                    'message' => 'Forbidden. Requires role: ' . implode(',', $roles),
                ], 403);
            }
        }

        return $next($request);
    }

    /**
     * Extract the bearer token from the Authorization header.
     * Returns '' if the header is missing or not in `Bearer {token}` form.
     */
    private function bearerToken(Request $request): string
    {
        $header = $request->header('Authorization', '');

        if (!str_starts_with($header, 'Bearer ')) {
            return '';
        }

        return trim(substr($header, 7));
    }

    private function unauthorized(string $detail): Response
    {
        return response()->json([
            'message' => 'Unauthenticated.',
            'detail' => $detail,
        ], 401);
    }
}
