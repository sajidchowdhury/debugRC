<?php

/**
 * Help content for: reports.reports-hub-journalEntries
 * Route: admin.reports.journalEntries (ReportController@journalEntries)
 *
 * Journal Entries report — list of all posted manual/auto journals within a
 * period, with voucher number, date, debit/credit, narration.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema)
 */

return [
    'key'        => 'reports.reports-hub-journalEntries',
    'module'     => 'reports',
    'title_bn'   => 'জার্নাল এন্ট্রি',
    'title_en'   => 'Journal Entries',
    'icon'       => 'fa-pen',
    'summary'    => 'একটা সময়ের সব জার্নাল ভাউচার — ভাউচার নম্বর, তারিখ, ডেবিট-ক্রেডিট, ন্যারেশন সহ।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-calendar-days',  'text' => 'তারিখ সীমা সেট করে জার্নাল তালিকা দেখা'],
        ['icon' => 'fa-filter',         'text' => 'লেজার/ভাউচার টাইপ/ব্র্যাঞ্চ দিয়ে ফিল্টার করা'],
        ['icon' => 'fa-file-export',    'text' => 'CSV/PDF তে এক্সপোর্ট করা'],
    ],

    'impacts' => [
        ['who' => 'GL',         'what' => 'সব পোস্টেড জার্নাল ভাউচার থেকে লাইন টানা হয়'],
        ['who' => 'অডিট',         'what' => 'ভাউচার নম্বর ও ন্যারেশন অডিট ট্রেইলে যায়'],
    ],

    'cautions' => [
        'শুধু পোস্ট হওয়া ভাউচার দেখায় — ড্রাফট জার্নাল তালিকায় আসে না।',
    ],

    'related' => ['reports.reports-hub', 'reports.dashboard', 'accounting.manual-journals'],

    // No diagram — per plan 7g the reports module has 0 diagrams.

    'updated_at' => '2026-08-07',
];
