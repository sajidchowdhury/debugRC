<?php

/**
 * Help content for: finance.branch-demand-weekly-report-drill-down
 * Route: admin.branch-demands.weekly-report.drill-down
 *
 * Drill-down sub-page — line-level detail for a single week's branch demand
 * summary (one branch × one product at a time).
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.2 (sub-page convention)
 */

return [
    'key'        => 'finance.branch-demand-weekly-report-drill-down',
    'module'     => 'finance',
    'title_bn'   => 'Weekly Report Drill-down',
    'title_en'   => 'Weekly Report Drill-down',
    'icon'       => 'fa-magnifying-glass-chart',
    'summary'    => 'সাপ্তাহিক রিপোর্টের কোনো একটি সেলে ক্লিক করলে লাইন ডিটেইল খোলে — ব্র্যাঞ্চ × পণ্য।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-magnifying-glass-chart', 'text' => 'নির্দিষ্ট সপ্তাহ × ব্র্যাঞ্চ × পণ্যের লাইন আইটেম দেখা'],
        ['icon' => 'fa-file-export',             'text' => 'ড্রিল-ডাউন ভিউ এক্সপোর্ট করা'],
    ],

    'impacts' => [
        ['who' => 'বিশ্লেষণ', 'what' => 'শুধু রিড-অনলি — কোনো ডিমান্ড বা GL বদলায় না'],
    ],

    'cautions' => [
        'ড্রিল-ডাউন ভিউ শুধু দেখার জন্য — এখান থেকে ডিমান্ড বদলানো যায় না।',
    ],

    'related' => [
        'finance.branch-demand-weekly-report',
        'finance.branch-demand',
    ],

    'updated_at' => '2026-08-07',
];
