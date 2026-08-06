<?php

/**
 * Help content for: inventory.warehouse-transfers-print
 * Route: admin.warehouse-transfers.print
 *
 * Printable transfer challan sub-page — the physical document that
 * accompanies goods in transit between godowns.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.4 (sub-page)
 */

return [
    'key'        => 'inventory.warehouse-transfers-print',
    'module'     => 'inventory',
    'title_bn'   => 'Warehouse Transfer Print',
    'title_en'   => 'Warehouse Transfer Print',
    'icon'       => 'fa-print',
    'summary'    => 'এটি ওয়্যারহাউস ট্রান্সফারের প্রিন্ট ভিউ পেজ।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-print',    'text' => 'ট্রান্সফার চালান প্রিন্ট করা'],
        ['icon' => 'fa-file-pdf', 'text' => 'পিডিএফ হিসেবে ডাউনলোড করা'],
    ],

    'impacts' => [
        ['who' => 'প্রিন্ট',  'what' => 'শুধু আউটপুট — কোনো তথ্য বদলে না'],
    ],

    'cautions' => [
        'শুধু প্রিন্টের জন্য — সরাসরি তথ্য বদলানো যায় না।',
    ],

    'related' => [
        'inventory.warehouse-transfers',
        'inventory.warehouse-transfers-summary',
    ],

    'updated_at' => '2026-08-07',
];
