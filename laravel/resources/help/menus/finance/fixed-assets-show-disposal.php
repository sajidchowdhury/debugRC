<?php

/**
 * Help content for: finance.fixed-assets-show-disposal
 * Route: admin.fixed-assets.show-disposal
 *
 * Disposal Detail sub-page — single disposal record (asset, date, sale price,
 * NBV at disposal, gain/loss, generated journal reference).
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.2 (sub-page convention)
 */

return [
    'key'        => 'finance.fixed-assets-show-disposal',
    'module'     => 'finance',
    'title_bn'   => 'Disposal Detail',
    'title_en'   => 'Disposal Detail',
    'icon'       => 'fa-eye',
    'summary'    => 'একটি ডিসপোজালের পুরো ডিটেইল — অ্যাসেট, বিক্রয়মূল্য, নিট বুক ভ্যালু, লাভ-ক্ষতি, জার্নাল।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-eye',         'text' => 'নির্দিষ্ট ডিসপোজালের হিসাব দেখা'],
        ['icon' => 'fa-file-export',  'text' => 'ডিটেইল রিপোর্ট এক্সপোর্ট করা'],
    ],

    'impacts' => [
        ['who' => 'রিপোর্ট', 'what' => 'শুধু রিড-অনলি — কোনো অ্যাসেট বা GL বদলায় না'],
    ],

    'cautions' => [
        'এখান থেকে ডিসপোজাল বদলানো যায় না — কারেকশন দরকার হলে fixed-assets-disposals পেজে যান।',
    ],

    'related' => ['finance.fixed-assets-disposals', 'finance.fixed-assets'],

    'updated_at' => '2026-08-07',
];
