<?php

/**
 * Help content for: accounting.employee-transactions-show
 * Route: admin.employee-transactions.show
 *
 * Detail view of a single employee transaction — salary/advance/payment line,
 * linked employee, GL impact, and the auto-posted journal.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.2 (sub-page convention)
 */

return [
    'key'        => 'accounting.employee-transactions-show',
    'module'     => 'accounting',
    'title_bn'   => 'কর্মচারী লেনদেন বিস্তারিত',
    'title_en'   => 'Employee Transaction Detail',
    'icon'       => 'fa-eye',
    'summary'    => 'এটি employee-transactions-এর বিস্তারিত ভিউ — একটা বেতন/অগ্রিম/পেমেন্ট এন্ট্রির সব লাইন আর জিএল ইমপ্যাক্ট দেখা যায়।',

    'for_roles'  => ['admin', 'superadmin', 'accountant', 'manager'],

    'what_you_can_do' => [
        ['icon' => 'fa-eye',      'text' => 'এন্ট্রির বিস্তারিত দেখা (কর্মচারী, অ্যামাউন্ট, ন্যারেশন)'],
        ['icon' => 'fa-book',     'text' => 'অটো-পোস্টেড জার্নাল লাইন দেখা'],
    ],

    'impacts' => [
        ['who' => 'রিপোর্ট', 'what' => 'বিস্তারিত ভিউ রিড-অনলি — কোনো এন্ট্রি বদলায় না'],
    ],

    'cautions' => [
        'শুধু দেখার জন্য — ভুল ধরতে হলে নতুন অ্যাডজাস্টমেন্ট এন্ট্রি দিতে হবে।',
    ],

    'related' => ['accounting.employee-transactions', 'accounting.employee-transactions-slip'],

    'updated_at' => '2026-08-07',
];
