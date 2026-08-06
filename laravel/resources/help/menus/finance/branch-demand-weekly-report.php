<?php

/**
 * Help content for: finance.branch-demand-weekly-report
 * Route: admin.branch-demands.weekly-report
 *
 * Weekly report sub-page — branch demand summarised by week (qty approved,
 * allocated, pending, received). Drill-down into a week for line detail.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.2 (sub-page convention)
 */

return [
    'key'        => 'finance.branch-demand-weekly-report',
    'module'     => 'finance',
    'title_bn'   => 'ব্র্যাঞ্চ ডিমান্ড উইকলি রিপোর্ট',
    'title_en'   => 'Branch Demand Weekly',
    'icon'       => 'fa-calendar-week',
    'summary'    => 'সপ্তাহ ধরে ব্র্যাঞ্চ ডিমান্ডের সারাংশ — কত অনুমোদন, বরাদ্দ, পেন্ডিং, রিসিভ হয়েছে।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-calendar-week',      'text' => 'সাপ্তাহিক ডিমান্ড সারাংশ দেখা ও ফিল্টার করা'],
        ['icon' => 'fa-magnifying-glass-chart', 'text' => 'নির্দিষ্ট সপ্তাহে ড্রিল-ডাউন করে লাইন ডিটেইল দেখা'],
    ],

    'impacts' => [
        ['who' => 'রিপোর্ট', 'what' => 'শুধু রিড-অনলি ভিউ — কোনো ডিমান্ড বা GL বদলায় না'],
    ],

    'cautions' => [
        'রিপোর্ট ডেটা সবসময় লাইভ নাও থাকতে পারে — ক্যাশ রিফ্রেশ দরকার হলে মূল পেজ থেকে ট্রিগার করুন।',
    ],

    'related' => [
        'finance.branch-demand',
        'finance.branch-demand-weekly-report-drill-down',
    ],

    'updated_at' => '2026-08-07',
];
