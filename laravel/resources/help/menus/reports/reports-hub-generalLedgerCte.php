<?php

/**
 * Help content for: reports.reports-hub-generalLedgerCte
 * Route: admin.reports.generalLedgerCte (ReportController@generalLedgerCte)
 *
 * General Ledger — CTE version. Same output as generalLedger but uses a
 * Common Table Expression for faster aggregation on large date ranges.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema)
 */

return [
    'key'        => 'reports.reports-hub-generalLedgerCte',
    'module'     => 'reports',
    'title_bn'   => 'জেনারেল লেজার (CTE)',
    'title_en'   => 'General Ledger (CTE)',
    'icon'       => 'fa-book-open',
    'summary'    => 'জেনারেল লেজারের দ্রুত ভার্সন — বড় তারিখ সীমা বা ব্যস্ত লেজারের জন্য CTE অপটিমাইজড।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-book',          'text' => 'নির্দিষ্ট লেজার বাছাই করে ভাউচার লাইন দেখা'],
        ['icon' => 'fa-bolt',          'text' => 'বড় ডেটার জন্য CTE ভার্সন দ্রুত রান করে'],
        ['icon' => 'fa-file-export',   'text' => 'CSV/PDF তে এক্সপোর্ট করা'],
    ],

    'impacts' => [
        ['who' => 'GL',         'what' => 'সব পোস্টেড জার্নাল লাইন থেকে একই ব্যালেন্স তৈরি হয় (generalLedger এর সমান)'],
        ['who' => 'হিসাব',         'what' => 'ওপেনিং + মুভমেন্ট = ক্লোজিং — সংখ্যা সমান, শুধু দ্রুত'],
    ],

    'cautions' => [
        'এটি generalLedger এর সমানুপাতিক ভার্সন — দুটোর সংখ্যা মিলবে, শুধু পারফরম্যান্স আলাদা।',
    ],

    'related' => ['reports.reports-hub', 'reports.dashboard', 'reports.reports-hub-generalLedger'],

    // No diagram — per plan 7g the reports module has 0 diagrams.

    'updated_at' => '2026-08-07',
];
