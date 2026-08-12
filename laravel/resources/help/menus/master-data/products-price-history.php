<?php

/**
 * Help content for: master-data.products-price-history
 * Route: admin.products.priceHistory (per-product)
 *
 * Per-product price change history — read-only view of every time the price
 * was set/changed, who changed it, and what the old/new values were.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.2 (sub-page convention)
 */

return [
    'key'        => 'master-data.products-price-history',
    'module'     => 'master-data',
    'title_bn'   => 'প্রোডাক্ট প্রাইস হিস্ট্রি',
    'title_en'   => 'Product Price History',
    'icon'       => 'fa-clock-rotate-left',
    'summary'    => 'একটি পণ্যের দাম কখন কত ছিল — সেই ইতিহাস। পুরোনো দাম আর কে বদলেছে দুটোই এখানে।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'salesman', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-clock-rotate-left',  'text' => 'দাম পরিবর্তনের ইতিহাস দেখা'],
        ['icon' => 'fa-filter',              'text' => 'তারিখ ধরে ফিল্টার করা'],
        ['icon' => 'fa-file-export',         'text' => 'ইতিহাস এক্সপোর্ট করা'],
    ],

    'impacts' => [
        ['who' => 'রিপোর্ট',  'what' => 'পুরোনো দামের ইতিহাস থেকে মার্জিন রিপোর্ট তৈরি হয়'],
    ],

    'cautions' => [
        'শুধু দেখার জন্য — এখান থেকে দাম বদলানো যায় না, পণ্য পেজে গিয়ে বদলাতে হবে।',
    ],

    'related' => ['master-data.products', 'master-data.products-audit'],

    'updated_at' => '2026-08-07',
];
