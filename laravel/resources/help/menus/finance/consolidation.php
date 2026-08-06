<?php

/**
 * Help content for: finance.consolidation
 * Route: admin.consolidation.index (and companies/rules/create/show/reconciliation/consolidated-* sub-routes)
 *
 * The Consolidation page — combine the books of multiple companies / branches
 * into a single group view, apply elimination rules for intercompany balances,
 * and produce consolidated Trial Balance, Balance Sheet, and P&L.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.3 (diagram), Appendix A.9 (consolidation layout)
 */

return [
    'key'        => 'finance.consolidation',
    'module'     => 'finance',
    'title_bn'   => 'কনসলিডেশন',
    'title_en'   => 'Consolidation',
    'icon'       => 'fa-code-merge',
    'summary'    => 'একাধিক কোম্পানি/ব্র্যাঞ্চের বই এক জায়গায় মেলান, ইন্টারকোম্পানি এলিমিনেশন করে গ্রুপ রিপোর্ট তৈরি।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-building',          'text' => 'কনসোলিডেশনে যুক্ত কোম্পানি/ব্র্যাঞ্চ তালিকা দেখা'],
        ['icon' => 'fa-gears',             'text' => 'এলিমিনেশন রুল সেট করা (ইন্টারকোম্পানি পে-রিসিভবল মেটানো)'],
        ['icon' => 'fa-plus',              'text' => 'নতুন কনসোলিডেশন রান তৈরি করা'],
        ['icon' => 'fa-scale-balanced',     'text' => 'কনসোলিডেটেড ট্রায়াল ব্যাল্যান্স (TB) দেখা'],
        ['icon' => 'fa-table-list',         'text' => 'কনসোলিডেটেড ব্যালেন্স শিট ও প্রফিট-লস রিপোর্ট দেখা'],
        ['icon' => 'fa-handshake',         'text' => 'ইন্টারকোম্পানি রিকনসাইলিয়েশন চেক করা'],
    ],

    'impacts' => [
        ['who' => 'গ্রুপ হিসাব',     'what' => 'একাধিক কোম্পানির বই একত্র হয়ে গ্রুপ GL তৈরি হয়'],
        ['who' => 'এলিমিনেশন',      'what' => 'ইন্টারকোম্পানি পে-রিসিভবল বাতিল হয় — ডাবল কাউন্ট দূর হয়'],
        ['who' => 'রিপোর্ট',         'what' => 'কনসোলিডেটেড TB/BS/PnL এক ভিউতে দেখা যায়'],
        ['who' => 'ইন্টারকোম্পানি',   'what' => 'রিকনসাইলিয়েশন পেন্ডিং হলে কনসোলিডেশন অসম্পূর্ণ থাকে'],
    ],

    'cautions' => [
        'এলিমিনেশন রুল ব্যাল্যান্সড না হলে কনসোলিডেটেড TB মেলে না — রুল যাচাই করে রান করুন।',
        'সব ব্র্যাঞ্চ/কোম্পানির পিরিয়ড ক্লোজ হওয়ার পরেই কনসোলিডেশন চালান — মাঝখানে চালালে সংখ্যা বদলে যাবে।',
    ],

    'related' => [
        'finance.consolidation-companies',
        'finance.consolidation-rules',
        'finance.consolidation-create',
        'finance.consolidation-consolidated-tb',
        'finance.consolidation-intercompany-reconciliation',
    ],

    'diagram' => 'consolidation-flow',

    'updated_at' => '2026-08-07',
];
