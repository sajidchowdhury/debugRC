<?php

/**
 * Help content for: reports.reports-hub-damageReportExport
 * Route: admin.reports.damageReportExport (ReportController@damageReportExport)
 *
 * Damage Report Export — downloads the damage report as a CSV/Excel file,
 * a snapshot of the damageReport at run time.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema)
 */

return [
    'key'        => 'reports.reports-hub-damageReportExport',
    'module'     => 'reports',
    'title_bn'   => 'ড্যামেজ রিপোর্ট এক্সপোর্ট',
    'title_en'   => 'Damage Report Export',
    'icon'       => 'fa-file-export',
    'summary'    => 'ড্যামেজ রিপোর্ট CSV/Excel ফাইলে নামান — অডিটর বা ইনসিওরেন্সের জন্য শেয়ার করুন।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-calendar-days',  'text' => 'তারিখ সীমা ও ফিল্টার সেট করা'],
        ['icon' => 'fa-download',       'text' => 'CSV/Excel ফাইল ডাউনলোড করা'],
    ],

    'impacts' => [
        ['who' => 'স্টক',  'what' => 'একই ড্যামেজ এন্ট্রি থেকে রো তৈরি হয় (damageReport এর সমান)'],
        ['who' => 'অডিট',  'what' => 'ফাইল স্ন্যাপশট অডিট বা ক্লেইমের কাজে লাগে'],
    ],

    'cautions' => [
        'এক্সপোর্ট যে মুহূর্তে চালান, সেই স্ন্যাপশট ফাইলে থাকে — পরে নতুন এন্ট্রি এলে আপডেট হয় না।',
    ],

    'related' => ['reports.reports-hub', 'reports.dashboard', 'reports.reports-hub-damageReport'],

    // No diagram — per plan 7g the reports module has 0 diagrams.

    'updated_at' => '2026-08-07',
];
