<?php

/**
 * Help content for: accounting.other-incomes
 * Route: admin.other-incomes.index (and show/audit/slip via wildcard)
 *
 * The Other Income page — non-sales income (bank interest, rent received,
 * commission earned, scrap sale, etc.). Each posting credits an income ledger
 * and debits cash/bank.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema)
 */

return [
    'key'        => 'accounting.other-incomes',
    'module'     => 'accounting',
    'title_bn'   => 'অন্যান্য আয়',
    'title_en'   => 'Other Income',
    'icon'       => 'fa-circle-plus',
    'summary'    => 'বিক্রি ছাড়া অন্য আয় — সুদ, ভাড়া, কমিশন, স্ক্র্যাপ সেল — এখানে লেখা হয়, আয় লেজারে ক্রেডিট আর ক্যাশ/ব্যাংকে ডেবিট।',

    'for_roles'  => ['admin', 'superadmin', 'accountant', 'manager'],

    'what_you_can_do' => [
        ['icon' => 'fa-circle-plus',      'text' => 'নতুন আয় এন্ট্রি — কোন আয়ের খাতা, কত টাকা, কোন অ্যাকাউন্টে এলো'],
        ['icon' => 'fa-magnifying-glass', 'text' => 'আগের আয় খুঁজে দেখা (তারিখ, খাতা ধরে)'],
        ['icon' => 'fa-receipt',          'text' => 'আয়ের স্লিপ/ভাউচার প্রিন্ট করা'],
        ['icon' => 'fa-eye',              'text' => 'এন্ট্রির বিস্তারিত ভিউ দেখা'],
        ['icon' => 'fa-list-check',       'text' => 'অডিট লগ দেখা — কে কখন আয় এন্ট্রি করেছে'],
    ],

    'impacts' => [
        ['who' => 'আয়ের লেজার',  'what' => 'ক্রেডিট হয় — আয় বাড়ে'],
        ['who' => 'ক্যাশ/ব্যাংক', 'what' => 'ডেবিট হয় — ব্যাল্যান্স বাড়ে'],
        ['who' => 'হিসাব',       'what' => 'ব্যাল্যান্সড জার্নাল অটো-পোস্ট হয়'],
        ['who' => 'অডিট',       'what' => 'প্রতিটা এন্ট্রি অডিট লগে লেখা থাকে'],
    ],

    'cautions' => [
        'বিক্রয় আয় এখানে এন্ট্রি করবেন না — বিক্রি সেলস ইনভয়েস থেকে অটো-পোস্ট হয়। এখানে শুধু অন্যান্য আয়।',
        'সঠিক আয়ের খাতা বাছাই করুন — ভুল খাতায় গেলে পিএল রিপোর্ট ভুল দেখায়।',
        'পেমেন্ট একবার পোস্ট হলে এডিট করা যায় না — রিভার্স করতে নতুন এন্ট্রি দিতে হবে।',
    ],

    'related' => ['master-data.ledgers', 'master-data.banks', 'accounting.other-incomes-slip', 'accounting.other-incomes-audit', 'reports.reports-hub-trialBalance'],

    'updated_at' => '2026-08-07',
];
