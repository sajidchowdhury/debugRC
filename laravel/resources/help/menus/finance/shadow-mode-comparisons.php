<?php

/**
 * Help content for: finance.shadow-mode-comparisons
 * Route: admin.shadow-mode.comparisons
 *
 * Shadow Comparisons index sub-page — list of all shadow comparison runs
 * (each row = one parallel-run with summary mismatch count).
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.2 (sub-page convention)
 */

return [
    'key'        => 'finance.shadow-mode-comparisons',
    'module'     => 'finance',
    'title_bn'   => 'Shadow Comparisons',
    'title_en'   => 'Shadow Comparisons',
    'icon'       => 'fa-table',
    'summary'    => 'সব শ্যাডো কম্পারিজন রানের তালিকা — কোনটিতে কত মিসম্যাচ ছিল।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-table',            'text' => 'সব শ্যাডো কম্পারিজন তালিকা দেখা ও ফিল্টার করা'],
        ['icon' => 'fa-magnifying-glass', 'text' => 'নির্দিষ্ট রানের ডিটেইলে ঢুকে পার্থক্য দেখা'],
    ],

    'impacts' => [
        ['who' => 'বিশ্লেষণ', 'what' => 'শুধু তালিকা ভিউ — কোনো GL বা স্টক বদলায় না'],
    ],

    'cautions' => [
        'সব মিসম্যাচ ক্লিয়ার না হলে কাটওভার করবেন না — আগে ডিটেইল পেজে কারণ খুঁজুন।',
    ],

    'related' => ['finance.shadow-mode', 'finance.shadow-mode-comparison-detail'],

    'updated_at' => '2026-08-07',
];
