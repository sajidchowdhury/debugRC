<?php

/**
 * Help content for: reports.reports-hub-generalLedger
 * Route: admin.reports.generalLedger (ReportController@generalLedger)
 *
 * General Ledger report — all journal lines per ledger account for a period,
 * showing opening, movements, and closing balance.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema)
 */

return [
    'key'        => 'reports.reports-hub-generalLedger',
    'module'     => 'reports',
    'title_bn'   => 'জেনারেল লেজার',
    'title_en'   => 'General Ledger',
    'icon'       => 'fa-book-open',
    'summary'    => 'একটা খাতার ভিতরের সব জার্নাল লাইন — ওপেনিং, মুভমেন্ট, ক্লোজিং ব্যালেন্স সহ।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-book',          'text' => 'নির্দিষ্ট লেজার বাছাই করে ভাউচার লাইন দেখা'],
        ['icon' => 'fa-calendar-days', 'text' => 'তারিখ সীমা সেট করা'],
        ['icon' => 'fa-file-export',   'text' => 'CSV/PDF তে এক্সপোর্ট করা'],
    ],

    'impacts' => [
        ['who' => 'GL',         'what' => 'সব পোস্টেড জার্নাল লাইন থেকে লেজার মুভমেন্ট টানা হয়'],
        ['who' => 'হিসাব',         'what' => 'ওপেনিং + মুভমেন্ট = ক্লোজিং ব্যালেন্স মিলিয়ে যায়'],
    ],

    'cautions' => [
        'বড় তারিখ সীমা ও ব্যস্ত লেজারের ক্ষেত্রে এই ভার্সন ধীর হতে পারে — CTE ভার্সন ব্যবহার করুন।',
    ],

    'related' => ['reports.reports-hub', 'reports.dashboard', 'reports.reports-hub-generalLedgerCte'],

    // No diagram — per plan 7g the reports module has 0 diagrams.

    'updated_at' => '2026-08-07',
];
