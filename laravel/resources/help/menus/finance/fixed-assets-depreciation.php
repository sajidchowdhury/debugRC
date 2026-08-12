<?php

/**
 * Help content for: finance.fixed-assets-depreciation
 * Route: admin.fixed-assets.depreciation
 *
 * Depreciation Schedule sub-page — list of every asset with cost, accumulated
 * depreciation, net book value, and the monthly depreciation amount.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.2 (sub-page convention)
 */

return [
    'key'        => 'finance.fixed-assets-depreciation',
    'module'     => 'finance',
    'title_bn'   => 'Depreciation Schedule',
    'title_en'   => 'Depreciation Schedule',
    'icon'       => 'fa-arrow-trend-down',
    'summary'    => 'প্রতিটি অ্যাসেটের ক্রয়মূল্য, জমা অবচয়, নিট বুক ভ্যালু ও মাসিক অবচয় — এক ভিউতে।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-arrow-trend-down', 'text' => 'অ্যাসেট-ওয়াইজ অবচয় সিডিউল দেখা'],
        ['icon' => 'fa-play',              'text' => 'মাসিক অবচয় রান চালানো (জার্নাল পোস্ট হয়)'],
    ],

    'impacts' => [
        ['who' => 'হিসাব (GL)', 'what' => 'অবচয় রান চালালে ডেবিট-ক্রেডিট জার্নাল স্বয়ংক্রিয় পোস্ট হয়'],
    ],

    'cautions' => [
        'অবচয় রান একবার চালালে জার্নাল ফিরে যায় না — ভুল ধরলে রিভার্স জার্নাল দিতে হয়।',
    ],

    'related' => ['finance.fixed-assets', 'accounting.manual-journals'],

    'updated_at' => '2026-08-07',
];
