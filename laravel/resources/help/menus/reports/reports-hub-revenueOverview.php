<?php

/**
 * Help content for: reports.reports-hub-revenueOverview
 * Route: admin.reports.revenueOverview (ReportController@revenueOverview)
 *
 * Revenue Overview — top-line revenue summary by day/week/month, by product
 * category, by salesman, by branch. Used for management reviews.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema)
 */

return [
    'key'        => 'reports.reports-hub-revenueOverview',
    'module'     => 'reports',
    'title_bn'   => 'রেভিনিউ ওভারভিউ',
    'title_en'   => 'Revenue Overview',
    'icon'       => 'fa-chart-line',
    'summary'    => 'দিন/সপ্তাহ/মাস ধরে আয়ের ছবি — ক্যাটাগরি, সেলসম্যান, ব্র্যাঞ্চ ভেদে বিক্রি দেখুন।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant', 'salesman'],

    'what_you_can_do' => [
        ['icon' => 'fa-calendar-days',  'text' => 'দৈনিক/সাপ্তাহিক/মাসিক গ্রুপিং সেট করা'],
        ['icon' => 'fa-filter',         'text' => 'ক্যাটাগরি/সেলসম্যান/ব্র্যাঞ্চ ধরে ফিল্টার করা'],
        ['icon' => 'fa-file-export',    'text' => 'CSV/PDF তে এক্সপোর্ট করা'],
    ],

    'impacts' => [
        ['who' => 'সেলস',   'what' => 'পোস্ট ইনভয়েস থেকে গ্রস রেভিনিউ অ্যাগ্রিগেট হয়'],
        ['who' => 'ম্যানেজমেন্ট',  'what' => 'ট্রেন্ড ও গ্রোথ বোঝাতে সাহায্য করে'],
    ],

    'cautions' => [
        'রেভিনিউ গ্রস — রিটার্ন বাদ দিলে নেট রেভিনিউ কম হবে; পিএল এর সাথে মিলিয়ে দেখুন।',
    ],

    'related' => ['reports.reports-hub', 'reports.dashboard', 'reports.reports-hub-profitAndLoss'],

    // No diagram — per plan 7g the reports module has 0 diagrams.

    'updated_at' => '2026-08-07',
];
