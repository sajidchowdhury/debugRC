<?php

/**
 * Help content for: system.system-health
 * Route: admin.system-health.index
 *
 * The System Health dashboard — overall status of DB, cache, disk, queue,
 * workers, and key background jobs. Green/yellow/red traffic-light view
 * so the admin can spot trouble before users complain.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema)
 */

return [
    'key'        => 'system.system-health',
    'module'     => 'system',
    'title_bn'   => 'সিস্টেম হেলথ',
    'title_en'   => 'System Health',
    'icon'       => 'fa-heart-pulse',
    'summary'    => 'ডেটাবেস, ক্যাশ, ডিস্ক, কিউ, ওয়ার্কার — পুরো সিস্টেমের হেলথ এক ড্যাশবোর্ডে; লাল দেখলে দ্রুত খুঁজুন।',

    'for_roles'  => ['admin', 'superadmin', 'manager'],

    'what_you_can_do' => [
        ['icon' => 'fa-heart-pulse',          'text' => 'ডেটাবেস, ক্যাশ, ডিস্ক, কিউ হেলথ এক নজরে দেখা'],
        ['icon' => 'fa-list-check',           'text' => 'ব্যাকগ্রাউন্ড জব ও ওয়ার্কার স্ট্যাটাস দেখা'],
        ['icon' => 'fa-triangle-exclamation', 'text' => 'চলমান অ্যালার্ট ও সতর্কতা দেখা'],
        ['icon' => 'fa-clock-rotate-left',    'text' => 'সাম্প্রতিক হেলথ হিস্ট্রি দেখা'],
        ['icon' => 'fa-bell',                 'text' => 'হেলথ অ্যালার্ট নোটিফিকেশন চালু/বন্ধ করা'],
    ],

    'impacts' => [
        ['who' => 'পর্যবেক্ষণ',     'what' => 'রিড-অনলি ড্যাশবোর্ড — সরাসরি কোনো ডেটা বদলায় না'],
        ['who' => 'সিদ্ধান্ত',      'what' => 'লাল স্ট্যাটাস ধরা পড়লে দ্রুত তদন্ত শুরু করা যায়'],
        ['who' => 'পার্টিশন হেলথ',  'what' => 'পার্টিশন সমস্যা এখানে রিফ্লেক্ট হয়'],
    ],

    'cautions' => [
        'লাল স্ট্যাটাস দেখলে তৎক্ষণিত খুঁজে বের করুন — অমীমাংসিত থাকলে ইউজার লগইন/রিপোর্ট আটকে যেতে পারে।',
        'ড্যাশবোর্ড তাজা করতে পেজ রিলোড দিন — পুরোনো স্ট্যাটাস দেখে সিদ্ধান্ত নেবেন না।',
    ],

    'related' => ['system.partition-health', 'system.sse-status', 'system.audit', 'system.compliance'],

    'updated_at' => '2026-08-07',
];
