<?php

/**
 * Help content for: system.users-print
 * Route: admin.users.print (uri: admin/users/print)
 *
 * Printable directory view of all user accounts — for hardcopy or PDF
 * export of the current user list (with roles and active status).
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.2 (sub-page convention)
 */

return [
    'key'        => 'system.users-print',
    'module'     => 'system',
    'title_bn'   => 'User Directory Print',
    'title_en'   => 'User Directory Print',
    'icon'       => 'fa-print',
    'summary'    => 'ইউজার ডিরেক্টরি প্রিন্ট করার ভিউ — বর্তমান তালিকা প্রিন্ট বা পিডিএফ হিসেবে নেওয়া যায়।',

    'for_roles'  => ['admin', 'superadmin'],

    'what_you_can_do' => [
        ['icon' => 'fa-print',     'text' => 'ইউজার ডিরেক্টরি প্রিন্ট বা পিডিএফ ডাউনলোড করা'],
        ['icon' => 'fa-filter',   'text' => 'রোল বা অ্যাক্টিভ স্ট্যাটাস ধরে প্রিন্ট করা'],
    ],

    'impacts' => [
        ['who' => 'রিপোর্ট',  'what' => 'বর্তমান তালিকা থেকে প্রিন্ট তৈরি হয় — ডেটা বদলায় না'],
    ],

    'cautions' => [
        'প্রিন্টে যা দেখায়, তা বর্তমান ডেটা থেকে — লাইভ নয়; পরে ডেটা বদলালে পুরোনো প্রিন্ট বদলায় না।',
    ],

    'related' => ['system.users', 'system.users-audit'],

    'updated_at' => '2026-08-07',
];
