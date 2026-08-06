<?php

/**
 * Help content for: inventory.stock-adjustments-audit
 * Route: admin.stock-adjustments.audit
 *
 * Audit-trail sub-page for stock adjustments — tracks who created,
 * approved, and posted each adjustment, with reason code & timestamps.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.4 (sub-page)
 */

return [
    'key'        => 'inventory.stock-adjustments-audit',
    'module'     => 'inventory',
    'title_bn'   => 'Stock Adjustment Audit',
    'title_en'   => 'Stock Adjustment Audit',
    'icon'       => 'fa-list-check',
    'summary'    => 'এটি স্টক অ্যাডজাস্টমেন্টের অডিট ট্রেইল পেজ।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-list-check',  'text' => 'ক্রিয়েট/অ্যাপ্রুভ/পোস্ট প্রতিটি ধাপের লগ দেখা'],
        ['icon' => 'fa-tag',          'text' => 'রিজন কোড ও অ্যাপ্রুভার ট্র্যাক করা'],
    ],

    'impacts' => [
        ['who' => 'অডিট',  'what' => 'শুধু দেখা যায় — কোনো তথ্য বদলে না'],
    ],

    'cautions' => [
        'শুধু দেখার জন্য — সরাসরি তথ্য বদলানো যায় না।',
    ],

    'related' => [
        'inventory.stock-adjustments',
        'inventory.stock-adjustments-reconcile',
    ],

    'updated_at' => '2026-08-07',
];
