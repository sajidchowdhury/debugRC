<?php

/**
 * Help content for: accounting.money-transfers-slip
 * Route: admin.money-transfers.slip
 *
 * Printable slip for a single money transfer — cash/bank movement voucher
 * for hardcopy or PDF export.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.2 (sub-page convention)
 */

return [
    'key'        => 'accounting.money-transfers-slip',
    'module'     => 'accounting',
    'title_bn'   => 'মানি ট্রান্সফার স্লিপ',
    'title_en'   => 'Money Transfer Slip',
    'icon'       => 'fa-receipt',
    'summary'    => 'এটি money-transfers-এর প্রিন্ট স্লিপ — ক্যাশ/ব্যাংক ট্রান্সফারের ভাউচার প্রিন্ট বা পিডিএফ হিসেবে নেওয়া যায়।',

    'for_roles'  => ['admin', 'superadmin', 'accountant', 'manager'],

    'what_you_can_do' => [
        ['icon' => 'fa-print',  'text' => 'স্লিপ প্রিন্ট বা পিডিএফ ডাউনলোড করা'],
        ['icon' => 'fa-eye',    'text' => 'প্রিন্টের আগে প্রিভিউ দেখা'],
    ],

    'impacts' => [
        ['who' => 'রিপোর্ট', 'what' => 'বর্তমান ট্রান্সফার থেকে স্লিপ তৈরি হয় — রিড-অনলি'],
    ],

    'cautions' => [
        'প্রিন্টে যা দেখায়, তা সেই মুহূর্তের ডেটা — পরে ট্রান্সফার বদলালে পুরোনো স্লিপ বদলায় না।',
    ],

    'related' => ['accounting.money-transfers', 'accounting.money-transfers-show'],

    'updated_at' => '2026-08-07',
];
