<?php

/**
 * Help content for: reports.sales-funnel
 * Route: admin.reports.salesFunnel (SalesFunnelController@index)
 *
 * Sales Funnel / pipeline report — carts → invoices → challans → payments,
 * showing conversion rates at each stage and where prospects drop off.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema)
 */

return [
    'key'        => 'reports.sales-funnel',
    'module'     => 'reports',
    'title_bn'   => 'সেলস ফানেল',
    'title_en'   => 'Sales Funnel',
    'icon'       => 'fa-filter',
    'summary'    => 'কার্ট থেকে ইনভয়েস, চালান, পেমেন্ট — প্রতিটা ধাপে কতগুলো সম্পন্ন হয় আর কোথায় পড়ে থাকে দেখুন।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant', 'salesman'],

    'what_you_can_do' => [
        ['icon' => 'fa-filter',             'text' => 'ফানেল স্টেজ দেখা — কার্ট, ইনভয়েস, চালান, পেমেন্ট'],
        ['icon' => 'fa-percent',            'text' => 'প্রতিটা স্টেজে কনভার্সন রেট দেখা'],
        ['icon' => 'fa-user-tie',           'text' => 'সেলসম্যান ধরে ফিল্টার করা'],
        ['icon' => 'fa-calendar-days',      'text' => 'তারিখ সীমা সেট করা'],
        ['icon' => 'fa-file-csv',           'text' => 'ফানেল ডেটা CSV তে এক্সপোর্ট করা'],
    ],

    'impacts' => [
        ['who' => 'সেলস কার্ট',  'what' => 'কার্ট সংখ্যা থেকে ফানেলের উপরের স্তর তৈরি হয়'],
        ['who' => 'ইনভয়েস',     'what' => 'পোস্ট হওয়া ইনভয়েস থেকে দ্বিতীয় স্তর আসে'],
        ['who' => 'চালান',       'what' => 'চালান তৈরি হলে তৃতীয় স্তর বাড়ে'],
        ['who' => 'পেমেন্ট',     'what' => 'receive হওয়া পেমেন্ট থেকে শেষ স্তর পূর্ণ হয়'],
    ],

    'cautions' => [
        'ড্রাফট কার্ট ফানেলের উপরের স্তর ফুলিয়ে দেখাতে পারে — রূপান্তর রেট বিশ্লেষণ করার আগে কার্টের স্ট্যাটাস খেয়াল করুন।',
        'চালান না হলে ইনভয়েস স্টেজে আটকে থাকবে — পেমেন্ট ছাড়া ইনভয়েস ফানেলের শেষে পৌঁছায় না।',
    ],

    'related' => ['sales.cart', 'sales.invoices', 'sales.challans', 'sales.customer-payments', 'reports.reports-hub'],

    // No diagram — per plan 7g the reports module has 0 diagrams.

    'updated_at' => '2026-08-07',
];
