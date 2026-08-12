<?php

/**
 * Help content for: reports.reports-hub-stocktakeWeekly
 * Route: admin.reports.stocktakeWeekly (ReportController@stocktakeWeekly)
 *
 * Stocktake Weekly — weekly snapshot of stock counts and variance, useful
 * for warehouses that count stock every week.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema)
 */

return [
    'key'        => 'reports.reports-hub-stocktakeWeekly',
    'module'     => 'reports',
    'title_bn'   => 'সাপ্তাহিক স্টকটেক',
    'title_en'   => 'Stocktake Weekly',
    'icon'       => 'fa-calendar-week',
    'summary'    => 'সাপ্তাহিক স্টক কাউন্ট ও ভ্যারিয়েন্স — যে গোডাউন প্রতি সপ্তাহে গণনা করে।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-calendar-week',  'text' => 'সপ্তাহ বা তারিখ সীমা সেট করা'],
        ['icon' => 'fa-filter',         'text' => 'প্রোডাক্ট/গোডাউন ধরে ফিল্টার করা'],
        ['icon' => 'fa-file-export',    'text' => 'CSV/PDF তে এক্সপোর্ট করা'],
    ],

    'impacts' => [
        ['who' => 'স্টকটেক',  'what' => 'সাপ্তাহিক কাউন্ট থেকে ভ্যারিয়েন্স টানা হয়'],
        ['who' => 'হিসাব',       'what' => 'সাপ্তাহিক ভ্যারিয়েন্স ভ্যালু shrinkage হিসেবে আসে'],
    ],

    'cautions' => [
        'সপ্তাহের কাউন্ট সম্পূর্ণ সাবমিট না হলে ভ্যারিয়েন্স সঠিক হবে না।',
    ],

    'related' => ['reports.reports-hub', 'reports.dashboard', 'reports.reports-hub-stocktakeWeeklyExport'],

    // No diagram — per plan 7g the reports module has 0 diagrams.

    'updated_at' => '2026-08-07',
];
