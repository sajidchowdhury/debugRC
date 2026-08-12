<?php

/**
 * Help content for: accounting.other-expenses-show
 * Route: admin.other-expenses.show
 *
 * Detail view of a single other-expense entry — expense ledger, amount,
 * payment account, narration, and the auto-posted journal.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.2 (sub-page convention)
 */

return [
    'key'        => 'accounting.other-expenses-show',
    'module'     => 'accounting',
    'title_bn'   => 'অন্যান্য খরচ বিস্তারিত',
    'title_en'   => 'Other Expense Detail',
    'icon'       => 'fa-eye',
    'summary'    => 'এটি other-expenses-এর বিস্তারিত ভিউ — খরচের খাতা, অ্যামাউন্ট, পেমেন্ট অ্যাকাউন্ট, ন্যারেশন দেখা যায়।',

    'for_roles'  => ['admin', 'superadmin', 'accountant', 'manager'],

    'what_you_can_do' => [
        ['icon' => 'fa-eye',   'text' => 'খরচ এন্ট্রির বিস্তারিত দেখা'],
        ['icon' => 'fa-book',  'text' => 'অটো-পোস্টেড জার্নাল লাইন দেখা'],
    ],

    'impacts' => [
        ['who' => 'রিপোর্ট', 'what' => 'বিস্তারিত ভিউ রিড-অনলি — কোনো এন্ট্রি বদলায় না'],
    ],

    'cautions' => [
        'শুধু দেখার জন্য — ভুল ধরতে হলে নতুন রিভার্সিং এন্ট্রি দিতে হবে।',
    ],

    'related' => ['accounting.other-expenses', 'accounting.other-expenses-slip'],

    'updated_at' => '2026-08-07',
];
