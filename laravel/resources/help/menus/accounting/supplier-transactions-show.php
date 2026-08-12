<?php

/**
 * Help content for: accounting.supplier-transactions-show
 * Route: admin.supplier-transactions.show
 *
 * Detail view of a single supplier transaction — supplier, amount, payment
 * account, allocation against PO/receive, narration, and the auto-posted
 * journal.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.2 (sub-page convention)
 */

return [
    'key'        => 'accounting.supplier-transactions-show',
    'module'     => 'accounting',
    'title_bn'   => 'সাপ্লায়ার পেমেন্ট বিস্তারিত',
    'title_en'   => 'Supplier Payment Detail',
    'icon'       => 'fa-eye',
    'summary'    => 'এটি supplier-transactions-এর বিস্তারিত ভিউ — সাপ্লায়ার, অ্যামাউন্ট, পেমেন্ট অ্যাকাউন্ট, অ্যালোকেশন দেখা যায়।',

    'for_roles'  => ['admin', 'superadmin', 'accountant', 'manager'],

    'what_you_can_do' => [
        ['icon' => 'fa-eye',   'text' => 'পেমেন্ট এন্ট্রির বিস্তারিত দেখা (সাপ্লায়ার, অ্যামাউন্ট, অ্যালোকেশন)'],
        ['icon' => 'fa-book',  'text' => 'অটো-পোস্টেড জার্নাল লাইন দেখা'],
    ],

    'impacts' => [
        ['who' => 'রিপোর্ট', 'what' => 'বিস্তারিত ভিউ রিড-অনলি — কোনো এন্ট্রি বদলায় না'],
    ],

    'cautions' => [
        'শুধু দেখার জন্য — ভুল ধরতে হলে নতুন অ্যাডজাস্টমেন্ট দিতে হবে।',
    ],

    'related' => ['accounting.supplier-transactions', 'accounting.supplier-transactions-slip'],

    'updated_at' => '2026-08-07',
];
