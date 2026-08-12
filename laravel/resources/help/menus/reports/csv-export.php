<?php

/**
 * Help content for: reports.csv-export
 * Route: admin.sales-invoices.export-csv (CsvExportController@exportInvoices)
 *
 * The CSV Export landing — pick a dataset (invoices, challans, …), set filters,
 * download a flat CSV. Used by accountants and managers to pull data into
 * Excel/Google Sheets for ad-hoc analysis or auditor sharing.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema)
 */

return [
    'key'        => 'reports.csv-export',
    'module'     => 'reports',
    'title_bn'   => 'CSV এক্সপোর্ট',
    'title_en'   => 'CSV Export',
    'icon'       => 'fa-file-csv',
    'summary'    => 'ইনভয়েস, চালান, বা অন্য ডেটা CSV ফাইলে নামিয়ে এক্সেলে খুলে দেখার জন্য এই পেজ।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-list-check',         'text' => 'ডেটাসেট বাছাই করা (ইনভয়েস, চালান, পারচেজ, পেমেন্ট ইত্যাদি)'],
        ['icon' => 'fa-calendar-days',      'text' => 'তারিখ সীমা ও ফিল্টার সেট করা (খদ্দের, ব্র্যাঞ্চ, সেলসম্যান)'],
        ['icon' => 'fa-download',           'text' => 'CSV ফাইল ডাউনলোড করা'],
        ['icon' => 'fa-file-excel',          'text' => 'এক্সেল/Google Sheets এ খুলে পিভট, চার্ট বানানো'],
    ],

    'impacts' => [
        ['who' => 'সেলস',     'what' => 'ইনভয়েস ও চালান ডেটা থেকে রো তৈরি হয় (read-only)'],
        ['who' => 'ক্রয়',      'what' => 'পারচেজ ডেটা এক্সপোর্ট হলে সাপ্লায়ার রো আসে'],
        ['who' => 'হিসাব',     'what' => 'পেমেন্ট/জার্নাল এক্সপোর্ট হলে GL রো আসে'],
    ],

    'cautions' => [
        'এক্সপোর্ট যে মুহূর্তে চালান, সেই স্ন্যাপশট ফাইলে থাকে — পরে নতুন এন্ট্রি এলে ফাইল আপডেট হয় না।',
        'খুব বড় রেঞ্জ (যেমন ১ বছরের সব ইনভয়েস) এক্সপোর্ট করলে টাইমআউট হতে পারে — মাস ধরে ভাগ করে নামান।',
    ],

    'related' => ['reports.reports-hub', 'reports.csv-export-export-challans', 'sales.invoices', 'sales.challans'],

    // No diagram — CSV export is a one-button flow.

    'updated_at' => '2026-08-07',
];
