<?php

/**
 * Help content for: reports.reports-hub-stocktakeVariance
 * Route: admin.reports.stocktakeVariance (ReportController@stocktakeVariance)
 *
 * Stocktake Variance — physical count vs system stock, with qty and value
 * variance per product. Used after stock-take to find shrinkage/overage.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema)
 */

return [
    'key'        => 'reports.reports-hub-stocktakeVariance',
    'module'     => 'reports',
    'title_bn'   => 'স্টকটেক ভ্যারিয়েন্স',
    'title_en'   => 'Stocktake Variance',
    'icon'       => 'fa-arrows-left-right',
    'summary'    => 'ফিজিক্যাল কাউন্ট বনাম সিস্টেম স্টক — কোথায় কত কম/বেশি পাওয়া গেল দেখুন।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-calendar-days',  'text' => 'স্টকটেক তারিখ বা সীমা সেট করা'],
        ['icon' => 'fa-filter',         'text' => 'প্রোডাক্ট/গোডাউন ধরে ফিল্টার করা'],
        ['icon' => 'fa-file-export',    'text' => 'CSV/PDF তে এক্সপোর্ট করা'],
    ],

    'impacts' => [
        ['who' => 'স্টকটেক',  'what' => 'কাউন্ট করা পরিমাণ ও সিস্টেম স্টক থেকে ভ্যারিয়েন্স বের হয়'],
        ['who' => 'হিসাব',       'what' => 'ভ্যারিয়েন্স ভ্যালু পিএল এ shrinkage খরচ হিসেবে আসে'],
    ],

    'cautions' => [
        'ভ্যারিয়েন্স শুধু সম্পূর্ণ স্টকটেক সাবমিট হলে সঠিক হবে — পেন্ডিং কাউন্ট বাদ যায়।',
    ],

    'related' => ['reports.reports-hub', 'reports.dashboard', 'reports.reports-hub-stocktakeVarianceExport'],

    // No diagram — per plan 7g the reports module has 0 diagrams.

    'updated_at' => '2026-08-07',
];
