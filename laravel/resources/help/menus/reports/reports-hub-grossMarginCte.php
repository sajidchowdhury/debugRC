<?php

/**
 * Help content for: reports.reports-hub-grossMarginCte
 * Route: admin.reports.grossMarginCte (ReportController@grossMarginCte)
 *
 * Gross Margin — CTE version. Same output as grossMargin but uses a Common
 * Table Expression for faster aggregation on large product catalogs.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema)
 */

return [
    'key'        => 'reports.reports-hub-grossMarginCte',
    'module'     => 'reports',
    'title_bn'   => 'গ্রস মার্জিন (CTE)',
    'title_en'   => 'Gross Margin (CTE)',
    'icon'       => 'fa-percent',
    'summary'    => 'গ্রস মার্জিনের দ্রুত ভার্সন — বড় প্রোডাক্ট ক্যাটালগের জন্য CTE অপটিমাইজড।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-calendar-days',  'text' => 'তারিখ সীমা সেট করা'],
        ['icon' => 'fa-bolt',           'text' => 'বড় ক্যাটালগের জন্য দ্রুত অ্যাগ্রিগেশন'],
        ['icon' => 'fa-file-export',    'text' => 'CSV/PDF তে এক্সপোর্ট করা'],
    ],

    'impacts' => [
        ['who' => 'সেলস',  'what' => 'একই ইনভয়েস লাইন থেকে বিক্রি টানা হয় (grossMargin এর সমান)'],
        ['who' => 'স্টক',  'what' => 'একই গড় ক্রয় মূল্য থেকে COGS বের হয়'],
    ],

    'cautions' => [
        'এটি grossMargin এর সমানুপাতিক ভার্সন — সংখ্যা মিলবে, শুধু দ্রুত রান হবে।',
    ],

    'related' => ['reports.reports-hub', 'reports.dashboard', 'reports.reports-hub-grossMargin'],

    // No diagram — per plan 7g the reports module has 0 diagrams.

    'updated_at' => '2026-08-07',
];
