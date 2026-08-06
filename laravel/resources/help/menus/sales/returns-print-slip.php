<?php

/**
 * Help content for: sales.returns-print-slip
 * Route: admin.sales-returns.print-slip
 *
 * Sub-page of sales.returns — print the return slip (credit-note slip)
 * given to the customer when goods are returned.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.4 (sub-page convention)
 */

return [
    'key'        => 'sales.returns-print-slip',
    'module'     => 'sales',
    'title_bn'   => 'Print Return Slip',
    'title_en'   => 'Print Return Slip',
    'icon'       => 'fa-print',
    'summary'    => 'এটি সেলস রিটার্ন-এর স্লিপ প্রিন্ট পেজ — খদ্দেরকে দেওয়ার জন্য রিটার্ন স্লিপ প্রিন্ট করা হয়।',

    'for_roles'  => ['manager', 'admin', 'superadmin', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-print',  'text' => 'রিটার্ন স্লিপ প্রিন্ট বা পিডিএফ ডাউনলোড করা'],
        ['icon' => 'fa-eye',    'text' => 'প্রিন্টের আগে রিটার্নের তথ্য যাচাই করা'],
    ],

    'impacts' => [
        ['who' => 'স্লিপ',  'what' => 'শুধু প্রিন্ট — কোনো হিসাব বদলায় না'],
    ],

    'cautions' => [
        'প্রিন্ট কপি রিটার্নের স্ন্যাপশট — রিটার্ন বদলালে নতুন করে স্লিপ প্রিন্ট করুন।',
    ],

    'related' => ['sales.returns', 'sales.invoices-print-invoice'],

    'updated_at' => '2026-08-07',
];
