<?php

/**
 * Help content for: master-data.product-categories
 * Route: admin.product-categories.index (and create/show/edit via wildcard)
 *
 * The Product Category master — mid-level taxonomy. Categories sit below
 * product-groups and above individual products. Used to group products in
 * sales, stock, and margin reports.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema)
 */

return [
    'key'        => 'master-data.product-categories',
    'module'     => 'master-data',
    'title_bn'   => 'প্রোডাক্ট ক্যাটাগরি',
    'title_en'   => 'Product Category',
    'icon'       => 'fa-tags',
    'summary'    => 'পণ্য ধরন অনুযায়ী ভাগ করুন — যেমন খাবার, পানীয়, কেয়ারিং। রিপোর্টে ও খুঁজতে সুবিধা।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'salesman', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-plus',              'text' => 'নতুন ক্যাটাগরি যোগ করা (নাম, প্যারেন্ট)'],
        ['icon' => 'fa-sitemap',          'text' => 'ক্যাটাগরি গাছের মতো সাজানো (nested)'],
        ['icon' => 'fa-pen-to-square',     'text' => 'ক্যাটাগরির নাম বদলানো'],
        ['icon' => 'fa-tags',              'text' => 'পণ্য ক্যাটাগরি বুঝে বরাদ্দ করা'],
        ['icon' => 'fa-magnifying-glass',  'text' => 'নাম দিয়ে খুঁজে বের করা'],
    ],

    'impacts' => [
        ['who' => 'পণ্য',       'what' => 'প্রোডাক্ট মাস্টারে ক্যাটাগরি ধরে গ্রুপিং হয়'],
        ['who' => 'রিপোর্ট',    'what' => 'ক্যাটাগরি-ওয়াইজ সেলস ও স্টক রিপোর্ট ধরে চলে'],
    ],

    'cautions' => [
        'নাম বদলালে পণ্য বদলায় না, কিন্তু পুরোনো রিপোর্টে নতুন নাম দেখায়।',
        'ক্যাটাগরি ডিলিট করলে তার নিচের পণ্য আনক্যাটাগরাইজড হয়ে যেতে পারে।',
    ],

    'related' => ['master-data.products', 'master-data.product-groups', 'master-data.product-categories-audit'],

    // No diagram — a small nested list; the tree view in-page already shows structure.

    'updated_at' => '2026-08-07',
];
