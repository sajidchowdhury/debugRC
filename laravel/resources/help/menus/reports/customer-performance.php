<?php

/**
 * Help content for: reports.customer-performance
 * Route: admin.reports.customerPerformance (CustomerPerformanceController@index)
 *
 * Per-customer performance report — sales, returns, payments received, outstanding,
 * and period-over-period comparison for one customer (or all customers ranked).
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema)
 */

return [
    'key'        => 'reports.customer-performance',
    'module'     => 'reports',
    'title_bn'   => 'কাস্টমার পারফরম্যান্স',
    'title_en'   => 'Customer Performance',
    'icon'       => 'fa-chart-line',
    'summary'    => 'প্রতিটি খদ্দেরের বিক্রি, রিটার্ন, পেমেন্ট আর বকেয়া — কোন খদ্দের কত দ্রুত বড় হচ্ছে দেখুন।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant', 'salesman'],

    'what_you_can_do' => [
        ['icon' => 'fa-user',               'text' => 'নির্দিষ্ট খদ্দের বা সব খদ্দের বাছাই করা'],
        ['icon' => 'fa-calendar-days',      'text' => 'তারিখ সীমা সেট করা ও পিরিয়ড তুলনা করা'],
        ['icon' => 'fa-file-invoice-dollar','text' => 'বিক্রি + রিটার্ন + পেমেন্ট এক সাথে দেখা'],
        ['icon' => 'fa-arrow-trend-up',     'text' => 'আগের পিরিয়ডের সাথে গ্রোথ তুলনা করা'],
        ['icon' => 'fa-file-csv',           'text' => 'রেজাল্ট CSV তে এক্সপোর্ট করা'],
    ],

    'impacts' => [
        ['who' => 'সেলস',     'what' => 'পোস্ট হওয়া ইনভয়েস ও রিটার্ন থেকে গ্রস বিক্রি বের হয়'],
        ['who' => 'হিসাব',     'what' => 'receive করা পেমেন্ট ও বকেয়া balance টানা হয়'],
        ['who' => 'সেলসম্যান',  'what' => 'খদ্দের ম্যাপ করা সেলসম্যানের পারফরম্যান্সে যোগ হয়'],
    ],

    'cautions' => [
        'শুধু পোস্ট হওয়া ইনভয়েস ধরা হয় — ড্রাফট ইনভয়েস এই রিপোর্টে আসে না।',
        'রিটার্ন বড় হলে নেট সেলস কম দেখাবে; গ্রস ও নেট আলাদা কলামে খেয়াল করুন।',
    ],

    'related' => ['sales.invoices', 'master-data.customers', 'sales.returns', 'reports.reports-hub', 'reports.dashboard'],

    // No diagram — per plan 7g the reports module has 0 diagrams.

    'updated_at' => '2026-08-07',
];
