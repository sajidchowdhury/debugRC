<?php

/**
 * Help content for: inventory.stock-adjustments-print
 * Route: admin.stock-adjustments.print
 *
 * Printable adjustment slip sub-page — the document that records a stock
 * correction (reason, qty, value, approver) for audit/physical filing.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.4 (sub-page)
 */

return [
    'key'        => 'inventory.stock-adjustments-print',
    'module'     => 'inventory',
    'title_bn'   => 'Stock Adjustment Print',
    'title_en'   => 'Stock Adjustment Print',
    'icon'       => 'fa-print',
    'summary'    => 'এটি স্টক অ্যাডজাস্টমেন্টের প্রিন্ট ভিউ পেজ।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-print',    'text' => 'অ্যাডজাস্টমেন্ট চালান প্রিন্ট করা'],
        ['icon' => 'fa-file-pdf', 'text' => 'পিডিএফ হিসেবে ডাউনলোড করা'],
    ],

    'impacts' => [
        ['who' => 'প্রিন্ট',  'what' => 'শুধু আউটপুট — কোনো তথ্য বদলে না'],
    ],

    'cautions' => [
        'শুধু প্রিন্টের জন্য — সরাসরি তথ্য বদলানো যায় না।',
    ],

    'related' => [
        'inventory.stock-adjustments',
        'inventory.stock-adjustments-show',
    ],

    'updated_at' => '2026-08-07',
];
