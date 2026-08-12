<?php

/**
 * Help content for: accounting.supplier-transactions-slip
 * Route: admin.supplier-transactions.slip
 *
 * Printable slip for a single supplier transaction — payment voucher for
 * hardcopy or PDF export.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.2 (sub-page convention)
 */

return [
    'key'        => 'accounting.supplier-transactions-slip',
    'module'     => 'accounting',
    'title_bn'   => 'সাপ্লায়ার পেমেন্ট স্লিপ',
    'title_en'   => 'Supplier Payment Slip',
    'icon'       => 'fa-receipt',
    'summary'    => 'এটি supplier-transactions-এর প্রিন্ট স্লিপ — সাপ্লায়ার পেমেন্টের ভাউচার প্রিন্ট বা পিডিএফ হিসেবে নেওয়া যায়।',

    'for_roles'  => ['admin', 'superadmin', 'accountant', 'manager'],

    'what_you_can_do' => [
        ['icon' => 'fa-print',  'text' => 'স্লিপ প্রিন্ট বা পিডিএফ ডাউনলোড করা'],
        ['icon' => 'fa-eye',    'text' => 'প্রিন্টের আগে প্রিভিউ দেখা'],
    ],

    'impacts' => [
        ['who' => 'রিপোর্ট', 'what' => 'বর্তমান এন্ট্রি থেকে স্লিপ তৈরি হয় — রিড-অনলি'],
    ],

    'cautions' => [
        'প্রিন্টে যা দেখায়, তা সেই মুহূর্তের ডেটা — পরে এন্ট্রি বদলালে পুরোনো স্লিপ বদলায় না।',
    ],

    'related' => ['accounting.supplier-transactions', 'accounting.supplier-transactions-show'],

    'updated_at' => '2026-08-07',
];
