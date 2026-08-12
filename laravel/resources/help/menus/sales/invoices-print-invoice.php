<?php

/**
 * Help content for: sales.invoices-print-invoice
 * Route: admin.sales-invoices.print-invoice
 *
 * Sub-page of sales.invoices — print the customer-facing invoice copy.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.4 (sub-page convention)
 */

return [
    'key'        => 'sales.invoices-print-invoice',
    'module'     => 'sales',
    'title_bn'   => 'Print Invoice',
    'title_en'   => 'Print Invoice',
    'icon'       => 'fa-print',
    'summary'    => 'এটি সেলস ইনভয়েস-এর প্রিন্ট পেজ — খদ্দেরকে দেওয়ার জন্য ইনভয়েস কপি প্রিন্ট করা হয়।',

    'for_roles'  => ['manager', 'admin', 'superadmin', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-print',  'text' => 'ইনভয়েস কপি প্রিন্ট বা পিডিএফ ডাউনলোড করা'],
        ['icon' => 'fa-eye',    'text' => 'প্রিন্টের আগে ইনভয়েসের তথ্য যাচাই করা'],
    ],

    'impacts' => [
        ['who' => 'ইনভয়েস',  'what' => 'শুধু প্রিন্ট — কোনো হিসাব বদলায় না'],
    ],

    'cautions' => [
        'প্রিন্ট কপি ইনভয়েসের স্ন্যাপশট — ইনভয়েস রিটার্ন বা এডিট হলে নতুন করে প্রিন্ট করুন।',
    ],

    'related' => ['sales.invoices', 'sales.returns-print-slip'],

    'updated_at' => '2026-08-07',
];
