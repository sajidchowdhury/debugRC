<?php

/**
 * Help content for: inventory.stock-transactions-drift
 * Route: admin.stock.drift
 *
 * Drift-analysis sub-page — highlights products whose stock has shifted
 * unusually fast or in unexpected directions over a time window. Useful
 * for spotting shrinkage or mis-posted transactions.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.4 (sub-page)
 */

return [
    'key'        => 'inventory.stock-transactions-drift',
    'module'     => 'inventory',
    'title_bn'   => 'Stock Drift',
    'title_en'   => 'Stock Drift',
    'icon'       => 'fa-arrows-left-right',
    'summary'    => 'এটি স্টক লেজারের ড্রিফট অ্যানালাইসিস পেজ — অস্বাভাবিক স্টক পরিবর্তন চিহ্নিত করে।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-arrows-left-right', 'text' => 'অস্বাভাবিক স্টক মুভমেন্ট দেখা'],
        ['icon' => 'fa-filter',           'text' => 'তারিখ রেঞ্জ ও গোডাউন ভিত্তিতে ফিল্টার'],
    ],

    'impacts' => [
        ['who' => 'রিপোর্ট',  'what' => 'শুধু দেখা যায় — কোনো তথ্য বদলে না'],
    ],

    'cautions' => [
        'শুধু দেখার জন্য — সরাসরি তথ্য বদলানো যায় না।',
    ],

    'related' => [
        'inventory.stock-transactions',
        'inventory.stock-transactions-warehouse-stock',
    ],

    'updated_at' => '2026-08-07',
];
