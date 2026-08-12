<?php

/**
 * Help content for: inventory.damages-print
 * Route: admin.damages.print
 *
 * Printable damage slip sub-page — the document that records damaged
 * goods, reason, qty, and value for approval/audit.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.4 (sub-page)
 */

return [
    'key'        => 'inventory.damages-print',
    'module'     => 'inventory',
    'title_bn'   => 'Damage Print',
    'title_en'   => 'Damage Print',
    'icon'       => 'fa-print',
    'summary'    => 'এটি ক্ষতি এন্ট্রির প্রিন্ট ভিউ পেজ।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-print',    'text' => 'ক্ষতির চালান প্রিন্ট করা'],
        ['icon' => 'fa-file-pdf', 'text' => 'পিডিএফ হিসেবে ডাউনলোড করা'],
    ],

    'impacts' => [
        ['who' => 'প্রিন্ট',  'what' => 'শুধু আউটপুট — কোনো তথ্য বদলে না'],
    ],

    'cautions' => [
        'শুধু প্রিন্টের জন্য — সরাসরি তথ্য বদলানো যায় না।',
    ],

    'related' => [
        'inventory.damages',
        'inventory.damages-show',
    ],

    'updated_at' => '2026-08-07',
];
