<?php

/**
 * Help content for: inventory.warehouse-transfers-checklist
 * Route: admin.warehouse-transfers.checklist
 *
 * Pre-transfer checklist sub-page — the steps to verify before sending
 * stock between godowns.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.4 (sub-page)
 */

return [
    'key'        => 'inventory.warehouse-transfers-checklist',
    'module'     => 'inventory',
    'title_bn'   => 'Warehouse Transfer Checklist',
    'title_en'   => 'Warehouse Transfer Checklist',
    'icon'       => 'fa-list-check',
    'summary'    => 'এটি ওয়্যারহাউস ট্রান্সফারের চেকলিস্ট পেজ।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-list-check',  'text' => 'ট্রান্সফার পূর্ববর্তী চেকলিস্ট দেখা'],
        ['icon' => 'fa-circle-check', 'text' => 'প্রতিটি ধাপ পূরণ হয়েছে কিনা নিশ্চিত হওয়া'],
    ],

    'impacts' => [
        ['who' => 'প্রসেস',  'what' => 'চেকলিস্ট মানলে ভুল ট্রান্সফার কমে'],
    ],

    'cautions' => [
        'শুধু দেখার/গাইডের জন্য — সরাসরি তথ্য বদলানো যায় না।',
    ],

    'related' => [
        'inventory.warehouse-transfers',
        'inventory.warehouse-transfers-audit',
    ],

    'updated_at' => '2026-08-07',
];
