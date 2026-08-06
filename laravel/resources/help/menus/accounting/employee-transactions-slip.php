<?php

/**
 * Help content for: accounting.employee-transactions-slip
 * Route: admin.employee-transactions.slip
 *
 * Printable slip for a single employee transaction — salary/advance/payment
 * voucher for hardcopy or PDF export.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.2 (sub-page convention)
 */

return [
    'key'        => 'accounting.employee-transactions-slip',
    'module'     => 'accounting',
    'title_bn'   => 'কর্মচারী লেনদেন স্লিপ',
    'title_en'   => 'Employee Transaction Slip',
    'icon'       => 'fa-receipt',
    'summary'    => 'এটি employee-transactions-এর প্রিন্ট স্লিপ — বেতন/অগ্রিম/পেমেন্টের ভাউচার প্রিন্ট বা পিডিএফ হিসেবে নেওয়া যায়।',

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

    'related' => ['accounting.employee-transactions', 'accounting.employee-transactions-show'],

    'updated_at' => '2026-08-07',
];
