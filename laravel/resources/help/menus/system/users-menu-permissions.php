<?php

/**
 * Help content for: system.users-menu-permissions
 * Route: admin.users.menu-permissions (uri: admin/users/{user}/menu-permissions)
 *
 * Per-user menu-permission editor — which sidebar menus this specific user
 * is allowed to see and open, on top of (or restricted from) their role.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.2 (sub-page convention)
 */

return [
    'key'        => 'system.users-menu-permissions',
    'module'     => 'system',
    'title_bn'   => 'User Menu Permissions',
    'title_en'   => 'User Menu Permissions',
    'icon'       => 'fa-user-shield',
    'summary'    => 'একজন ইউজার কোন কোন মেনু দেখতে পারবে — রোলের ওপর অ্যাড-হক পারমিশন এখানে সেট করা যায়।',

    'for_roles'  => ['admin', 'superadmin'],

    'what_you_can_do' => [
        ['icon' => 'fa-user-shield',   'text' => 'ইউজারের দৃশ্যমান মেনু তালিকা চালু/বন্ধ করা'],
        ['icon' => 'fa-list-check',    'text' => 'রোল-লেভেল পারমিশনের ওপর ইউজার-লেভেল ওভাররাইড দেওয়া'],
        ['icon' => 'fa-floppy-disk',   'text' => 'পরিবর্তন সেভ করে পরবর্তী লগইন থেকে কার্যকর করা'],
    ],

    'impacts' => [
        ['who' => 'ইউজার',       'what' => 'পরবর্তী লগইন থেকে তার সাইডবার ও অ্যাক্সেস বদলায়'],
        ['who' => 'পারমিশন',    'what' => 'রোলে যা থাকলেও ইউজার-লেভেলে সরিয়ে নেওয়া যায়'],
        ['who' => 'অডিট লগ',   'what' => 'পারমিশন বদল অডিট লগে লেখা পড়ে'],
    ],

    'cautions' => [
        'পারমিশন বদল করলে ইউজারকে লগআউট করে আবার লগইন করতে বলুন — নতুন পারমিশন পুরোপুরি কার্যকর হবে পরবর্তী সেশন থেকে।',
    ],

    'related' => ['system.users', 'system.users-security-audit', 'system.users-audit'],

    'updated_at' => '2026-08-07',
];
