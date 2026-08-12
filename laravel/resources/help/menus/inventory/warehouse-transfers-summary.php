<?php

/**
 * Help content for: inventory.warehouse-transfers-summary
 * Route: admin.warehouse-transfers.summary
 *
 * Summary sub-page — aggregate view of all transfers (count, qty, value)
 * by godown, by date range.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.4 (sub-page)
 */

return [
    'key'        => 'inventory.warehouse-transfers-summary',
    'module'     => 'inventory',
    'title_bn'   => 'Warehouse Transfer Summary',
    'title_en'   => 'Warehouse Transfer Summary',
    'icon'       => 'fa-clipboard',
    'summary'    => 'এটি ওয়্যারহাউস ট্রান্সফারের সারাংশ পেজ।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-clipboard',  'text' => 'গোডাউন ও তারিখ ভিত্তিক সারাংশ দেখা'],
        ['icon' => 'fa-chart-bar',  'text' => 'ট্রান্সফার সংখ্যা, পরিমাণ ও মূল্য দেখা'],
    ],

    'impacts' => [
        ['who' => 'রিপোর্ট',  'what' => 'শুধু দেখা যায় — কোনো তথ্য বদলে না'],
    ],

    'cautions' => [
        'শুধু দেখার জন্য — সরাসরি তথ্য বদলানো যায় না।',
    ],

    'related' => [
        'inventory.warehouse-transfers',
        'inventory.warehouse-transfers-print',
    ],

    'updated_at' => '2026-08-07',
];
