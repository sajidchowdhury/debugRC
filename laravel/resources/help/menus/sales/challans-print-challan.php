<?php

/**
 * Help content for: sales.challans-print-challan
 * Route: admin.sales-challans.print-challan
 *
 * Sub-page of sales.challans — print the challan copy (goes with the
 * delivery vehicle).
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.4 (sub-page convention)
 */

return [
    'key'        => 'sales.challans-print-challan',
    'module'     => 'sales',
    'title_bn'   => 'Print Challan',
    'title_en'   => 'Print Challan',
    'icon'       => 'fa-print',
    'summary'    => 'এটি চালান-এর প্রিন্ট পেজ — চালান কপি প্রিন্ট করে গাড়িতে বা খদ্দেরকে দেওয়া হয়।',

    'for_roles'  => ['manager', 'admin', 'superadmin', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-print',  'text' => 'চালান কপি প্রিন্ট করা বা পিডিএফ ডাউনলোড করা'],
        ['icon' => 'fa-eye',    'text' => 'প্রিন্টের আগে চালানের তথ্য যাচাই করা'],
    ],

    'impacts' => [
        ['who' => 'চালান',  'what' => 'শুধু প্রিন্ট — কোনো হিসাব বদলায় না'],
    ],

    'cautions' => [
        'প্রিন্ট কপি সর্বদা প্রিন্টের মুহূর্তের স্ন্যাপশট — পরে চালান বদলালে আবার প্রিন্ট করতে হবে।',
    ],

    'related' => ['sales.challans', 'sales.invoices-print-godown'],

    'updated_at' => '2026-08-07',
];
