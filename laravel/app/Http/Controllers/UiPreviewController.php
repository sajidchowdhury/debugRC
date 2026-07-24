<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * UI Preview Controller — Phase 4 dev/design tool.
 *
 * Renders a storybook-style showcase of every <x-erp.*> design-system
 * component with sample data, so designers/stakeholders can verify visual
 * fidelity against rc-erp-ui-showcase.html before any sales view is rebuilt.
 *
 * Route: GET /ui-preview (auth-protected, inside the auth middleware group
 * in routes/web.php). Uses layouts/erp-preview.blade.php (no admin sidebar).
 */
class UiPreviewController extends Controller
{
    public function index(Request $request)
    {
        // Sample data for the showcase — kept here so the view stays readable.
        $data = [
            // Stat cards
            'stats' => [
                ['label' => 'Needs Godown', 'label_bn' => 'গোডাউন প্রয়োজন', 'value' => 4, 'accent' => 'amber', 'icon' => 'clock'],
                ['label' => 'Blank Godown', 'label_bn' => 'ব্লাঙ্ক গোডাউন', 'value' => 3, 'accent' => 'orange', 'icon' => 'clipboard-list'],
                ['label' => 'Ready for Challan', 'label_bn' => 'চালানের জন্য প্রস্তুত', 'value' => 2, 'accent' => 'cyan', 'icon' => 'warehouse'],
                ['label' => 'Completed', 'label_bn' => 'সম্পন্ন', 'value' => 1, 'accent' => 'green', 'icon' => 'check-circle'],
            ],

            // Status pills — all 6 statuses
            'statuses' => [
                'draft', 'finalized', 'blank_godown_created',
                'godown_prepared', 'challan_issued', 'cancelled',
            ],

            // Branch pills — all 4 branches
            'branches' => ['HO', 'PAT', 'NOW', 'TAR'],

            // Step indicator — 4-step workflow
            'workflowSteps' => [
                ['label' => 'Invoice', 'label_bn' => 'চালান', 'icon' => 'file-text', 'state' => 'done'],
                ['label' => 'Blank Godown', 'label_bn' => 'ব্লাঙ্ক গোডাউন', 'icon' => 'clipboard-list', 'state' => 'active'],
                ['label' => 'Godown Prep', 'label_bn' => 'গোডাউন প্রস্তুতি', 'icon' => 'warehouse', 'state' => 'pending'],
                ['label' => 'Challan Issue', 'label_bn' => 'চালান ইস্যু', 'icon' => 'truck', 'state' => 'pending'],
            ],

            // Data table sample rows
            'invoiceRows' => [
                ['code' => 'INV-HO-00011', 'customer' => 'Rahim Store', 'total' => 440, 'status' => 'blank_godown_created'],
                ['code' => 'INV-HO-00009', 'customer' => 'Al Amin Trading', 'total' => 1281, 'status' => 'finalized'],
                ['code' => 'INV-HO-00006', 'customer' => 'New Market Traders', 'total' => 15245, 'status' => 'godown_prepared'],
                ['code' => 'INV-HO-00007', 'customer' => 'Al Amin Trading', 'total' => 18910, 'status' => 'challan_issued'],
            ],
            'invoiceCols' => [
                ['key' => 'code', 'header' => 'Invoice Code', 'cell_class' => 'font-medium text-amber-900'],
                ['key' => 'customer', 'header' => 'Customer'],
                ['key' => 'total', 'header' => 'Total (৳)', 'header_class' => 'text-right', 'cell_class' => 'text-right font-semibold'],
                ['key' => 'status', 'header' => 'Status', 'render' => fn($row) => \App\Support\StatusPalette::pillHtml($row['status'])],
            ],

            // Filter chips
            'filterChips' => [
                ['key' => 'all', 'label' => 'All', 'count' => 10],
                ['key' => 'finalized', 'label' => 'Needs Godown', 'count' => 4],
                ['key' => 'blank_godown_created', 'label' => 'Blank Godown', 'count' => 3],
                ['key' => 'godown_prepared', 'label' => 'Ready for Challan', 'count' => 2],
                ['key' => 'challan_issued', 'label' => 'Completed', 'count' => 1],
            ],

            // Dispatcher checkbox cards
            'dispatchers' => [
                ['id' => 'd1', 'name' => 'Karim Uddin', 'sub' => 'EMP-DSP-HO-01 • 01711-123456'],
                ['id' => 'd2', 'name' => 'Rahim Sheikh', 'sub' => 'EMP-DSP-HO-02 • 01711-654321'],
                ['id' => 'd3', 'name' => 'Jashim Ali', 'sub' => 'EMP-DSP-HO-03 • 01711-987654'],
            ],

            // Signatures
            'signers' => [
                ['label' => 'Dispatcher', 'label_bn' => 'ডিসপ্যাচার'],
                ['label' => 'Godown Manager', 'label_bn' => 'গোডাউন ম্যানেজার'],
                ['label' => 'Verifier', 'label_bn' => 'যাচাইকারী'],
            ],
        ];

        return view('erp.ui-preview', $data);
    }
}
