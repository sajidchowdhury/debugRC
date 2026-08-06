<?php

/**
 * Help content for: accounting.other-expenses-slip
 * Route: admin.other-expenses.slip
 *
 * Printable slip for a single other-expense entry — expense voucher for
 * hardcopy or PDF export.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.2 (sub-page convention)
 */

return [
    'key'        => 'accounting.other-expenses-slip',
    'module'     => 'accounting',
    'title_bn'   => 'অন্যান্য খরচ স্লিপ',
    'title_en'   => 'Other Expense Slip',
    'icon'       => 'fa-receipt',
    'summary'    => 'এটি other-expenses-এর প্রিন্ট স্লিপ — খরচের ভাউচার প্রিন্ট বা পিডিএফ হিসেবে নেওয়া যায়।',

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

    'related' => ['accounting.other-expenses', 'accounting.other-expenses-show'],

    'updated_at' => '2026-08-07',
];
