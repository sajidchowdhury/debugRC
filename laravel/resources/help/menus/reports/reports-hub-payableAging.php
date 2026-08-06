<?php

/**
 * Help content for: reports.reports-hub-payableAging
 * Route: admin.reports.payableAging (ReportController@payableAging)
 *
 * Payable Aging — supplier-wise outstanding grouped by age bucket
 * (0-30, 31-60, 61-90, 90+ days). Shows who you owe and for how long.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema)
 */

return [
    'key'        => 'reports.reports-hub-payableAging',
    'module'     => 'reports',
    'title_bn'   => 'পেয়েবল এজিং',
    'title_en'   => 'Payable Aging',
    'icon'       => 'fa-hourglass-half',
    'summary'    => 'সাপ্লায়ারদের কত দিনের পুরোনো পাওনা বকেয়া আছে — বয়স্গত বালতে দেখুন।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-calendar-days',  'text' => 'অ্যাজ-অফ তারিখ সেট করা'],
        ['icon' => 'fa-eye',            'text' => 'সাপ্লায়ার ধরে বয়স্গত পাওনা দেখা'],
        ['icon' => 'fa-file-export',    'text' => 'CSV/PDF তে এক্সপোর্ট করা'],
    ],

    'impacts' => [
        ['who' => 'সাপ্লায়ার লেজার',  'what' => 'সব পোস্ট পারচেজ ও পেমেন্ট থেকে পাওনা ব্যালেন্স বের হয়'],
        ['who' => 'পেমেন্ট প্ল্যান',     'what' => 'পুরোনো বালত কে আগে পরিশোধ করবেন ঠিক করতে সাহায্য করে'],
    ],

    'cautions' => [
        'পাওনা শুধু পোস্ট পারচেজ থেকে আসে — ড্রাফট পারচেজ বা পেন্ডিং পেমেন্ট ধরা হয় না।',
    ],

    'related' => ['reports.reports-hub', 'reports.dashboard', 'reports.reports-hub-receivableAging'],

    // No diagram — per plan 7g the reports module has 0 diagrams.

    'updated_at' => '2026-08-07',
];
