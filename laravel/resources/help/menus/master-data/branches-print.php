<?php

/**
 * Help content for: master-data.branches-print
 * Route: admin.branches.print
 *
 * Printable directory view of all branches — for hardcopy or PDF export of the
 * current branch list.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.2 (sub-page convention)
 */

return [
    'key'        => 'master-data.branches-print',
    'module'     => 'master-data',
    'title_bn'   => 'ব্র্যাঞ্চ ডিরেক্টরি প্রিন্ট',
    'title_en'   => 'Branch Directory Print',
    'icon'       => 'fa-print',
    'summary'    => 'ব্র্যাঞ্চ ডিরেক্টরি প্রিন্ট করার ভিউ। বর্তমান তালিকা প্রিন্ট বা পিডিএফ হিসেবে নেওয়া যায়।',

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

    'related' => ['master-data.branches', 'master-data.branches-audit'],

    'updated_at' => '2026-08-07',
];
