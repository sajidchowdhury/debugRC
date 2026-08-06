<?php

/**
 * Help content for: inventory.warehouse-transfers-audit
 * Route: admin.warehouse-transfers.audit
 *
 * Audit-trail sub-page for warehouse transfers — who created, transit, and
 * received each transfer, with timestamps.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.4 (sub-page)
 */

return [
    'key'        => 'inventory.warehouse-transfers-audit',
    'module'     => 'inventory',
    'title_bn'   => 'Warehouse Transfer Audit',
    'title_en'   => 'Warehouse Transfer Audit',
    'icon'       => 'fa-list-check',
    'summary'    => 'এটি ওয়্যারহাউস ট্রান্সফারের অডিট ট্রেইল পেজ।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-list-check',  'text' => 'প্রতিটি ট্রান্সফারের ক্রিয়েট/ট্রানজিট/রিসিভ লগ দেখা'],
        ['icon' => 'fa-user',       'text' => 'কে কখন কী অ্যাকশন নিয়েছে তা ট্র্যাক করা'],
    ],

    'impacts' => [
        ['who' => 'অডিট',  'what' => 'শুধু দেখা যায় — কোনো তথ্য বদলে না'],
    ],

    'cautions' => [
        'শুধু দেখার জন্য — সরাসরি তথ্য বদলানো যায় না।',
    ],

    'related' => [
        'inventory.warehouse-transfers',
        'inventory.warehouse-transfers-reconcile',
    ],

    'updated_at' => '2026-08-07',
];
