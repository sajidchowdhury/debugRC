<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Services\MenuService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ensure Menu Permission — route-level menu access guard.
 *
 * This middleware bridges the gap between the sidebar menu visibility
 * (user_menu_permissions) and the route-level access control. It prevents
 * a user from accessing a menu's route by directly typing/pasting the URL
 * if they don't have can_view permission for that menu.
 *
 * Usage in routes:
 *   ->middleware('menu.permission:branchdemand')           // controller only
 *   ->middleware('menu.permission:branchdemand,pending')   // controller + action
 *
 * How it works:
 *   1. Admin/superadmin always pass (bypass).
 *   2. For non-admin users, it checks user_menu_permissions for a matching
 *      menu entry (by controller + optional action) with can_view = true.
 *   3. If no permission found, the request is denied with 403.
 *
 * This is the SECOND layer of defense:
 *   - Layer 1: MenuService hides the menu item from the sidebar.
 *   - Layer 2: This middleware blocks direct URL access.
 *   - Layer 3: The 'role' middleware restricts by role.
 */
class EnsureMenuPermission
{
    public function __construct(
        private MenuService $menuService
    ) {}

    public function handle(Request $request, Closure $next, string $controller, ?string $action = null): Response
    {
        if (!Auth::check()) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }
            return redirect()->route('login');
        }

        /** @var \App\Models\User $user */
        $user = Auth::user();

        // Admin/superadmin always pass.
        if ($user->isAdmin()) {
            return $next($request);
        }

        // Check menu permission via MenuService.
        if ($this->menuService->canView($user, $controller, $action ?? '')) {
            return $next($request);
        }

        // Deny — user doesn't have menu permission.
        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'You do not have permission to access this menu.',
            ], 403);
        }

        return redirect()->route('dashboard')
            ->with('error', 'You do not have permission to access that area.');
    }
}
