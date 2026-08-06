<?php

/**
 * Help content for: inventory.damages-view-attachment
 * Route: admin.damages.attachments.view
 *
 * View sub-page — in-browser preview of the photo or document attached
 * to a damage entry, without downloading.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.4 (sub-page)
 */

return [
    'key'        => 'inventory.damages-view-attachment',
    'module'     => 'inventory',
    'title_bn'   => 'View Attachment',
    'title_en'   => 'View Attachment',
    'icon'       => 'fa-paperclip',
    'summary'    => 'এটি ক্ষতি এন্ট্রির সংযুক্ত ফাইল ব্রাউজারে দেখার পেজ।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-paperclip',  'text' => 'ছবি/ডকুমেন্ট ব্রাউজারে প্রিভিউ করা'],
        ['icon' => 'fa-eye',        'text' => 'ফাইল ডাউনলোড না করেই দেখা'],
    ],

    'impacts' => [
        ['who' => 'প্রমাণ',  'what' => 'শুধু প্রিভিউ — কোনো তথ্য বদলে না'],
    ],

    'cautions' => [
        'শুধু দেখার জন্য — সরাসরি তথ্য বদলানো যায় না।',
    ],

    'related' => [
        'inventory.damages',
        'inventory.damages-download-attachment',
    ],

    'updated_at' => '2026-08-07',
];
