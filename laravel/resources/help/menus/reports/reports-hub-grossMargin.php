<?php

/**
 * Help content for: reports.reports-hub-grossMargin
 * Route: admin.reports.grossMargin (ReportController@grossMargin)
 *
 * Gross Margin report — revenue, COGS, and gross profit % per product/category/
 * customer. Shows where the business actually makes money.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema)
 */

return [
    'key'        => 'reports.reports-hub-grossMargin',
    'module'     => 'reports',
    'title_bn'   => 'গ্রস মার্জিন',
    'title_en'   => 'Gross Margin',
    'icon'       => 'fa-percent',
    'summary'    => 'প্রতিটা প্রোডাক্ট/ক্যাটাগরি/খদ্দেরের বিক্রি, ক্রয় মূল্য আর গ্রস প্রফিট % — কোথায় লাভ বেশি।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-calendar-days',  'text' => 'তারিখ সীমা সেট করা'],
        ['icon' => 'fa-filter',         'text' => 'প্রোডাক্ট/ক্যাটাগরি/খদ্দের ধরে ফিল্টার করা'],
        ['icon' => 'fa-file-export',    'text' => 'CSV/PDF তে এক্সপোর্ট করা'],
    ],

    'impacts' => [
        ['who' => 'সেলস',  'what' => 'ইনভয়েস লাইন থেকে বিক্রি ও রেট টানা হয়'],
        ['who' => 'স্টক',  'what' => 'প্রোডাক্টের গড় ক্রয় মূল্য (COGS) বের হয়'],
    ],

    'cautions' => [
        'COGS গড় ক্রয় মূল্য ব্যবহার করে — ভেরিয়েবল কস্ট থাকলে মার্জিন একটু তফাত হতে পারে।',
    ],

    'related' => ['reports.reports-hub', 'reports.dashboard', 'reports.reports-hub-grossMarginCte'],

    // No diagram — per plan 7g the reports module has 0 diagrams.

    'updated_at' => '2026-08-07',
];
