<?php

/**
 * Help content for: accounting.money-transfers-show
 * Route: admin.money-transfers.show
 *
 * Detail view of a single money transfer — source account, destination
 * account, amount, narration, posting date, and the auto-posted journal.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.2 (sub-page convention)
 */

return [
    'key'        => 'accounting.money-transfers-show',
    'module'     => 'accounting',
    'title_bn'   => 'মানি ট্রান্সফার বিস্তারিত',
    'title_en'   => 'Money Transfer Detail',
    'icon'       => 'fa-eye',
    'summary'    => 'এটি money-transfers-এর বিস্তারিত ভিউ — সোর্স অ্যাকাউন্ট, ডেস্টিনেশন, অ্যামাউন্ট, ন্যারেশন দেখা যায়।',

    'for_roles'  => ['admin', 'superadmin', 'accountant', 'manager'],

    'what_you_can_do' => [
        ['icon' => 'fa-eye',         'text' => 'ট্রান্সফারের বিস্তারিত দেখা (সোর্স, ডেস্টিনেশন, অ্যামাউন্ট)'],
        ['icon' => 'fa-book',        'text' => 'অটো-পোস্টেড জার্নাল লাইন দেখা'],
    ],

    'impacts' => [
        ['who' => 'রিপোর্ট', 'what' => 'বিস্তারিত ভিউ রিড-অনলি — কোনো এন্ট্রি বদলায় না'],
    ],

    'cautions' => [
        'শুধু দেখার জন্য — ভুল ধরতে হলে নতুন রিভার্সিং ট্রান্সফার দিতে হবে।',
    ],

    'related' => ['accounting.money-transfers', 'accounting.money-transfers-slip'],

    'updated_at' => '2026-08-07',
];
