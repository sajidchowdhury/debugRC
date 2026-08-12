<?php

/**
 * Help content for: master-data.banks-print
 * Route: admin.banks.print
 *
 * Printable directory view of all banks — for hardcopy or PDF export of the
 * current bank list.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.2 (sub-page convention)
 */

return [
    'key'        => 'master-data.banks-print',
    'module'     => 'master-data',
    'title_bn'   => 'ব্যাংক ডিরেক্টরি প্রিন্ট',
    'title_en'   => 'Bank Directory Print',
    'icon'       => 'fa-print',
    'summary'    => 'ব্যাংক ডিরেক্টরি প্রিন্ট করার ভিউ। বর্তমান তালিকা প্রিন্ট বা পিডিএফ হিসেবে নেওয়া যায়।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-print',        'text' => 'ডিরেক্টরি প্রিন্ট বা পিডিএফ ডাউনলোড করা'],
        ['icon' => 'fa-filter',        'text' => 'ফিল্টার ধরে প্রিন্ট করা'],
    ],

    'impacts' => [
        ['who' => 'রিপোর্ট',  'what' => 'বর্তমান তালিকা থেকে প্রিন্ট তৈরি হয়'],
    ],

    'cautions' => [
        'প্রিন্টে যা দেখায়, তা বর্তমান ডেটা থেকে — লাইভ নয়, পরে ডেটা বদলালে পুরোনো প্রিন্ট বদলায় না।',
    ],

    'related' => ['master-data.banks', 'master-data.banks-audit'],

    'updated_at' => '2026-08-07',
];
