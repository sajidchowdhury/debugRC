<?php

/**
 * Help content for: sales.customer-payments-print-receipt
 * Route: admin.customer-payments.print-receipt
 *
 * Sub-page of sales.customer-payments — print the customer-facing money
 * receipt for a single payment.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.4 (sub-page convention)
 */

return [
    'key'        => 'sales.customer-payments-print-receipt',
    'module'     => 'sales',
    'title_bn'   => 'Print Payment Receipt',
    'title_en'   => 'Print Payment Receipt',
    'icon'       => 'fa-print',
    'summary'    => 'এটি কাস্টমার পেমেন্ট-এর রিসিট প্রিন্ট পেজ — খদ্দেরকে দেওয়ার জন্য মানি রিসিট প্রিন্ট করা হয়।',

    'for_roles'  => ['manager', 'admin', 'superadmin', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-print',  'text' => 'মানি রিসিট প্রিন্ট বা পিডিএফ ডাউনলোড করা'],
        ['icon' => 'fa-eye',    'text' => 'প্রিন্টের আগে পেমেন্টের তথ্য যাচাই করা'],
    ],

    'impacts' => [
        ['who' => 'রিসিট',  'what' => 'শুধু প্রিন্ট — কোনো হিসাব বদলায় না'],
    ],

    'cautions' => [
        'রিসিট প্রিন্টের মুহূর্তের স্ন্যাপশট — পেমেন্ট এডিট হলে আবার প্রিন্ট করতে হবে।',
    ],

    'related' => ['sales.customer-payments', 'sales.customer-payments-slip'],

    'updated_at' => '2026-08-07',
];
