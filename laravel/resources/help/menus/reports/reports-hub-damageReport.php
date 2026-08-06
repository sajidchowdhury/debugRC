<?php

/**
 * Help content for: reports.reports-hub-damageReport
 * Route: admin.reports.damageReport (ReportController@damageReport)
 *
 * Damage Report — list of damaged/expired/lost stock entries for a period,
 * with product, qty, value, reason. Used for insurance claims and shrinkage
 * tracking.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema)
 */

return [
    'key'        => 'reports.reports-hub-damageReport',
    'module'     => 'reports',
    'title_bn'   => 'ড্যামেজ রিপোর্ট',
    'title_en'   => 'Damage Report',
    'icon'       => 'fa-triangle-exclamation',
    'summary'    => 'নষ্ট, মেয়াদোত্তীর্ণ বা হারানো স্টকের তালিকা — প্রোডাক্ট, পরিমাণ, মূল্য ও কারণ সহ।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-calendar-days',  'text' => 'তারিখ সীমা সেট করা'],
        ['icon' => 'fa-filter',         'text' => 'প্রোডাক্ট/গোডাউন/কারণ ধরে ফিল্টার করা'],
        ['icon' => 'fa-file-export',    'text' => 'CSV/PDF তে এক্সপোর্ট করা'],
    ],

    'impacts' => [
        ['who' => 'স্টক',  'what' => 'সব ড্যামেজ এন্ট্রি থেকে নষ্ট পরিমাণ ও মূল্য টানা হয়'],
        ['who' => 'হিসাব',  'what' => 'ড্যামেজ ভ্যালু পিএল এ খরচ হিসেবে আসে'],
    ],

    'cautions' => [
        'শুধু পোস্ট হওয়া ড্যামেজ এন্ট্রি আসে — পেন্ডিং এন্ট্রি বাদ যায়।',
    ],

    'related' => ['reports.reports-hub', 'reports.dashboard', 'reports.reports-hub-damageReportExport'],

    // No diagram — per plan 7g the reports module has 0 diagrams.

    'updated_at' => '2026-08-07',
];
