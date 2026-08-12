<?php

/**
 * Help content for: finance.consolidation-companies
 * Route: admin.consolidation.companies
 *
 * Companies sub-page — list of companies/branches included in consolidation,
 * with their mapping to group ledgers.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.2 (sub-page convention)
 */

return [
    'key'        => 'finance.consolidation-companies',
    'module'     => 'finance',
    'title_bn'   => 'Consolidation Companies',
    'title_en'   => 'Consolidation Companies',
    'icon'       => 'fa-building',
    'summary'    => 'কনসোলিডেশনে যুক্ত কোম্পানি বা ব্র্যাঞ্চগুলোর তালিকা — গ্রুপ লেজারে ম্যাপিং সহ।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-building',     'text' => 'যুক্ত কোম্পানি/ব্র্যাঞ্চ তালিকা দেখা'],
        ['icon' => 'fa-arrow-right-arrow-left', 'text' => 'কোম্পানি → গ্রুপ লেজার ম্যাপিং পরিবর্তন করা'],
    ],

    'impacts' => [
        ['who' => 'কনসোলিডেশন', 'what' => 'ম্যাপিং বদলালে পরবর্তী রানে গ্রুপ GL নতুন ম্যাপ ধরে হবে'],
    ],

    'cautions' => [
        'ম্যাপিং ভুল হলে কনসোলিডেটেড TB ভুল বসবে — আগের রানের সঙ্গে তুলনা করে যাচাই করুন।',
    ],

    'related' => ['finance.consolidation', 'finance.consolidation-rules'],

    'updated_at' => '2026-08-07',
];
