<?php

/**
 * Help content for: master-data.ledgers-print
 * Route: admin.ledgers.print
 *
 * Printable directory view of the chart of accounts — for hardcopy or PDF
 * export of the current ledger tree.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.2 (sub-page convention)
 */

return [
    'key'        => 'master-data.ledgers-print',
    'module'     => 'master-data',
    'title_bn'   => 'লেজার ডিরেক্টরি প্রিন্ট',
    'title_en'   => 'Ledger Directory Print',
    'icon'       => 'fa-print',
    'summary'    => 'চার্ট-অফ-অ্যাকাউন্টস প্রিন্ট করার ভিউ। বর্তমান গাছ প্রিন্ট বা পিডিএফ হিসেবে নেওয়া যায়।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-print',        'text' => 'চার্ট-অফ-অ্যাকাউন্টস প্রিন্ট বা পিডিএফ ডাউনলোড করা'],
        ['icon' => 'fa-filter',        'text' => 'গ্রুপ ধরে প্রিন্ট করা'],
    ],

    'impacts' => [
        ['who' => 'রিপোর্ট',  'what' => 'বর্তমান গাছ থেকে প্রিন্ট তৈরি হয়'],
    ],

    'cautions' => [
        'প্রিন্টে যা দেখায়, তা বর্তমান ডেটা থেকে — লাইভ নয়, পরে গাছ বদলালে পুরোনো প্রিন্ট বদলায় না।',
    ],

    'related' => ['master-data.ledgers', 'master-data.ledgers-audit'],

    'updated_at' => '2026-08-07',
];
