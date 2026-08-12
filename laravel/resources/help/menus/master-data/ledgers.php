<?php

/**
 * Help content for: master-data.ledgers
 * Route: admin.ledgers.index (and create/show/edit via wildcard)
 *
 * The Chart of Accounts / Ledger master — every accounting head (asset,
 * liability, income, expense, equity) lives here, arranged as a parent-child
 * tree. Every journal entry in the ERP posts to one of these ledgers.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.3 (diagram)
 */

return [
    'key'        => 'master-data.ledgers',
    'module'     => 'master-data',
    'title_bn'   => 'লেজার',
    'title_en'   => 'Accounts',
    'icon'       => 'fa-book',
    'summary'    => 'হিসাবের চার্ট অফ অ্যাকাউন্টস — অ্যাসেট, দায়, আয়, ব্যয় সব খাত এখানে গাছের মতো সাজানো। প্রতিটা জার্নাল এন্ট্রি এই খাতাগুলোতে পড়ে।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-folder-plus',      'text' => 'নতুন লেজার খাতা তৈরি করা (নাম, কোড, গ্রুপ)'],
        ['icon' => 'fa-sitemap',          'text' => 'প্যারেন্ট-চাইল্ড গাছে সাজানো (group → ledger)'],
        ['icon' => 'fa-tag',              'text' => 'গ্রুপ নির্ধারণ — অ্যাসেট / দায় / আয় / ব্যয়'],
        ['icon' => 'fa-magnifying-glass',  'text' => 'নাম বা কোড দিয়ে খাতা খোঁজা'],
        ['icon' => 'fa-scale-balanced',    'text' => 'খাতার বর্তমান ব্যাল্যান্স দেখা'],
        ['icon' => 'fa-list-check',        'text' => 'অডিট লগ দেখা — কে কখন কী পরিবর্তন করেছে'],
    ],

    'impacts' => [
        ['who' => 'লেজার',         'what' => 'চার্ট-অফ-অ্যাকাউন্টস গাছে নতুন শাখা যোগ/আপডেট হয়'],
        ['who' => 'ট্রানজেকশন',     'what' => 'প্রতিটা জার্নাল এন্ট্রি এই খাতাগুলোতে পোস্ট হয়'],
        ['who' => 'রিপোর্ট',        'what' => 'ট্রায়াল ব্যাল্যান্স, পিএল, ব্যাল্যান্স শিট এই কাঠামো ধরে তৈরি হয়'],
    ],

    'cautions' => [
        'এন্ট্রি থাকা খাতা ডিলিট করবেন না — ইনঅ্যাক্টিভ করুন, তাহলে পুরোনো হিসাব ঠিক থাকে।',
        'প্যারেন্ট গ্রুপ বদলালে সব চাইল্ড খাতা রিপোর্টে জায়গা বদলায় — সাবধানে করুন।',
    ],

    'related' => ['accounting.manual-journals', 'master-data.banks', 'master-data.suppliers', 'master-data.customers', 'master-data.ledgers-audit'],

    'diagram' => 'chart-of-accounts-tree',

    'updated_at' => '2026-08-07',
];
