<?php

/**
 * Help content for: reports.reports-hub-branchWiseLedger
 * Route: admin.reports.branchWiseLedger (ReportController@branchWiseLedger)
 *
 * Branch-wise Ledger — per-branch ledger balances and movements for a
 * period. Used in multi-branch businesses to compare branch performance.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema)
 */

return [
    'key'        => 'reports.reports-hub-branchWiseLedger',
    'module'     => 'reports',
    'title_bn'   => 'ব্র্যাঞ্চ ওয়াইজ লেজার',
    'title_en'   => 'Branch-wise Ledger',
    'icon'       => 'fa-book',
    'summary'    => 'প্রতিটা ব্র্যাঞ্চের লেজার ব্যালেন্স ও মুভমেন্ট — কোন ব্র্যাঞ্চ কেমন করছে দেখুন।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-calendar-days',  'text' => 'তারিখ সীমা সেট করা'],
        ['icon' => 'fa-filter',         'text' => 'ব্র্যাঞ্চ/লেজার ধরে ফিল্টার করা'],
        ['icon' => 'fa-file-export',    'text' => 'CSV/PDF তে এক্সপোর্ট করা'],
    ],

    'impacts' => [
        ['who' => 'GL',         'what' => 'ব্র্যাঞ্চ ট্যাগ করা সব জার্নাল থেকে লেজার ব্যালেন্স টানা হয়'],
        ['who' => 'ম্যানেজমেন্ট',  'what' => 'ব্র্যাঞ্চ তুলনা ও পারফরম্যান্স বোঝাতে সাহায্য করে'],
    ],

    'cautions' => [
        'জার্নালে ব্র্যাঞ্চ ট্যাগ না থাকলে রিপোর্টে সেই লাইন বাদ যাবে — ব্র্যাঞ্চ ম্যাপিং ঠিক রাখুন।',
    ],

    'related' => ['reports.reports-hub', 'reports.dashboard', 'reports.reports-hub-branchIntercompany'],

    // No diagram — per plan 7g the reports module has 0 diagrams.

    'updated_at' => '2026-08-07',
];
