<?php

/**
 * Help content for: accounting.manual-journals-show
 * Route: admin.manual-journals.show
 *
 * Detail view of a single manual journal — all debit/credit lines, narration,
 * voucher number, posting date, and the linked GL impact.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.2 (sub-page convention)
 */

return [
    'key'        => 'accounting.manual-journals-show',
    'module'     => 'accounting',
    'title_bn'   => 'ম্যানুয়াল জার্নাল বিস্তারিত',
    'title_en'   => 'Manual Journal Detail',
    'icon'       => 'fa-eye',
    'summary'    => 'এটি manual-journals-এর বিস্তারিত ভিউ — সব ডেবিট-ক্রেডিট লাইন, ন্যারেশন, ভাউচার নম্বর দেখা যায়।',

    'for_roles'  => ['admin', 'superadmin', 'accountant', 'manager'],

    'what_you_can_do' => [
        ['icon' => 'fa-eye',         'text' => 'সব ডেবিট-ক্রেডিট লাইন দেখা'],
        ['icon' => 'fa-magnifying-glass', 'text' => 'লেজার খাতা ধরে ট্রেস করা'],
        ['icon' => 'fa-print',       'text' => 'জার্নাল ভাউচার প্রিন্ট করা'],
    ],

    'impacts' => [
        ['who' => 'রিপোর্ট', 'what' => 'বিস্তারিত ভিউ রিড-অনলি — কোনো এন্ট্রি বদলায় না'],
    ],

    'cautions' => [
        'শুধু দেখার জন্য — ভুল ধরতে হলে নতুন রিভার্সিং জার্নাল দিতে হবে।',
    ],

    'related' => ['accounting.manual-journals', 'accounting.manual-journals-audit'],

    'updated_at' => '2026-08-07',
];
