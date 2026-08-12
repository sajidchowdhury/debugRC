<?php

/**
 * Help content for: finance.branch-demand-price-range-comparison
 * Route: admin.branch-demands.price-range-comparison
 *
 * Price range comparison sub-page — compare prices across demands/branches
 * to spot anomalies (branches ordering at widely different unit prices).
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.2 (sub-page convention)
 */

return [
    'key'        => 'finance.branch-demand-price-range-comparison',
    'module'     => 'finance',
    'title_bn'   => 'Price Range Comparison',
    'title_en'   => 'Price Range Comparison',
    'icon'       => 'fa-arrows-up-down',
    'summary'    => 'ব্র্যাঞ্চগুলোর ডিমান্ড প্রাইস পাশাপাশি মেলান — কোথায় দাম বেশি/কম তা ধরা যায়।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-arrows-up-down', 'text' => 'ব্র্যাঞ্চ-ওয়াইজ দামের পার্থক্য পাশাপাশি দেখা'],
        ['icon' => 'fa-triangle-exclamation', 'text' => 'অস্বাভাবিক দামের ব্যবধান চিহ্নিত করা'],
    ],

    'impacts' => [
        ['who' => 'রিপোর্ট', 'what' => 'শুধু তুলনা ভিউ — মূল ডিমান্ড বা দাম বদলায় না'],
    ],

    'cautions' => [
        'এখান থেকে দাম বদলানো যায় না — পণ্যের দাম বদলাতে মাস্টার-ডেটা প্রোডাক্ট পেজে যান।',
    ],

    'related' => ['finance.branch-demand', 'master-data.products'],

    'updated_at' => '2026-08-07',
];
