<?php

/**
 * Help content for: reports.reports-hub-trialBalance
 * Route: admin.reports.trialBalance (ReportController@trialBalance)
 *
 * Trial Balance — every ledger account's debit/credit totals and closing
 * balance for a date range. Used to verify books are balanced before pulling
 * P&L and Balance Sheet.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema)
 */

return [
    'key'        => 'reports.reports-hub-trialBalance',
    'module'     => 'reports',
    'title_bn'   => 'ট্রায়াল ব্যাল্যান্স',
    'title_en'   => 'Trial Balance',
    'icon'       => 'fa-scale-balanced',
    'summary'    => 'সব খাতার ডেবিট-ক্রেডিট ব্যালেন্স এক তালিকায় — মাস শেষে হিসাব মিলছে কি না দেখুন।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-calendar-days',  'text' => 'তারিখ সীমা সেট করে রিপোর্ট রান করা'],
        ['icon' => 'fa-eye',            'text' => 'প্রতিটা লেজারের ডেবিট/ক্রেডিট ও ক্লোজিং ব্যালেন্স দেখা'],
        ['icon' => 'fa-file-export',    'text' => 'রেজাল্ট CSV/PDF তে এক্সপোর্ট বা প্রিন্ট করা'],
    ],

    'impacts' => [
        ['who' => 'GL',         'what' => 'সব পোস্টেড জার্নাল থেকে লেজার ব্যালেন্স টানা হয়'],
        ['who' => 'হিসাব',         'what' => 'ডেবিট ও ক্রেডিট মোট না মিললে জার্নাল ভুল আছে বোঝা যায়'],
    ],

    'cautions' => [
        'শুধু পোস্ট হওয়া জার্নাল ধরা হয় — ড্রাফট জার্নাল ট্রায়াল ব্যাল্যান্সে আসে না।',
    ],

    'related' => ['reports.reports-hub', 'reports.dashboard', 'reports.reports-hub-profitAndLoss'],

    // No diagram — per plan 7g the reports module has 0 diagrams.

    'updated_at' => '2026-08-07',
];
