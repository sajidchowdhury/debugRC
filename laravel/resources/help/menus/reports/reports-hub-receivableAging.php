<?php

/**
 * Help content for: reports.reports-hub-receivableAging
 * Route: admin.reports.receivableAging (ReportController@receivableAging)
 *
 * Receivable Aging — customer-wise outstanding grouped by age bucket
 * (0-30, 31-60, 61-90, 90+ days). Shows who owes you and for how long.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema)
 */

return [
    'key'        => 'reports.reports-hub-receivableAging',
    'module'     => 'reports',
    'title_bn'   => 'রিসিভেবল এজিং',
    'title_en'   => 'Receivable Aging',
    'icon'       => 'fa-hourglass-half',
    'summary'    => 'কোন খদ্দের কত দিনের পুরোনো বকেয়া রাখছে — ০-৩০, ৩১-৬০, ৬১-৯০, ৯০+ বালতে।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-calendar-days',  'text' => 'অ্যাজ-অফ তারিখ সেট করা (আজ বা যেকোনো তারিখ)'],
        ['icon' => 'fa-eye',            'text' => 'খদ্দের ধরে বয়স্গত বকেয়া দেখা'],
        ['icon' => 'fa-file-export',    'text' => 'CSV/PDF তে এক্সপোর্ট করা'],
    ],

    'impacts' => [
        ['who' => 'খদ্দের লেজার',  'what' => 'সব পোস্ট ইনভয়েস ও পেমেন্ট থেকে বকেয়া ব্যালেন্স বের হয়'],
        ['who' => 'কালেকশন',         'what' => 'পুরোনো বালত কালেকশন টার্গেট ঠিক করতে সাহায্য করে'],
    ],

    'cautions' => [
        'বকেয়া শুধু পোস্ট ইনভয়েস থেকে আসে — ড্রাফট ইনভয়েস বা পেন্ডিং পেমেন্ট ধরা হয় না।',
    ],

    'related' => ['reports.reports-hub', 'reports.dashboard', 'reports.reports-hub-arAgingCte'],

    // No diagram — per plan 7g the reports module has 0 diagrams.

    'updated_at' => '2026-08-07',
];
