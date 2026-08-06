<?php

/**
 * Help content for: reports.reports-hub-productMovement
 * Route: admin.reports.productMovement (ReportController@productMovement)
 *
 * Product Movement report — inward (purchase/return-in/adjustment+) and
 * outward (sale/return-out/damage/adjustment-) per product for a period.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema)
 */

return [
    'key'        => 'reports.reports-hub-productMovement',
    'module'     => 'reports',
    'title_bn'   => 'প্রোডাক্ট মুভমেন্ট',
    'title_en'   => 'Product Movement',
    'icon'       => 'fa-arrow-right-arrow-left',
    'summary'    => 'একটা সময়ে প্রোডাক্ট কত ইন/আউট হয়েছে — ক্রয়, বিক্রি, রিটার্ন, ড্যামেজ সব মুভমেন্ট।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-calendar-days',  'text' => 'তারিখ সীমা সেট করা'],
        ['icon' => 'fa-filter',         'text' => 'প্রোডাক্ট/ক্যাটাগরি/গোডাউন ধরে ফিল্টার করা'],
        ['icon' => 'fa-file-export',    'text' => 'CSV/PDF তে এক্সপোর্ট করা'],
    ],

    'impacts' => [
        ['who' => 'স্টক ট্রানজেকশন',  'what' => 'সব ইন/আউট ট্রানজেকশন থেকে মুভমেন্ট টানা হয়'],
        ['who' => 'হিসাব',              'what' => 'ওপেনিং + ইন - আউট = ক্লোজিং স্টক মিলিয়ে যায়'],
    ],

    'cautions' => [
        'শুধু পোস্ট হওয়া স্টক ট্রানজেকশন ধরা হয় — পেন্ডিং এন্ট্রি বাদ যায়।',
    ],

    'related' => ['reports.reports-hub', 'reports.dashboard', 'reports.reports-hub-productStockAnalysis'],

    // No diagram — per plan 7g the reports module has 0 diagrams.

    'updated_at' => '2026-08-07',
];
