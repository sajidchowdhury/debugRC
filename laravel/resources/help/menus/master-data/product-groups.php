<?php

/**
 * Help content for: master-data.product-groups
 * Route: admin.product-groups.index (and create/show/edit via wildcard)
 *
 * The Product Group master — top-level taxonomy above product-categories.
 * Groups roll categories up in reports (e.g. "Food" → "Beverages", "Snacks").
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema)
 */

return [
    'key'        => 'master-data.product-groups',
    'module'     => 'master-data',
    'title_bn'   => 'প্রোডাক্ট গ্রুপ',
    'title_en'   => 'Product Group',
    'icon'       => 'fa-layer-group',
    'summary'    => 'ক্যাটাগরির উপরের স্তর — একই ধরনের ক্যাটাগরি এক গ্রুপে রাখুন। গ্রুপ-ওয়াইজ রিপোর্ট সহজ হয়।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'salesman', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-plus',              'text' => 'নতুন গ্রুপ যোগ করা'],
        ['icon' => 'fa-layer-group',       'text' => 'গ্রুপের নিচে ক্যাটাগরি বরাদ্দ করা'],
        ['icon' => 'fa-pen-to-square',     'text' => 'গ্রুপের নাম বদলানো'],
        ['icon' => 'fa-magnifying-glass',  'text' => 'গ্রুপ খুঁজে বের করা'],
    ],

    'impacts' => [
        ['who' => 'পণ্য',       'what' => 'প্রোডাক্ট রিপোর্টে গ্রুপ লেভেল সামারি আসে'],
        ['who' => 'রিপোর্ট',    'what' => 'গ্রুপ-ওয়াইজ মার্জিন ও স্টক রিপোর্ট ধরে চলে'],
    ],

    'cautions' => [
        'গ্রুপ বদলালে রিপোর্ট গ্রুপিং বদলায় — পুরোনো রিপোর্টে নতুন নাম দেখায়।',
        'গ্রুপ ডিলিট করলে নিচের ক্যাটাগরি আনগ্রুপড হয়ে যেতে পারে।',
    ],

    'related' => ['master-data.product-categories', 'master-data.products', 'master-data.product-groups-audit'],

    // No diagram — a flat list of group records.

    'updated_at' => '2026-08-07',
];
