<?php

/**
 * Help content for: inventory.damages-download-attachment
 * Route: admin.damages.attachments.download
 *
 * Download sub-page — download the photo or document file attached
 * to a damage entry as proof.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.4 (sub-page)
 */

return [
    'key'        => 'inventory.damages-download-attachment',
    'module'     => 'inventory',
    'title_bn'   => 'Download Attachment',
    'title_en'   => 'Download Attachment',
    'icon'       => 'fa-download',
    'summary'    => 'এটি ক্ষতি এন্ট্রির সাথে যুক্ত ছবি/ডকুমেন্ট ডাউনলোড পেজ।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-download',  'text' => 'সংযুক্ত ফাইল ডাউনলোড করা'],
        ['icon' => 'fa-file-image', 'text' => 'ছবি বা ডকুমেন্ট লোকালে সেভ করা'],
    ],

    'impacts' => [
        ['who' => 'প্রমাণ',  'what' => 'ফাইল লোকালে সেভ হয় — মূল এন্ট্রি বদলে না'],
    ],

    'cautions' => [
        'শুধু ডাউনলোডের জন্য — সরাসরি তথ্য বদলানো যায় না।',
    ],

    'related' => [
        'inventory.damages',
        'inventory.damages-view-attachment',
    ],

    'updated_at' => '2026-08-07',
];
