<?php

/**
 * Help content for: sales.invoices-print-godown
 * Route: admin.sales-invoices.print-godown
 *
 * Sub-page of sales.invoices — print the godown copy of a specific invoice
 * (lists the items the godown should release).
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.4 (sub-page convention)
 */

return [
    'key'        => 'sales.invoices-print-godown',
    'module'     => 'sales',
    'title_bn'   => 'Print Godown Copy',
    'title_en'   => 'Print Godown Copy',
    'icon'       => 'fa-print',
    'summary'    => 'এটি সেলস ইনভয়েস-এর গোডাউন কপি প্রিন্ট পেজ — গোডাউনে দেওয়ার জন্য পণ্য তালিকা প্রিন্ট করা হয়।',

    'for_roles'  => ['manager', 'admin', 'superadmin', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-print',  'text' => 'গোডাউন কপি প্রিন্ট বা পিডিএফ ডাউনলোড করা'],
        ['icon' => 'fa-eye',    'text' => 'প্রিন্টের আগে পণ্য তালিকা যাচাই করা'],
    ],

    'impacts' => [
        ['who' => 'গোডাউন',  'what' => 'শুধু প্রিন্ট — কোনো হিসাব বদলায় না'],
    ],

    'cautions' => [
        'প্রিন্ট কপি ইনভয়েসের স্ন্যাপশট — ইনভয়েস বদলালে আবার প্রিন্ট করতে হবে।',
    ],

    'related' => ['sales.invoices', 'sales.challans-print-challan'],

    'updated_at' => '2026-08-07',
];
