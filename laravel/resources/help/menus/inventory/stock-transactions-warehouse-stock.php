<?php

/**
 * Help content for: inventory.stock-transactions-warehouse-stock
 * Route: admin.stock.warehouse_stock
 *
 * Warehouse-stock snapshot sub-page — current stock-on-hand per product
 * per godown, derived from the stock ledger. Read-only.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.4 (sub-page)
 */

return [
    'key'        => 'inventory.stock-transactions-warehouse-stock',
    'module'     => 'inventory',
    'title_bn'   => 'Warehouse Stock',
    'title_en'   => 'Warehouse Stock',
    'icon'       => 'fa-warehouse',
    'summary'    => 'এটি গোডাউন ভিত্তিক বর্তমান স্টক স্ন্যাপশট পেজ।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-warehouse', 'text' => 'প্রতি গোডাউনে প্রতি পণ্যের বর্তমান স্টক দেখা'],
        ['icon' => 'fa-filter',    'text' => 'পণ্য বা গোডাউন দিয়ে ফিল্টার'],
    ],

    'impacts' => [
        ['who' => 'রিপোর্ট',  'what' => 'শুধু দেখা যায় — কোনো তথ্য বদলে না'],
    ],

    'cautions' => [
        'শুধু দেখার জন্য — সরাসরি তথ্য বদলানো যায় না।',
    ],

    'related' => [
        'inventory.stock-transactions',
        'master-data.warehouses',
    ],

    'updated_at' => '2026-08-07',
];
