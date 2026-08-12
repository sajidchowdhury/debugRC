<?php

/**
 * Help content for: reports.reports-hub-stocktakeWeeklyExport
 * Route: admin.reports.stocktakeWeeklyExport (ReportController@stocktakeWeeklyExport)
 *
 * Stocktake Weekly Export — downloads the weekly stocktake report as
 * CSV/Excel, a snapshot of stocktakeWeekly at run time.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema)
 */

return [
    'key'        => 'reports.reports-hub-stocktakeWeeklyExport',
    'module'     => 'reports',
    'title_bn'   => 'সাপ্তাহিক স্টকটেক এক্সপোর্ট',
    'title_en'   => 'Stocktake Weekly Export',
    'icon'       => 'fa-file-export',
    'summary'    => 'সাপ্তাহিক স্টকটেক রিপোর্ট CSV/Excel ফাইলে নামান — ম্যানেজমেন্ট রিভিউয়ের জন্য।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-calendar-week',  'text' => 'সপ্তাহ ও ফিল্টার সেট করা'],
        ['icon' => 'fa-download',       'text' => 'CSV/Excel ফাইল ডাউনলোড করা'],
    ],

    'impacts' => [
        ['who' => 'স্টকটেক',  'what' => 'একই সাপ্তাহিক কাউন্ট থেকে রো তৈরি হয় (stocktakeWeekly এর সমান)'],
        ['who' => 'অডিট',      'what' => 'ফাইল স্ন্যাপশট অডিট বা রিভিউতে লাগে'],
    ],

    'cautions' => [
        'এক্সপোর্ট যে মুহূর্তে চালান সেই স্ন্যাপশট ফাইলে থাকে — পরে নতুন কাউন্ট এলে আপডেট হয় না।',
    ],

    'related' => ['reports.reports-hub', 'reports.dashboard', 'reports.reports-hub-stocktakeWeekly'],

    // No diagram — per plan 7g the reports module has 0 diagrams.

    'updated_at' => '2026-08-07',
];
