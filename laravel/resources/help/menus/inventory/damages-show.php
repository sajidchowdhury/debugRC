<?php

/**
 * Help content for: inventory.damages-show
 * Route: admin.damages.show
 *
 * Detail sub-page — read-only view of a single damage entry (product, qty,
 * reason, attached photo, approval status, GL link).
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.4 (sub-page)
 */

return [
    'key'        => 'inventory.damages-show',
    'module'     => 'inventory',
    'title_bn'   => 'Damage Detail',
    'title_en'   => 'Damage Detail',
    'icon'       => 'fa-eye',
    'summary'    => 'এটি ক্ষতি এন্ট্রির বিস্তারিত পেজ।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-eye',          'text' => 'একটি ক্ষতির পুরো বিবরণ দেখা'],
        ['icon' => 'fa-paperclip',    'text' => 'সংযুক্ত ছবি/ডকুমেন্ট দেখা'],
    ],

    'impacts' => [
        ['who' => 'পেজ',  'what' => 'শুধু দেখা যায় — কোনো তথ্য বদলে না'],
    ],

    'cautions' => [
        'শুধু দেখার জন্য — সরাসরি তথ্য বদলানো যায় না।',
    ],

    'related' => [
        'inventory.damages',
        'inventory.damages-view-attachment',
    ],

    'updated_at' => '2026-08-07',
];
