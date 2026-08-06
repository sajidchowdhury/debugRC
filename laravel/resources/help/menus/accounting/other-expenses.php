<?php

/**
 * Help content for: accounting.other-expenses
 * Route: admin.other-expenses.index (and show/audit/slip via wildcard)
 *
 * The Other Expense page — non-purchase expenses (utility bill, office rent,
 * stationery, conveyance, bank charges, etc.). Each posting debits an expense
 * ledger and credits cash/bank.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema)
 */

return [
    'key'        => 'accounting.other-expenses',
    'module'     => 'accounting',
    'title_bn'   => 'অন্যান্য খরচ',
    'title_en'   => 'Other Expense',
    'icon'       => 'fa-circle-minus',
    'summary'    => 'কেনা ছাড়া অন্য খরচ — ইউটিলিটি, ভাড়া, স্টেশনারি, ব্যাংক চার্জ — এখানে লেখা হয়, খরচ লেজারে ডেবিট আর ক্যাশ/ব্যাংকে ক্রেডিট।',

    'for_roles'  => ['admin', 'superadmin', 'accountant', 'manager'],

    'what_you_can_do' => [
        ['icon' => 'fa-circle-minus',     'text' => 'নতুন খরচ এন্ট্রি — কোন খরচের খাতা, কত টাকা, কোন অ্যাকাউন্ট থেকে গেল'],
        ['icon' => 'fa-magnifying-glass', 'text' => 'আগের খরচ খুঁজে দেখা (তারিখ, খাতা ধরে)'],
        ['icon' => 'fa-receipt',          'text' => 'খরচের স্লিপ/ভাউচার প্রিন্ট করা'],
        ['icon' => 'fa-eye',              'text' => 'এন্ট্রির বিস্তারিত ভিউ দেখা'],
        ['icon' => 'fa-list-check',       'text' => 'অডিট লগ দেখা — কে কখন খরচ এন্ট্রি করেছে'],
    ],

    'impacts' => [
        ['who' => 'খরচের লেজার', 'what' => 'ডেবিট হয় — খরচ বাড়ে'],
        ['who' => 'ক্যাশ/ব্যাংক', 'what' => 'ক্রেডিট হয় — ব্যাল্যান্স কমে'],
        ['who' => 'হিসাব',       'what' => 'ব্যাল্যান্সড জার্নাল অটো-পোস্ট হয়'],
        ['who' => 'অডিট',       'what' => 'প্রতিটা এন্ট্রি অডিট লগে লেখা থাকে'],
    ],

    'cautions' => [
        'কেনা মালের খরচ এখানে এন্ট্রি করবেন না — পারচেজ রিসিভ থেকে অটো-পোস্ট হয়। এখানে শুধু অন্যান্য খরচ।',
        'ভ্যাট/ট্যাক্স-যোগ্য খরচ ঠিক খাতায় বাছাই করুন — ভুল হলে ভ্যাট রিপোর্ট ভুল হবে।',
        'একবার পোস্ট হলে এডিট করা যায় না — রিভার্স করতে নতুন এন্ট্রি দিতে হবে।',
    ],

    'related' => ['master-data.ledgers', 'master-data.banks', 'accounting.other-expenses-slip', 'accounting.other-expenses-audit', 'reports.reports-hub-trialBalance'],

    'updated_at' => '2026-08-07',
];
