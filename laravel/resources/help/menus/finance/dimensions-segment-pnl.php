<?php

/**
 * Help content for: finance.dimensions-segment-pnl
 * Route: admin.dimensions.segment-pnl
 *
 * Segment P&L sub-page — Profit & Loss sliced by dimension
 * (each segment = one column).
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.2 (sub-page convention)
 */

return [
    'key'        => 'finance.dimensions-segment-pnl',
    'module'     => 'finance',
    'title_bn'   => 'Segment P&L',
    'title_en'   => 'Segment P&L',
    'icon'       => 'fa-chart-bar',
    'summary'    => 'সেগমেন্ট অনুযায়ী প্রফিট-লস — কোন লাইন/ব্র্যাঞ্চ কত আয়-খরচ করছে।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-chart-bar',  'text' => 'সেগমেন্ট-ওয়াইজ প্রফিট-লস দেখা'],
        ['icon' => 'fa-file-export', 'text' => 'রিপোর্ট এক্সপোর্ট করা'],
    ],

    'impacts' => [
        ['who' => 'রিপোর্ট', 'what' => 'শুধু রিড-অনলি — কোনো GL বা ট্যাগ বদলায় না'],
    ],

    'cautions' => [
        'সেগমেন্ট ফাঁকা দেখালে ডাইমেনশন ট্যাগ দেওয়া হয়নি — finance.dimensions থেকে ট্যাগ করুন।',
    ],

    'related' => ['finance.dimensions', 'finance.dimensions-segment-bs'],

    'updated_at' => '2026-08-07',
];
