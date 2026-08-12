<?php

/**
 * Help content for: inventory.stock-transactions-show
 * Route: admin.stock.show
 *
 * Detail sub-page — read-only view of a single stock transaction row
 * (product, qty in/out, source document, godown, timestamp).
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.4 (sub-page)
 */

return [
    'key'        => 'inventory.stock-transactions-show',
    'module'     => 'inventory',
    'title_bn'   => 'Stock Transaction Detail',
    'title_en'   => 'Stock Transaction Detail',
    'icon'       => 'fa-eye',
    'summary'    => 'এটি একটি স্টক লেজার এন্ট্রির বিস্তারিত পেজ।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-eye',           'text' => 'একটি মুভমেন্টের পুরো বিবরণ দেখা'],
        ['icon' => 'fa-arrow-up-right-from-square', 'text' => 'সোর্স ডকুমেন্টে (ইনভয়েস/পিও/ট্রান্সফার) ড্রিল ডাউন'],
    ],

    'impacts' => [
        ['who' => 'পেজ',  'what' => 'শুধু দেখা যায় — কোনো তথ্য বদলে না'],
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
