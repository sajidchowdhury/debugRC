<?php

/**
 * Help content for: reports.reports-hub-balanceSheet
 * Route: admin.reports.balanceSheet (ReportController@balanceSheet)
 *
 * Balance Sheet — assets, liabilities, and equity as at a date. Shows what
 * the business owns vs owes on a given day.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema)
 */

return [
    'key'        => 'reports.reports-hub-balanceSheet',
    'module'     => 'reports',
    'title_bn'   => 'ব্যালেন্স শিট',
    'title_en'   => 'Balance Sheet',
    'icon'       => 'fa-table-list',
    'summary'    => 'নির্দিষ্ট তারিখে কোম্পানির সম্পদ, দায় আর মালিকানা ইকুইটি — ব্যবসার আসল ছবি।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-calendar-day',   'text' => 'যেকোনো তারিখ পর্যন্ত ব্যালেন্স শিট তৈরি করা'],
        ['icon' => 'fa-eye',            'text' => 'অ্যাসেট, লায়াবিলিটি, ইকুইটি গ্রুপ ধরে দেখা'],
        ['icon' => 'fa-file-export',    'text' => 'PDF/CSV তে এক্সপোর্ট বা প্রিন্ট করা'],
    ],

    'impacts' => [
        ['who' => 'GL',         'what' => 'অ্যাসেট/লায়াবিলিটি/ইকুইটি লেজার ব্যালেন্স থেকে সংখ্যা আসে'],
        ['who' => 'হিসাব',         'what' => 'রিটেইন্ড আর্নিংস = পিএল এর নেট প্রফিট বহন করে'],
    ],

    'cautions' => [
        'ব্যালেন্স শিট ব্যালেন্স না হলে আগে ট্রায়াল ব্যাল্যান্স ও পিএল মিলিয়ে নিন।',
    ],

    'related' => ['reports.reports-hub', 'reports.dashboard', 'reports.reports-hub-profitAndLoss'],

    // No diagram — per plan 7g the reports module has 0 diagrams.

    'updated_at' => '2026-08-07',
];
