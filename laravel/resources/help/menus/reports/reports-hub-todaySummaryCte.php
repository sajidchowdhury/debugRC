<?php

/**
 * Help content for: reports.reports-hub-todaySummaryCte
 * Route: admin.reports.todaySummaryCte (ReportController@todaySummaryCte)
 *
 * Today Summary (CTE) — today's sales, purchase, cash in/out, receipts,
 * payments, and key KPIs in one page. CTE version for fast aggregation.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema)
 */

return [
    'key'        => 'reports.reports-hub-todaySummaryCte',
    'module'     => 'reports',
    'title_bn'   => 'আজকের সামারি (CTE)',
    'title_en'   => 'Today Summary (CTE)',
    'icon'       => 'fa-sun',
    'summary'    => 'আজকের বিক্রি, ক্রয়, নগদ ইন/আউট, রিসিট, পেমেন্ট — এক পেজে দ্রুত CTE ভার্সন।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-calendar-day',   'text' => 'আজ বা যেকোনো তারিখ সেট করা'],
        ['icon' => 'fa-bolt',            'text' => 'CTE ভার্সন দ্রুত অ্যাগ্রিগেট করে'],
        ['icon' => 'fa-file-export',     'text' => 'CSV/PDF তে এক্সপোর্ট করা'],
    ],

    'impacts' => [
        ['who' => 'সেলস/ক্রয়',  'what' => 'আজকের পোস্ট ইনভয়েস ও পারচেজ থেকে সামারি তৈরি হয়'],
        ['who' => 'ক্যাশ',       'what' => 'আজকের ক্যাশ ইন/আউট থেকে পজিশন আসে'],
    ],

    'cautions' => [
        'আজকের সামারি শুধু পোস্ট ডেটা দেখায় — পেন্ডিং বা ড্রাফট এন্ট্রি বাদ যায়।',
    ],

    'related' => ['reports.reports-hub', 'reports.dashboard', 'reports.reports-hub-revenueOverview'],

    // No diagram — per plan 7g the reports module has 0 diagrams.

    'updated_at' => '2026-08-07',
];
