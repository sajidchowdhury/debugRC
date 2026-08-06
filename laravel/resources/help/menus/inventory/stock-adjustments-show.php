<?php

/**
 * Help content for: inventory.stock-adjustments-show
 * Route: admin.stock-adjustments.show
 *
 * Detail sub-page — read-only view of a single stock adjustment (product,
 * qty diff, reason, approver, GL link, posted-at).
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.4 (sub-page)
 */

return [
    'key'        => 'inventory.stock-adjustments-show',
    'module'     => 'inventory',
    'title_bn'   => 'Stock Adjustment Detail',
    'title_en'   => 'Stock Adjustment Detail',
    'icon'       => 'fa-eye',
    'summary'    => 'এটি স্টক অ্যাডজাস্টমেন্টের বিস্তারিত পেজ।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-eye',           'text' => 'একটি অ্যাডজাস্টমেন্টের পুরো বিবরণ দেখা'],
        ['icon' => 'fa-arrow-up-right-from-square', 'text' => 'সংশ্লিষ্ট জার্নাল এন্ট্রিতে ড্রিল ডাউন'],
    ],

    'impacts' => [
        ['who' => 'পেজ',  'what' => 'শুধু দেখা যায় — কোনো তথ্য বদলে না'],
    ],

    'cautions' => [
        'শুধু দেখার জন্য — সরাসরি তথ্য বদলানো যায় না।',
    ],

    'related' => [
        'inventory.stock-adjustments',
        'inventory.stock-adjustments-print',
    ],

    'updated_at' => '2026-08-07',
];
