<?php

/**
 * Help content for: reports.csv-export-export-challans
 * Route: admin.sales-challans.export-csv (CsvExportController@exportChallans)
 *
 * Export Challans to CSV — downloads challans (godown delivery notes) as a
 * flat CSV. Used by logistics/accountants to share with transport or auditors.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema)
 */

return [
    'key'        => 'reports.csv-export-export-challans',
    'module'     => 'reports',
    'title_bn'   => 'চালান CSV এক্সপোর্ট',
    'title_en'   => 'Export Challans CSV',
    'icon'       => 'fa-file-csv',
    'summary'    => 'গোডাউন চালানগুলো CSV ফাইলে নামিয়ে এক্সেলে খুলে দেখুন বা শেয়ার করুন।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-calendar-days',  'text' => 'তারিখ সীমা ও ফিল্টার সেট করা'],
        ['icon' => 'fa-download',       'text' => 'চালানের CSV ফাইল ডাউনলোড করা'],
    ],

    'impacts' => [
        ['who' => 'সেলস',  'what' => 'পোস্ট হওয়া চালান থেকে রো তৈরি হয় (read-only)'],
    ],

    'cautions' => [
        'এক্সপোর্ট যে মুহূর্তে চালান সেই স্ন্যাপশট ফাইলে থাকে — পরে নতুন চালান এলে আপডেট হয় না।',
    ],

    'related' => ['reports.csv-export', 'reports.reports-hub', 'sales.challans'],

    // No diagram — per plan 7g the reports module has 0 diagrams.

    'updated_at' => '2026-08-07',
];
