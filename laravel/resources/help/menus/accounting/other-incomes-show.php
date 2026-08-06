<?php

/**
 * Help content for: accounting.other-incomes-show
 * Route: admin.other-incomes.show
 *
 * Detail view of a single other-income entry — income ledger, amount,
 * receiving account, narration, and the auto-posted journal.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.2 (sub-page convention)
 */

return [
    'key'        => 'accounting.other-incomes-show',
    'module'     => 'accounting',
    'title_bn'   => 'অন্যান্য আয় বিস্তারিত',
    'title_en'   => 'Other Income Detail',
    'icon'       => 'fa-eye',
    'summary'    => 'এটি other-incomes-এর বিস্তারিত ভিউ — আয়ের খাতা, অ্যামাউন্ট, রিসিভ অ্যাকাউন্ট, ন্যারেশন দেখা যায়।',

    'for_roles'  => ['admin', 'superadmin', 'accountant', 'manager'],

    'what_you_can_do' => [
        ['icon' => 'fa-eye',   'text' => 'আয় এন্ট্রির বিস্তারিত দেখা'],
        ['icon' => 'fa-book',  'text' => 'অটো-পোস্টেড জার্নাল লাইন দেখা'],
    ],

    'impacts' => [
        ['who' => 'রিপোর্ট', 'what' => 'বিস্তারিত ভিউ রিড-অনলি — কোনো এন্ট্রি বদলায় না'],
    ],

    'cautions' => [
        'শুধু দেখার জন্য — ভুল ধরতে হলে নতুন রিভার্সিং এন্ট্রি দিতে হবে।',
    ],

    'related' => ['accounting.other-incomes', 'accounting.other-incomes-slip'],

    'updated_at' => '2026-08-07',
];
