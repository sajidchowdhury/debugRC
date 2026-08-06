<?php

/**
 * Help content for: master-data.products
 * Route: admin.products.index (and create/show/edit via wildcard)
 *
 * The Product master — the spine of the ERP. Every item the business buys,
 * stocks, or sells is registered here (name, code, unit, price, barcode,
 * category, group). Sales, purchase, inventory and most reports depend on it.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema)
 */

return [
    'key'        => 'master-data.products',
    'module'     => 'master-data',
    'title_bn'   => 'পণ্য',
    'title_en'   => 'Product',
    'icon'       => 'fa-box',
    'summary'    => 'বিক্রি বা কেনা হওয়া প্রতিটি পণ্যের নাম, কোড, দাম, স্টক এখানে মাস্টার হয়। সেলস, ক্রয়, ইনভেন্টরি সব এই তালিকা ধরে চলে।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'salesman', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-box',                  'text' => 'নতুন পণ্য যোগ করা (নাম, কোড, ইউনিট, দাম)'],
        ['icon' => 'fa-pen-to-square',        'text' => 'পণ্যের তথ্য বা দাম বদলানো'],
        ['icon' => 'fa-barcode',              'text' => 'বারকোড বসানো বা স্ক্যান করা'],
        ['icon' => 'fa-tags',                 'text' => 'ক্যাটাগরি ও গ্রুপ বরাদ্দ করা'],
        ['icon' => 'fa-clock-rotate-left',    'text' => 'দাম পরিবর্তনের ইতিহাস দেখা'],
        ['icon' => 'fa-print',               'text' => 'প্রোডাক্ট ডিরেক্টরি প্রিন্ট করা'],
    ],

    'impacts' => [
        ['who' => 'পণ্য',       'what' => 'মাস্টার তালিকায় যোগ/আপডেট হয়'],
        ['who' => 'স্টক',       'what' => 'ইনভেন্টরি ও গোডাউন এই পণ্য ধরে চলে'],
        ['who' => 'সেলস',       'what' => 'কার্ট ও ইনভয়েসে পণ্য ধরে দাম আসে'],
        ['who' => 'ক্রয়',       'what' => 'পিও ও রিসিভে পণ্য ম্যাচ হয়'],
        ['who' => 'রিপোর্ট',     'what' => 'গ্রস মার্জিন, প্রোডাক্ট মুভমেন্ট রিপোর্ট এই তালিকা ধরে চলে'],
    ],

    'cautions' => [
        'দাম বদলালে সেটা সাথে সাথে কার্টে চলে যায় — পুরোনো ইনভয়েসে আগের দামই থাকে।',
        'স্টক বা লেনদেন থাকা পণ্য ডিলিট করা যায় না — ইনঅ্যাক্টিভ করুন।',
        'একই কোড দুটো পণ্যে দিলে বারকোড স্ক্যানে গোলমাল হয়।',
    ],

    'related' => ['master-data.product-categories', 'master-data.product-groups', 'master-data.products-price-history', 'master-data.warehouses', 'master-data.products-audit'],

    // No diagram — a flat product list; the price-history sub-page handles change tracking.

    'updated_at' => '2026-08-07',
];
