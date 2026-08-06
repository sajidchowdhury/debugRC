<?php

/**
 * Help content for: finance.branch-demand-shadow-comparisons
 * Route: admin.branch-demand-shadow.comparisons
 *
 * Shadow comparisons index — list of all shadow comparison runs (each row =
 * one parallel-run with summary diff count).
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.2 (sub-page convention)
 */

return [
    'key'        => 'finance.branch-demand-shadow-comparisons',
    'module'     => 'finance',
    'title_bn'   => 'Branch Demand Shadow Comparisons',
    'title_en'   => 'Branch Demand Shadow Comparisons',
    'icon'       => 'fa-table',
    'summary'    => 'ব্র্যাঞ্চ ডিমান্ডের সব শ্যাডো কম্পারিজন রানের তালিকা — কোনটিতে কত মিসম্যাচ।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-table',            'text' => 'সব শ্যাডো রান তালিকা দেখা ও ফিল্টার করা'],
        ['icon' => 'fa-magnifying-glass',  'text' => 'নির্দিষ্ট রানের ডিটেইলে ঢুকে পার্থক্য দেখা'],
    ],

    'impacts' => [
        ['who' => 'বিশ্লেষণ', 'what' => 'শুধু তালিকা ভিউ — কোনো GL বা স্টক বদলায় না'],
    ],

    'cautions' => [
        'শ্যাডো কম্পারিজন পরীক্ষার জন্য — কাটওভারের আগে সব মিসম্যাচ খুঁজে বের করুন।',
    ],

    'related' => [
        'finance.branch-demand-shadow',
        'finance.branch-demand-shadow-comparison-detail',
    ],

    'updated_at' => '2026-08-07',
];
