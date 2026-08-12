<?php

/**
 * Help content for: finance.branch-demand-shadow-comparison-detail
 * Route: admin.branch-demand-shadow.detail
 *
 * Drill-down sub-page — single shadow comparison: row-by-row diff between
 * shadow output and live (or old-logic) output.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.2 (sub-page convention)
 */

return [
    'key'        => 'finance.branch-demand-shadow-comparison-detail',
    'module'     => 'finance',
    'title_bn'   => 'Branch Demand Shadow Comparison Detail',
    'title_en'   => 'Branch Demand Shadow Comparison Detail',
    'icon'       => 'fa-magnifying-glass',
    'summary'    => 'একটি শ্যাডো কম্পারিজনের সারিতে সারিতে পার্থক্য — কোথায় সংখ্যা মেলেনি তা দেখা।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-magnifying-glass', 'text' => 'প্রতিটি ডিমান্ড লাইনের শ্যাডো বনাম লাইভ পার্থক্য দেখা'],
        ['icon' => 'fa-triangle-exclamation', 'text' => 'মিসম্যাচ হলে কারণ ট্র্যাক করা'],
    ],

    'impacts' => [
        ['who' => 'বিশ্লেষণ', 'what' => 'শুধু দেখার ভিউ — কোনো GL বা স্টক বদলায় না'],
    ],

    'cautions' => [
        'শ্যাডো ডিটেইল পরীক্ষার জন্য — এখান থেকে কাটওভার চালানো যায় না।',
    ],

    'related' => [
        'finance.branch-demand-shadow',
        'finance.branch-demand-shadow-comparisons',
    ],

    'updated_at' => '2026-08-07',
];
