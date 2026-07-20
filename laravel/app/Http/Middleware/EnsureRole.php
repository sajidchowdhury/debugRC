<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ensure Role — Phase 3.
 *
 * Usage in routes: ->middleware('role:admin') or ->middleware('role:admin,accountant')
 *
 * Checks if the authenticated user's role (from the Employee relationship)
 * is one of the allowed roles. Admins and superadmins have a bypass for
 * admin-tier routes.
 *
 * The 10 canonical roles are defined in config/roles.php.
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        if (!Auth::check()) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }
            return redirect()->route('login');
        }

        /** @var \App\Models\User $user */
        $user = Auth::user();
        $userRole = $user->getRole();

        // Superadmin always passes.
        if ($userRole === 'superadmin') {
            return $next($request);
        }

        // If the route requires admin tier, check admin bypass.
        $allowedTiers = $this->getRoleTiers($roles);
        if (in_array('admin', $allowedTiers, true) && $userRole === 'admin') {
            return $next($request);
        }

        // Check exact role match.
        if (in_array($userRole, $roles, true)) {
            return $next($request);
        }

        // Deny.
        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'You do not have permission to perform this action.',
            ], 403);
        }

        return redirect()->route('dashboard')
            ->with('error', 'You do not have permission to access that area.');
    }

    /**
     * Get the tiers for the given roles from config/roles.php.
     *
     * @param array<string> $roles
     * @return array<string>
     */
    private function getRoleTiers(array $roles): array
    {
        $allRoles = config('roles', []);
        $tiers = [];
        foreach ($roles as $role) {
            $tier = $allRoles[$role]['tier'] ?? null;
            if ($tier !== null) {
                $tiers[] = $tier;
            }
        }
        return $tiers;
    }
}
