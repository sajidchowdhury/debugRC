<?php

/**
 * Help content for: finance.fixed-assets-disposals
 * Route: admin.fixed-assets.disposals
 *
 * Asset Disposals sub-page — list of every disposed asset (sold or scrapped),
 * with date, sale price, and gain/loss.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.2 (sub-page convention)
 */

return [
    'key'        => 'finance.fixed-assets-disposals',
    'module'     => 'finance',
    'title_bn'   => 'Asset Disposals',
    'title_en'   => 'Asset Disposals',
    'icon'       => 'fa-trash-can',
    'summary'    => 'বিক্রি বা স্ক্র্যাপ হওয়া অ্যাসেটের তালিকা — তারিখ, বিক্রয়মূল্য, লাভ-ক্ষতি সহ।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-trash-can', 'text' => 'নতুন ডিসপোজাল এন্ট্রি করা (বিক্রি/স্ক্র্যাপ)'],
        ['icon' => 'fa-eye',       'text' => 'পুরোনো ডিসপোজাল তালিকা দেখা'],
    ],

    'impacts' => [
        ['who' => 'হিসাব (GL)', 'what' => 'ডিসপোজাল হলে লাভ-ক্ষতি জার্নাল স্বয়ংক্রিয় তৈরি হয়'],
    ],

    'cautions' => [
        'বিক্রয়মূল্য ভুল দিলে লাভ-ক্ষতি জার্নাল ভুল বসবে — আগে নিশ্চিত হয়ে সেভ করুন।',
    ],

    'related' => ['finance.fixed-assets', 'finance.fixed-assets-show-disposal'],

    'updated_at' => '2026-08-07',
];
