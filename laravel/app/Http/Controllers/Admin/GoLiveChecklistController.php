<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Go-Live Checklist Controller — Task 30 (Phase 1D).
 *
 * Bengali/English bilingual go-live checklist for the Sales module.
 * Migrated from legacy: app/views/sales/go_live_checklist.php
 *
 * Assets already deployed:
 *   - public/assets/css/sales-guide.css (Hind Siliguri + Noto Sans Bengali fonts, checklist styles)
 *   - public/assets/js/sales-guide.js   (search filter + IntersectionObserver nav)
 *
 * Gap: G-15 — Go-live checklist (manager sign-off)
 */
class GoLiveChecklistController extends Controller
{
    /**
     * Show the Go-Live checklist page.
     *
     * Accessible to: manager, admin, accountant, warehouse_manager, IT roles.
     * (Not typically for salesman — this is a pre-launch sign-off document.)
     */
    public function index()
    {
        return view('admin.sales.checklist', [
            'title' => 'Go-Live Checklist / সেলস Go-Live চেকলিস্ট',
        ]);
    }
}
