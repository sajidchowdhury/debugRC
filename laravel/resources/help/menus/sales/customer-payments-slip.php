<?php

/**
 * Help content for: sales.customer-payments-slip
 * Route: admin.customer-payments.slip
 *
 * Sub-page of sales.customer-payments — the compact payment slip view.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.4 (sub-page convention)
 */

return [
    'key'        => 'sales.customer-payments-slip',
    'module'     => 'sales',
    'title_bn'   => 'Payment Slip',
    'title_en'   => 'Payment Slip',
    'icon'       => 'fa-receipt',
    'summary'    => 'এটি কাস্টমার পেমেন্ট-এর স্লিপ পেজ — একটি নির্দিষ্ট পেমেন্টের সংক্ষিপ্ত স্লিপ দেখা ও প্রিন্ট করা হয়।',

    'for_roles'  => ['manager', 'admin', 'superadmin', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-eye',   'text' => 'পেমেন্টের সংক্ষিপ্ত স্লিপ দেখা'],
        ['icon' => 'fa-print', 'text' => 'স্লিপ প্রিন্ট করা'],
    ],

    'impacts' => [
        ['who' => 'স্লিপ',  'what' => 'শুধু দেখা/প্রিন্ট — কোনো হিসাব বদলায় না'],
    ],

    'cautions' => [
        'স্লিপ পেমেন্টের স্ন্যাপশট — পেমেন্ট বদলালে নতুন করে স্লিপ খুলুন।',
    ],

    'related' => ['sales.customer-payments', 'sales.customer-payments-print-receipt'],

    'updated_at' => '2026-08-07',
];
