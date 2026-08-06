<?php

/**
 * Help content for: inventory.warehouse-transfers-reconcile
 * Route: admin.warehouse-transfers.reconcile
 *
 * Reconcile sub-page — match transfers in transit vs actually received
 * to detect partial-receive or missing receive-confirm.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.4 (sub-page)
 */

return [
    'key'        => 'inventory.warehouse-transfers-reconcile',
    'module'     => 'inventory',
    'title_bn'   => 'Warehouse Transfer Reconcile',
    'title_en'   => 'Warehouse Transfer Reconcile',
    'icon'       => 'fa-scale-balanced',
    'summary'    => 'এটি ওয়্যারহাউস ট্রান্সফারের রিকনসাইল পেজ — ট্রানজিট vs রিসিভ মেলানো।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-scale-balanced', 'text' => 'ট্রানজিটে থাকা চালান ও রিসিভ হওয়া মাল মেলানো'],
        ['icon' => 'fa-triangle-exclamation', 'text' => 'পার্শিয়াল বা পেন্ডিং রিসিভ চিহ্নিত করা'],
    ],

    'impacts' => [
        ['who' => 'রিকনসাইল',  'what' => 'ট্রানজিট লেজার পরিষ্কার হয় — কিন্তু সরাসরি কোনো পোস্ট হয় না'],
    ],

    'cautions' => [
        'শুধু দেখার/মেলানোর জন্য — কারেকশন দরকার হলে মূল ট্রান্সফার পেজে গিয়ে রিসিভ কনফার্ম করুন।',
    ],

    'related' => [
        'inventory.warehouse-transfers',
        'inventory.warehouse-transfers-audit',
    ],

    'updated_at' => '2026-08-07',
];
