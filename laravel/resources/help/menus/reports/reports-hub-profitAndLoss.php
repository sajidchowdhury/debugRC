<?php

/**
 * Help content for: reports.reports-hub-profitAndLoss
 * Route: admin.reports.profitAndLoss (ReportController@profitAndLoss)
 *
 * Profit & Loss statement — revenue, cost of goods sold, gross profit,
 * operating expenses, and net profit for a period.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema)
 */

return [
    'key'        => 'reports.reports-hub-profitAndLoss',
    'module'     => 'reports',
    'title_bn'   => 'প্রফিট অ্যান্ড লস',
    'title_en'   => 'Profit & Loss',
    'icon'       => 'fa-chart-bar',
    'summary'    => 'একটা সময়ের আয়, ক্রয়, খরচ আর নেট লাভ — ব্যবসা লাভে আছে কি লসে দেখুন।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-calendar-days',  'text' => 'তারিখ সীমা (মাস/কোয়ার্টার/বছর) সেট করা'],
        ['icon' => 'fa-eye',            'text' => 'আয়, COGS, গ্রস প্রফিট, খরচ, নেট প্রফিট দেখা'],
        ['icon' => 'fa-file-export',    'text' => 'PDF/CSV তে এক্সপোর্ট বা প্রিন্ট করা'],
    ],

    'impacts' => [
        ['who' => 'GL',         'what' => 'ইনকাম ও এক্সপেন্স লেজার ব্যালেন্স থেকে সংখ্যা টানা হয়'],
        ['who' => 'হিসাব',         'what' => 'নেট প্রফিট ব্যালেন্স শিটের রিটেইন্ড আর্নিংসে যায়'],
    ],

    'cautions' => [
        'পিরিয়ডের ভিতরের সব জার্নাল পোস্ট হয়ে থাকলে সংখ্যা সঠিক হবে — পেন্ডিং জার্নাল বাদ যায়।',
    ],

    'related' => ['reports.reports-hub', 'reports.dashboard', 'reports.reports-hub-balanceSheet'],

    // No diagram — per plan 7g the reports module has 0 diagrams.

    'updated_at' => '2026-08-07',
];
