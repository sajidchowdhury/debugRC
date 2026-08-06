<?php

/**
 * Help content for: reports.reports-hub-stocktakeVarianceExport
 * Route: admin.reports.stocktakeVarianceExport (ReportController@stocktakeVarianceExport)
 *
 * Stocktake Variance Export — downloads the variance report as CSV/Excel,
 * a snapshot of stocktakeVariance at run time.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema)
 */

return [
    'key'        => 'reports.reports-hub-stocktakeVarianceExport',
    'module'     => 'reports',
    'title_bn'   => 'স্টকটেক ভ্যারিয়েন্স এক্সপোর্ট',
    'title_en'   => 'Stocktake Variance Export',
    'icon'       => 'fa-file-export',
    'summary'    => 'স্টকটেক ভ্যারিয়েন্স রিপোর্ট CSV/Excel ফাইলে নামান — অডিট বা ক্লেইমের জন্য।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-calendar-days',  'text' => 'তারিখ ও ফিল্টার সেট করা'],
        ['icon' => 'fa-download',       'text' => 'CSV/Excel ফাইল ডাউনলোড করা'],
    ],

    'impacts' => [
        ['who' => 'স্টকটেক',  'what' => 'একই ভ্যারিয়েন্স ডেটা থেকে রো তৈরি হয় (stocktakeVariance এর সমান)'],
        ['who' => 'অডিট',      'what' => 'ফাইল স্ন্যাপশট অডিট বা রিভিউতে লাগে'],
    ],

    'cautions' => [
        'এক্সপোর্ট যে মুহূর্তে চালান সেই স্ন্যাপশট ফাইলে থাকে — পরে নতুন ভ্যারিয়েন্স এলে আপডেট হয় না।',
    ],

    'related' => ['reports.reports-hub', 'reports.dashboard', 'reports.reports-hub-stocktakeVariance'],

    // No diagram — per plan 7g the reports module has 0 diagrams.

    'updated_at' => '2026-08-07',
];
