<?php

/**
 * Help content for: reports.reports-hub
 * Route: admin.reports.index (ReportController@index)
 *
 * The master Reports Hub — ONE landing page that lists every report type
 * (trial balance, P&L, balance sheet, cash flow, aging, general ledger,
 * gross margin, product movement, damage, stocktake, branch, supplier, audit).
 * Each report is its own tab/page reachable from this hub.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), Appendix A.9
 */

return [
    'key'        => 'reports.reports-hub',
    'module'     => 'reports',
    'title_bn'   => 'রিপোর্ট হাব',
    'title_en'   => 'Reports',
    'icon'       => 'fa-chart-pie',
    'summary'    => 'সব রিপোর্ট এক জায়গায় — ট্রায়াল ব্যাল্যান্স, পিএল, ব্যালেন্স শিট, ক্যাশফ্লো, এজিং, গ্রস মার্জিন, প্রোডাক্ট মুভমেন্ট — একটাই হাব।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-list',                'text' => 'সব রিপোর্ট টাইপ এক তালিকায় দেখা ও যেকোনোটায় ক্লিক করে খোলা'],
        ['icon' => 'fa-calendar-days',       'text' => 'তারিখ সীমা (date range) সেট করা — আজ, এই মাস, কাস্টম'],
        ['icon' => 'fa-filter',              'text' => 'ব্র্যাঞ্চ, লেজার, খদ্দের, সাপ্লায়ার দিয়ে ফিল্টার করা'],
        ['icon' => 'fa-play',                'text' => 'রিপোর্ট রান করে স্ক্রিনে ফলাফল দেখা'],
        ['icon' => 'fa-file-export',         'text' => 'রিপোর্ট CSV/PDF তে এক্সপোর্ট বা প্রিন্ট করা'],
        ['icon' => 'fa-bolt',               'text' => 'CTE ভার্সন বাছাই করা বড় ডেটার জন্য (দ্রুত রান)'],
    ],

    'impacts' => [
        ['who' => 'হিসাব (GL)',  'what' => 'সব পোস্টেড জার্নাল থেকে ব্যালেন্স টানা হয় (read-only)'],
        ['who' => 'সেলস/ক্রয়',     'what' => 'ইনভয়েস, চালান ও পারচেজ থেকে সারাংশ তৈরি হয়'],
        ['who' => 'স্টক',          'what' => 'স্টক ট্রানজেকশন ও স্টকটেক থেকে সংখ্যা আসে'],
        ['who' => 'ব্র্যাঞ্চ',     'what' => 'ব্র্যাঞ্চ ওয়াইজ লেজার ও ইন্টারকোম্পানি ডেটা একত্র হয়'],
    ],

    'cautions' => [
        'রিপোর্ট শুধু পোস্ট হওয়া ডেটা দেখায় — ড্রাফট জার্নাল বা পেন্ডিং ইনভয়েস ধরা হয় না।',
        'খুব বড় তারিখ সীমা (যেমন ১ বছর) দিলে সাধারণ ভার্সন ধীর হতে পারে — CTE ভার্সন ব্যবহার করুন।',
    ],

    'related' => ['accounting.manual-journals', 'master-data.ledgers', 'sales.invoices', 'inventory.stock-transactions', 'reports.dashboard'],

    // No diagram — per plan 7g the reports hub has 0 diagrams.

    'updated_at' => '2026-08-07',
];
