<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard Controller — Phase 3.
 *
 * Minimal dashboard for the Laravel side. The full dashboard will be
 * ported in Phase 4+. For now, this shows the user is logged in and
 * provides a link back to the legacy app.
 */
class DashboardController extends Controller
{
    public function index()
    {
        $user = Auth::user();
        $branchId = session('branch_id');

        // Basic stats (best effort — tables may be empty during testing).
        $stats = [];
        try {
            $stats['customers'] = DB::table('customers')->whereNull('deleted_at')->count();
            $stats['products'] = DB::table('products')->whereNull('deleted_at')->count();
            $stats['invoices_today'] = DB::table('sales_invoices')
                ->where('invoice_date', today())
                ->whereNull('deleted_at')
                ->count();
            $stats['pending_challans'] = DB::table('sales_invoices')
                ->where('is_godown_prepared', false)
                ->where('status', 'confirmed')
                ->count();
        } catch (\Throwable $e) {
            // Tables may not exist yet during early testing.
        }

        return view('dashboard.index', [
            'title' => 'Dashboard — Remote Center ERP',
            'user' => $user,
            'stats' => $stats,
            'legacyUrl' => config('app.legacy_url', '/'),
        ]);
    }
}
