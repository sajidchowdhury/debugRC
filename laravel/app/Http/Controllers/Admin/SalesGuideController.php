<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Sales Guide Controller — Task 29 (Phase 1D).
 *
 * Bengali/English bilingual user guideline page for the Sales module.
 * Migrated from legacy: app/views/sales/guide.php
 *
 * Assets already deployed:
 *   - public/assets/css/sales-guide.css (Hind Siliguri + Noto Sans Bengali fonts)
 *   - public/assets/js/sales-guide.js   (search filter + IntersectionObserver nav)
 *
 * Gap: G-14 — Sales guideline page (Bengali/English)
 */
class SalesGuideController extends Controller
{
    /**
     * Show the Sales guideline page.
     *
     * Accessible to all sales-module roles: salesman, warehouse_manager,
     * dispatcher, accountant, manager, admin.
     */
    public function guide()
    {
        return view('admin.sales.guide', [
            'title' => 'Sales Guideline / সেলস নির্দেশিকা',
        ]);
    }
}
