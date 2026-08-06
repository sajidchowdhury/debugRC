<?php

/**
 * Help content for: system.users
 * Route: admin.users.index (and create/show/edit via wildcard)
 *
 * The User master page — every login account of the ERP is created and managed
 * here. Users get roles, menu permissions, and active/inactive status; many are
 * linked back to a master-data.employees record.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema)
 */

return [
    'key'        => 'system.users',
    'module'     => 'system',
    'title_bn'   => 'ইউজার',
    'title_en'   => 'User',
    'icon'       => 'fa-user-gear',
    'summary'    => 'কে লগইন করতে পারবে, কোন রোলে, কোন মেনু দেখবে — এই সব ইউজার অ্যাকাউন্ট এখানে তৈরি ও নিয়ন্ত্রিত হয়।',

    'for_roles'  => ['admin', 'superadmin'],

    'what_you_can_do' => [
        ['icon' => 'fa-user-plus',       'text' => 'নতুন ইউজার তৈরি করা (নাম, ইমেইল, ফোন, পাসওয়ার্ড)'],
        ['icon' => 'fa-user-shield',     'text' => 'রোল অ্যাসাইন করা (admin / manager / accountant / salesman)'],
        ['icon' => 'fa-power-off',       'text' => 'অ্যাকাউন্ট অ্যাক্টিভেট বা ডিঅ্যাক্টিভেট করা'],
        ['icon' => 'fa-key',             'text' => 'পাসওয়ার্ড রিসেট করা (ইউজার ভুলে গেলে)'],
        ['icon' => 'fa-list-check',      'text' => 'মেনু পারমিশন সেট করা — কোন মেনু দেখতে পারবে'],
        ['icon' => 'fa-shield-halved',  'text' => 'ইউজারের সিকিউরিটি অডিট ও লগইন হিস্ট্রি দেখা'],
    ],

    'impacts' => [
        ['who' => 'লগইন',         'what' => 'অ্যাক্টিভ ইউজার অ্যাকাউন্ট লগইন করতে পারবে'],
        ['who' => 'পারমিশন',     'what' => 'রোল ও মেনু পারমিশন বদলালে কোন পেজ খোলে তা নির্ভর করে'],
        ['who' => 'অডিট লগ',     'what' => 'ইউজার তৈরি/বদল প্রতিটি অডিট লগে লেখা পড়ে'],
    ],

    'cautions' => [
        'ইউজার ডিলিট করবেন না — তার পুরোনো ইনভয়েস/অডিট ইতিহাস বাঁধা রাখতে ডিঅ্যাক্টিভ করুন।',
        'পাসওয়ার্ড রিসেট করলে ইউজারকে নতুন পাসওয়ার্ড একই সময়ে দিতে হবে — কনফার্ম করে তারপর রিসেট করুন।',
        'পারমিশন বদল করলে ইউজারকে লগআউট করে আবার লগইন করতে বলুন — নতুন পারমিশন পরবর্তী লগইন থেকে পুরোপুরি কাজ করবে।',
    ],

    'related' => ['system.users-menu-permissions', 'system.users-audit', 'system.users-security-audit', 'system.audit', 'master-data.employees'],

    // No diagram — a flat user-account list; fan-out picture lives on notifications.

    'updated_at' => '2026-08-07',
];
